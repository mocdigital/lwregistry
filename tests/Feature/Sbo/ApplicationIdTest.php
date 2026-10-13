<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('new sbo applications receive sequential mbn application ids', function (): void {
    $manager = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
    $year = now()->format('Y');

    $first = Sbo::create([
        'name' => 'MBN First ' . Str::random(6),
        'slug' => 'mbn-first-' . Str::random(6),
        'created_by' => $manager->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ]);

    $second = Sbo::create([
        'name' => 'MBN Second ' . Str::random(6),
        'slug' => 'mbn-second-' . Str::random(6),
        'created_by' => $manager->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ]);

    expect($first->application_id)->toBe('MBN-' . $year . '-0001');
    expect($second->application_id)->toBe('MBN-' . $year . '-0002');
});

test('migration backfills existing sbo application ids from sbo prefix to mbn prefix', function (): void {
    $manager = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    $oldWithoutCollision = Sbo::create([
        'name' => 'Legacy SBO One',
        'slug' => 'legacy-sbo-one-' . Str::random(6),
        'created_by' => $manager->id,
        'application_id' => 'SBO-2026-0001',
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ]);
    $oldWithCollision = Sbo::create([
        'name' => 'Legacy SBO Two',
        'slug' => 'legacy-sbo-two-' . Str::random(6),
        'created_by' => $manager->id,
        'application_id' => 'SBO-2026-0002',
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ]);
    Sbo::create([
        'name' => 'Existing MBN Two',
        'slug' => 'existing-mbn-two-' . Str::random(6),
        'created_by' => $manager->id,
        'application_id' => 'MBN-2026-0002',
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ]);

    DB::table('support_enquiries')->insert([
        'user_id' => $manager->id,
        'sbo_id' => $oldWithCollision->id,
        'first_name' => 'Ada',
        'last_name' => 'Okafor',
        'sbo_number' => 'SBO-2026-0002',
        'subject' => 'Legacy support reference',
        'message' => 'Please update this app id.',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_07_04_000000_rename_sbo_application_id_prefix_to_mbn.php');
    $migration->up();

    expect($oldWithoutCollision->fresh()->application_id)->toBe('MBN-2026-0001');
    expect($oldWithCollision->fresh()->application_id)->toBe('MBN-2026-0003');
    expect(DB::table('support_enquiries')->value('sbo_number'))->toBe('MBN-2026-0003');
});
