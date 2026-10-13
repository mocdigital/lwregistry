<?php

declare(strict_types=1);

use App\Support\SqliteDumpImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->dumpPath = tempnam(sys_get_temp_dir(), 'registry-dump-');

    Schema::create('test_dump_parents', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Schema::create('test_dump_children', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('parent_id')->constrained('test_dump_parents');
        $table->string('name');
    });

    Schema::create('test_dump_reordered', function (Blueprint $table): void {
        $table->id();
        $table->string('second');
        $table->string('first');
    });

    Schema::create('test_dump_missing_target_column', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Schema::create('test_dump_json_payloads', function (Blueprint $table): void {
        $table->id();
        $table->json('raw_payload');
    });
});

afterEach(function (): void {
    if (is_string($this->dumpPath) && is_file($this->dumpPath)) {
        unlink($this->dumpPath);
    }
});

it('replaces imported tables and skips framework tables from the dump', function (): void {
    DB::table('test_dump_parents')->insert([
        'id' => 99,
        'name' => 'Old parent',
    ]);

    DB::table('test_dump_children')->insert([
        'id' => 77,
        'parent_id' => 99,
        'name' => 'Old child',
    ]);

    DB::table('cache')->insert([
        'key' => 'existing-cache-key',
        'value' => 'keep-me',
        'expiration' => 0,
    ]);

    file_put_contents($this->dumpPath, <<<'SQL'
PRAGMA foreign_keys=OFF;
BEGIN TRANSACTION;
CREATE TABLE IF NOT EXISTS "test_dump_parents" ("id" integer primary key autoincrement not null, "name" varchar not null);
CREATE TABLE IF NOT EXISTS "test_dump_children" ("id" integer primary key autoincrement not null, "parent_id" integer not null, "name" varchar not null, foreign key("parent_id") references "test_dump_parents"("id"));
INSERT INTO migrations VALUES(999,'ignore_me',1);
INSERT INTO cache VALUES('dump-cache-key','skip-me',0);
INSERT INTO test_dump_parents VALUES(1,'Imported parent');
INSERT INTO test_dump_children VALUES(10,1,unistr('Imported child\u000aLine 2'));
COMMIT;
SQL);

    (new SqliteDumpImporter(DB::connection()))->import($this->dumpPath);

    expect(DB::table('test_dump_parents')->get()->map(fn ($row): array => (array) $row)->all())->toBe([
        ['id' => 1, 'name' => 'Imported parent'],
    ]);

    expect(DB::table('test_dump_children')->get()->map(fn ($row): array => (array) $row)->all())->toBe([
        ['id' => 10, 'parent_id' => 1, 'name' => "Imported child\nLine 2"],
    ]);

    expect(DB::table('cache')->pluck('value', 'key')->all())->toBe([
        'existing-cache-key' => 'keep-me',
    ]);
});

it('uses dump column names instead of target table column order', function (): void {
    file_put_contents($this->dumpPath, <<<'SQL'
CREATE TABLE IF NOT EXISTS "test_dump_reordered" ("id" integer primary key autoincrement not null, "first" varchar not null, "second" varchar not null);
INSERT INTO test_dump_reordered VALUES(5,'value for first','value for second');
SQL);

    (new SqliteDumpImporter(DB::connection()))->import($this->dumpPath);

    expect((array) DB::table('test_dump_reordered')->first())->toBe([
        'id' => 5,
        'second' => 'value for second',
        'first' => 'value for first',
    ]);
});

it('maps purchase order values by SQLite source columns when target column order differs', function (): void {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('purchase_orders');
    Schema::enableForeignKeyConstraints();

    Schema::create('purchase_orders', function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('sbo_id');
        $table->unsignedBigInteger('category_id');
        $table->string('request_type');
        $table->string('title');
        $table->text('description');
        $table->string('document_path')->nullable();
        $table->decimal('budget', 12, 2)->nullable();
        $table->string('quantity')->nullable();
    });

    DB::table('purchase_orders')->insert([
        'id' => 999,
        'sbo_id' => 1,
        'category_id' => 1,
        'request_type' => 'old',
        'title' => 'Old title',
        'description' => 'Old description',
    ]);

    file_put_contents($this->dumpPath, <<<'SQL'
CREATE TABLE IF NOT EXISTS "purchase_orders" (
    "id" integer primary key autoincrement not null,
    "sbo_id" integer not null,
    "category_id" integer not null,
    "title" varchar not null,
    "description" text not null,
    "document_path" varchar,
    "budget" numeric,
    "quantity" varchar,
    "request_type" varchar not null,
    PRIMARY KEY ("id"),
    UNIQUE ("title"),
    CHECK ("budget" >= 0),
    CONSTRAINT "purchase_orders_sbo_id_foreign" FOREIGN KEY ("sbo_id") REFERENCES "sbos" ("id")
);
INSERT INTO purchase_orders VALUES(101,21,1,'Demo RFP 001','Demo procurement request',NULL,6350,'23 units','service');
SQL);

    (new SqliteDumpImporter(DB::connection()))->import($this->dumpPath);

    $rows = DB::table('purchase_orders')->get()->map(fn ($row): array => (array) $row)->all();

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toMatchArray([
        'id' => 101,
        'sbo_id' => 21,
        'category_id' => 1,
        'request_type' => 'service',
        'title' => 'Demo RFP 001',
        'description' => 'Demo procurement request',
        'document_path' => null,
        'quantity' => '23 units',
    ]);
});

it('omits generated target columns from positional snapshot inserts', function (): void {
    $driver = DB::connection()->getDriverName();

    if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
        $this->markTestSkipped("Generated column import is not supported on the [{$driver}] test driver.");
    }

    Schema::dropIfExists('test_dump_generated_users');

    if ($driver === 'sqlite') {
        DB::statement(<<<'SQL'
CREATE TABLE "test_dump_generated_users" (
    "id" integer primary key not null,
    "firstname" varchar not null,
    "lastname" varchar not null,
    "name" varchar generated always as ("lastname" || ' ' || "firstname") stored,
    "email" varchar not null
)
SQL);
    } else {
        DB::statement(<<<'SQL'
CREATE TABLE `test_dump_generated_users` (
    `id` bigint unsigned not null primary key,
    `firstname` varchar(255) not null,
    `lastname` varchar(255) not null,
    `name` varchar(255) generated always as (concat(`lastname`, ' ', `firstname`)) stored,
    `email` varchar(255) not null
)
SQL);
    }

    DB::table('test_dump_generated_users')->insert([
        'id' => 1,
        'firstname' => 'Old',
        'lastname' => 'User',
        'email' => 'old@example.test',
    ]);

    file_put_contents($this->dumpPath, <<<'SQL'
CREATE TABLE IF NOT EXISTS "test_dump_generated_users" (
    "id" integer primary key autoincrement not null,
    "firstname" varchar not null,
    "lastname" varchar not null,
    "name" varchar not null,
    "email" varchar not null
);
INSERT INTO test_dump_generated_users VALUES(2,'Demo','Super Admin','Wrong imported name','demo.superadmin@example.test');
SQL);

    (new SqliteDumpImporter(DB::connection()))->import($this->dumpPath);

    expect((array) DB::table('test_dump_generated_users')->first())->toMatchArray([
        'id' => 2,
        'firstname' => 'Demo',
        'lastname' => 'Super Admin',
        'name' => 'Super Admin Demo',
        'email' => 'demo.superadmin@example.test',
    ]);
});

it('preserves SQLite backslash literals when importing JSON payloads into mysql', function (): void {
    file_put_contents($this->dumpPath, <<<'SQL'
CREATE TABLE IF NOT EXISTS "test_dump_json_payloads" ("id" integer primary key autoincrement not null, "raw_payload" text not null);
INSERT INTO test_dump_json_payloads VALUES(1,'{"seeded_by":"Database\\Seeders\\LocalDemoSeeder"}');
SQL);

    (new SqliteDumpImporter(DB::connection()))->import($this->dumpPath);

    $payload = (string) DB::table('test_dump_json_payloads')->value('raw_payload');

    expect(json_decode($payload, true))->toBe([
        'seeded_by' => 'Database\\Seeders\\LocalDemoSeeder',
    ]);
});

it('fails preflight before deleting rows when source columns are missing in the target table', function (): void {
    DB::table('test_dump_missing_target_column')->insert([
        'id' => 1,
        'name' => 'Keep me',
    ]);

    file_put_contents($this->dumpPath, <<<'SQL'
CREATE TABLE IF NOT EXISTS "test_dump_missing_target_column" ("id" integer primary key autoincrement not null, "name" varchar not null, "legacy_only" varchar not null);
INSERT INTO test_dump_missing_target_column VALUES(2,'Imported','not present in target');
SQL);

    expect(fn (): null => (new SqliteDumpImporter(DB::connection()))->import($this->dumpPath))
        ->toThrow(RuntimeException::class, 'test_dump_missing_target_column.legacy_only');

    expect(DB::table('test_dump_missing_target_column')->pluck('name', 'id')->all())->toBe([
        1 => 'Keep me',
    ]);
});
