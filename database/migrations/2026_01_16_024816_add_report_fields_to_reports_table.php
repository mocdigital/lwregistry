<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            // Guard each column so the migration is safe to re-run after a
            // partial failure (a prior run added some columns before erroring).
            if ( ! Schema::hasColumn('reports', 'description')) {
                $table->text('description')->nullable();
            }

            // Nullable: SQLite (and any populated table) rejects adding a
            // NOT NULL column without a default. Requiredness is enforced at
            // the application/validation layer.
            if ( ! Schema::hasColumn('reports', 'period_start')) {
                $table->date('period_start')->nullable();
            }

            if ( ! Schema::hasColumn('reports', 'period_end')) {
                $table->date('period_end')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['description', 'period_start', 'period_end'],
                fn (string $column): bool => Schema::hasColumn('reports', $column),
            ));

            if ([] !== $columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
