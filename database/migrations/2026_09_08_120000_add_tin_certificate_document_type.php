<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * TIN is now captured two ways: the NUMBER is typed into a field on the
 * application (stored on sbos.tax_number), and the CERTIFICATE is uploaded as a
 * compliance document. Any existing global "TIN" document type becomes the
 * certificate upload; where none exists, one is created. Global (country_id
 * null) so it shows on every application. Optional, so it never hard-blocks —
 * the same treatment TIN documents were already given.
 */
return new class() extends Migration
{
    public function up(): void
    {
        $now = now();

        // Rename an existing global TIN document type to "TIN Certificate".
        $renamed = DB::table('compliance_document_types')
            ->whereNull('country_id')
            ->where(function ($q): void {
                $q->whereRaw('LOWER(name) LIKE ?', ['%tax identification%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%tin%']);
            })
            ->update([
                'name' => 'TIN Certificate',
                'is_required' => false,
                'updated_at' => $now,
            ]);

        // None to rename → create the certificate upload type.
        $exists = DB::table('compliance_document_types')
            ->whereNull('country_id')
            ->where('name', 'TIN Certificate')
            ->exists();

        if (0 === $renamed && ! $exists) {
            DB::table('compliance_document_types')->insert([
                'name' => 'TIN Certificate',
                'description' => 'Upload your Tax Identification Number (TIN) certificate.',
                'is_required' => false,
                'is_active' => true,
                'sort_order' => 100,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Left in place: removing it could orphan uploaded certificates.
    }
};
