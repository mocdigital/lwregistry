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
        Schema::table('compliance_document_types', function (Blueprint $table): void {
            $table->foreignId('country_id')
                ->nullable()
                ->after('description')
                ->constrained('countries')
                ->nullOnDelete();
            $table->string('registry_authority')->nullable()->after('country_id');
            $table->string('source_url')->nullable()->after('registry_authority');
            $table->text('source_note')->nullable()->after('source_url');
            $table->boolean('is_seeded')->default(false)->after('sort_order');
            $table->boolean('needs_admin_review')->default(false)->after('is_seeded');
        });

        $this->seedCountryBusinessDocuments();
    }

    public function down(): void
    {
        if (Schema::hasColumn('compliance_document_types', 'country_id')) {
            DB::table('compliance_document_types')
                ->where('is_seeded', true)
                ->delete();
        }

        Schema::table('compliance_document_types', function (Blueprint $table): void {
            $table->dropColumn([
                'registry_authority',
                'source_url',
                'source_note',
                'is_seeded',
                'needs_admin_review',
            ]);
            $table->dropConstrainedForeignId('country_id');
        });
    }

    private function seedCountryBusinessDocuments(): void
    {
        $catalog = require database_path('data/country_business_registration_documents.php');
        $default = $catalog['default'];
        $countries = DB::table('countries')->orderBy('name')->get(['id', 'name']);

        foreach ($countries as $country) {
            $entry = array_merge($default, $catalog['countries'][$country->name] ?? []);
            $documentName = str_replace('{country}', $country->name, $entry['document_name']);
            $description = str_replace('{country}', $country->name, $entry['description']);

            $existing = DB::table('compliance_document_types')
                ->where('country_id', $country->id)
                ->where('name', $documentName)
                ->first();

            if (null === $existing) {
                DB::table('compliance_document_types')->insert([
                    'name' => $documentName,
                    'description' => $description,
                    'country_id' => $country->id,
                    'registry_authority' => $entry['registry_authority'] ?? null,
                    'source_url' => $entry['source_url'] ?? null,
                    'source_note' => $entry['source_note'] ?? null,
                    'is_required' => true,
                    'is_active' => true,
                    'sort_order' => (int) DB::table('compliance_document_types')->max('sort_order') + 1,
                    'is_seeded' => true,
                    'needs_admin_review' => (bool) ($entry['needs_admin_review'] ?? true),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            if ((bool) ($existing->is_seeded ?? false)) {
                DB::table('compliance_document_types')
                    ->where('id', $existing->id)
                    ->update([
                        'registry_authority' => $entry['registry_authority'] ?? null,
                        'source_url' => $entry['source_url'] ?? null,
                        'source_note' => $entry['source_note'] ?? null,
                        'needs_admin_review' => (bool) ($entry['needs_admin_review'] ?? true),
                        'updated_at' => now(),
                    ]);
            }
        }
    }
};
