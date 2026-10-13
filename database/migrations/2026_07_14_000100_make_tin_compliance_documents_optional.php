<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    /**
     * TIN documents remain available as backend records, but they do not block
     * country compliance unless an administrator explicitly marks one required.
     */
    public function up(): void
    {
        DB::table('compliance_document_types')
            ->where(function ($query): void {
                $query
                    ->whereRaw('LOWER(name) LIKE ?', ['%tin%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%tax identification%']);
            })
            ->update([
                'is_required' => false,
                'updated_at' => now(),
            ]);
    }

    /**
     * The previous required/optional state is data-dependent, so it is not
     * safely reversible without overwriting administrator configuration.
     */
    public function down(): void {}
};
