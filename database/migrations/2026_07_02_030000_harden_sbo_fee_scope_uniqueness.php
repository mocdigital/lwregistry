<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if ( ! Schema::hasColumn('sbo_capital_types', 'scope_key')) {
            Schema::table('sbo_capital_types', function (Blueprint $table): void {
                $table->string('scope_key')->default('global')->after('country_id');
            });
        }

        if ( ! Schema::hasColumn('sbo_fee_settings', 'scope_key')) {
            Schema::table('sbo_fee_settings', function (Blueprint $table): void {
                $table->string('scope_key')->default('global')->after('country_id');
            });
        }

        $this->backfillScopeKeys('sbo_capital_types');
        $this->backfillScopeKeys('sbo_fee_settings');

        $this->deduplicate('sbo_capital_types', ['scope_key', 'structure', 'key']);
        $this->deduplicate('sbo_fee_settings', ['scope_key', 'key']);

        $this->ensureForeignKeyIndex('sbo_capital_types', 'sbo_capital_types_country_id_lookup');
        $this->ensureForeignKeyIndex('sbo_fee_settings', 'sbo_fee_settings_country_id_lookup');

        $this->replaceUniqueIndex(
            'sbo_capital_types',
            ['country_id', 'structure', 'key'],
            ['scope_key', 'structure', 'key'],
            'sbo_capital_types_scope_structure_key_unique',
        );

        $this->replaceUniqueIndex(
            'sbo_fee_settings',
            ['country_id', 'key'],
            ['scope_key', 'key'],
            'sbo_fee_settings_scope_key_unique',
        );
    }

    public function down(): void
    {
        $this->restoreUniqueIndex(
            'sbo_fee_settings',
            'sbo_fee_settings_scope_key_unique',
            ['country_id', 'key'],
        );

        $this->restoreUniqueIndex(
            'sbo_capital_types',
            'sbo_capital_types_scope_structure_key_unique',
            ['country_id', 'structure', 'key'],
        );

        $this->dropForeignKeyIndex('sbo_fee_settings', 'sbo_fee_settings_country_id_lookup');
        $this->dropForeignKeyIndex('sbo_capital_types', 'sbo_capital_types_country_id_lookup');

        if (Schema::hasColumn('sbo_fee_settings', 'scope_key')) {
            Schema::table('sbo_fee_settings', function (Blueprint $table): void {
                $table->dropColumn('scope_key');
            });
        }

        if (Schema::hasColumn('sbo_capital_types', 'scope_key')) {
            Schema::table('sbo_capital_types', function (Blueprint $table): void {
                $table->dropColumn('scope_key');
            });
        }
    }

    private function backfillScopeKeys(string $table): void
    {
        DB::table($table)
            ->whereNull('country_id')
            ->update(['scope_key' => 'global']);

        DB::table($table)
            ->whereNotNull('country_id')
            ->orderBy('id')
            ->get(['id', 'country_id'])
            ->each(function (object $row) use ($table): void {
                DB::table($table)
                    ->where('id', $row->id)
                    ->update(['scope_key' => 'country:' . $row->country_id]);
            });
    }

    private function ensureForeignKeyIndex(string $tableName, string $indexName): void
    {
        if (Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->index('country_id', $indexName);
        });
    }

    /**
     * @param  list<string>  $oldColumns
     * @param  list<string>  $newColumns
     */
    private function replaceUniqueIndex(string $tableName, array $oldColumns, array $newColumns, string $newIndexName): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($oldColumns, $tableName): void {
            if (Schema::hasIndex($tableName, $oldColumns, 'unique')) {
                $table->dropUnique($oldColumns);
            }
        });

        if (Schema::hasIndex($tableName, $newIndexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($newColumns, $newIndexName): void {
            $table->unique($newColumns, $newIndexName);
        });
    }

    /**
     * @param  list<string>  $oldColumns
     */
    private function restoreUniqueIndex(string $tableName, string $newIndexName, array $oldColumns): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($newIndexName, $tableName): void {
            if (Schema::hasIndex($tableName, $newIndexName)) {
                $table->dropUnique($newIndexName);
            }
        });

        if (Schema::hasIndex($tableName, $oldColumns, 'unique')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($oldColumns): void {
            $table->unique($oldColumns);
        });
    }

    private function dropForeignKeyIndex(string $tableName, string $indexName): void
    {
        if ( ! Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
        });
    }

    /**
     * Keep the newest row for each logical key before enforcing uniqueness.
     *
     * @param  list<string>  $columns
     */
    private function deduplicate(string $table, array $columns): void
    {
        DB::table($table)
            ->orderByDesc('id')
            ->get(array_merge(['id'], $columns))
            ->groupBy(fn (object $row): string => collect($columns)
                ->map(fn (string $column): string => (string) $row->{$column})
                ->implode('|'))
            ->each(function ($rows) use ($table): void {
                $idsToDelete = $rows->pluck('id')->skip(1);

                if ($idsToDelete->isNotEmpty()) {
                    DB::table($table)->whereIn('id', $idsToDelete)->delete();
                }
            });
    }
};
