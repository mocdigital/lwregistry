<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\PurchaseOrder;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class LocalDemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const COUNTRY_NAMES = [
        'Nigeria',
        'Ghana',
        'South Africa',
        'United Kingdom',
        'United States',
        'Canada',
        'Kenya',
    ];

    private const ZONE_NAMES = [
        'Lagos Zone 1',
        'Lagos Zone 2',
        'Lagos Zone 3',
        'Lagos Zone 4',
        'Lagos Zone 5',
        'Lagos Zone 6',
        'Abuja Zone 1',
        'Abuja Zone 2',
        'Accra Ghana Zone',
        'Australia Region',
    ];

    private ?string $passwordHash = null;

    public function run(): void
    {
        if ( ! app()->environment('local')) {
            $this->command?->warn('LocalDemoSeeder only runs in the local environment.');

            return;
        }

        $categories = DB::table('categories')->orderBy('id')->get(['id', 'name']);

        if ($categories->isEmpty()) {
            throw new RuntimeException('Seed categories before running LocalDemoSeeder.');
        }

        $countries = $this->preferredCountries();
        $zones = $this->preferredZones();
        $now = now();

        DB::transaction(function () use ($categories, $countries, $zones, $now): void {
            $adminId = $this->adminUserId($now);
            $sboIds = [];

            for ($i = 1; $i <= 100; $i++) {
                $category = $categories[($i - 1) % $categories->count()];
                $country = $countries[($i - 1) % count($countries)];
                $zone = $zones[($i - 1) % count($zones)];
                $state = $this->sboWorkflowState($i);
                $createdAt = $now->copy()->subDays(130 - $i);

                $userId = $this->upsertUser($i, $createdAt, $state);
                $sboId = $this->upsertSbo($i, $userId, $adminId, $category, $country, $zone, $state, $createdAt);

                $this->resetSboChildren($sboId);
                $this->seedComplianceDocument($sboId, (int) $country->id, $state, $adminId, $createdAt);
                $this->seedPaymentTransaction($sboId, $i, $state, $adminId, $createdAt);
                $this->seedProfileSections($sboId, $i, (string) $category->name, $now);

                $sboIds[] = $sboId;
            }

            $allSboIds = DB::table('sbos')
                ->whereNotNull('created_by')
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $this->seedRfqs($allSboIds ?: $sboIds, $categories, $now);
            $this->refreshCategoryCounts($categories, $now);
        });

        $this->command?->info('Seeded 100 demo SBOs and one open demo RFP/RFQ with bids for every SBO.');
        $this->command?->info('Demo SBO users use demo.sbo001@example.test through demo.sbo100@example.test with password "' . self::PASSWORD . '".');
    }

    /**
     * @return array<int, object>
     */
    private function preferredCountries(): array
    {
        $byName = DB::table('countries')
            ->whereIn('name', self::COUNTRY_NAMES)
            ->get(['id', 'name'])
            ->keyBy('name');

        $countries = [];

        foreach (self::COUNTRY_NAMES as $name) {
            if ($byName->has($name)) {
                $countries[] = $byName->get($name);
            }
        }

        if ([] !== $countries) {
            return $countries;
        }

        return DB::table('countries')->orderBy('id')->limit(7)->get(['id', 'name'])->all();
    }

    /**
     * @return array<int, object>
     */
    private function preferredZones(): array
    {
        $byName = DB::table('zones')
            ->whereIn('name', self::ZONE_NAMES)
            ->get(['id', 'name', 'code'])
            ->keyBy('name');

        $zones = [];

        foreach (self::ZONE_NAMES as $name) {
            if ($byName->has($name)) {
                $zones[] = $byName->get($name);
            }
        }

        if ([] !== $zones) {
            return $zones;
        }

        return DB::table('zones')->orderBy('id')->limit(10)->get(['id', 'name', 'code'])->all();
    }

    /**
     * @return array<string, string|null|bool>
     */
    private function sboWorkflowState(int $index): array
    {
        return match (true) {
            $index <= 25 => [
                'label' => 'Fully verified',
                'verification_status' => 'approved',
                'documents_status' => 'compliant',
                'payment_status' => 'paid',
                'document_status' => 'approved',
                'payment_review_status' => 'approved',
                'gateway_status' => 'submitted',
                'verified_notified' => true,
                'decline_reason' => null,
                'info_request' => null,
                'active_status' => 'active',
                'is_active' => true,
            ],
            $index <= 35 => [
                'label' => 'Ready for final verification',
                'verification_status' => 'approved',
                'documents_status' => 'compliant',
                'payment_status' => 'paid',
                'document_status' => 'approved',
                'payment_review_status' => 'approved',
                'gateway_status' => 'submitted',
                'verified_notified' => false,
                'decline_reason' => null,
                'info_request' => null,
                'active_status' => 'active',
                'is_active' => true,
            ],
            $index <= 50 => [
                'label' => 'Paid, documents pending',
                'verification_status' => 'approved',
                'documents_status' => 'submitted',
                'payment_status' => 'paid',
                'document_status' => 'pending',
                'payment_review_status' => 'approved',
                'gateway_status' => 'submitted',
                'verified_notified' => false,
                'decline_reason' => null,
                'info_request' => null,
                'active_status' => 'active',
                'is_active' => true,
            ],
            $index <= 65 => [
                'label' => 'Documents approved, unpaid',
                'verification_status' => 'approved',
                'documents_status' => 'compliant',
                'payment_status' => 'unpaid',
                'document_status' => 'approved',
                'payment_review_status' => null,
                'gateway_status' => null,
                'verified_notified' => false,
                'decline_reason' => null,
                'info_request' => null,
                'active_status' => 'active',
                'is_active' => true,
            ],
            $index <= 78 => [
                'label' => 'No documents submitted, unpaid',
                'verification_status' => 'approved',
                'documents_status' => 'pending',
                'payment_status' => 'unpaid',
                'document_status' => null,
                'payment_review_status' => null,
                'gateway_status' => null,
                'verified_notified' => false,
                'decline_reason' => null,
                'info_request' => null,
                'active_status' => 'active',
                'is_active' => true,
            ],
            $index <= 88 => [
                'label' => 'Payment submitted, awaiting review',
                'verification_status' => 'approved',
                'documents_status' => 'pending',
                'payment_status' => 'unpaid',
                'document_status' => null,
                'payment_review_status' => 'pending',
                'gateway_status' => 'submitted',
                'verified_notified' => false,
                'decline_reason' => null,
                'info_request' => null,
                'active_status' => 'active',
                'is_active' => true,
            ],
            $index <= 96 => [
                'label' => 'Documents rejected, information requested',
                'verification_status' => 'info_requested',
                'documents_status' => 'submitted',
                'payment_status' => 'unpaid',
                'document_status' => 'rejected',
                'payment_review_status' => null,
                'gateway_status' => null,
                'verified_notified' => false,
                'decline_reason' => null,
                'info_request' => 'Please upload a clearer registration certificate and confirm the business address.',
                'active_status' => 'active',
                'is_active' => true,
            ],
            default => [
                'label' => 'Declined',
                'verification_status' => 'declined',
                'documents_status' => 'pending',
                'payment_status' => 'unpaid',
                'document_status' => null,
                'payment_review_status' => null,
                'gateway_status' => null,
                'verified_notified' => false,
                'decline_reason' => 'Demo declined application: registration details could not be validated.',
                'info_request' => null,
                'active_status' => 'declined',
                'is_active' => false,
            ],
        };
    }

    /**
     * @param  array<string, string|null|bool>  $state
     */
    private function upsertUser(int $index, CarbonInterface $createdAt, array $state): int
    {
        $firstNames = ['Ada', 'David', 'Grace', 'Samuel', 'Miriam', 'Joshua', 'Sarah', 'Daniel', 'Tomi', 'Esther'];
        $lastNames = ['Okafor', 'Mensah', 'Williams', 'Adebayo', 'Ndlovu', 'Johnson', 'Ochieng', 'Smith', 'Brown', 'Adeyemi'];
        $firstName = $firstNames[($index - 1) % count($firstNames)];
        $lastName = $lastNames[(int) floor(($index - 1) / count($firstNames)) % count($lastNames)];
        $email = sprintf('demo.sbo%03d@example.test', $index);
        $existing = DB::table('users')->where('email', $email)->first();
        $values = [
            'firstname' => $firstName,
            'lastname' => $lastName,
            'name' => $lastName . ' ' . $firstName,
            'phone' => sprintf('+234800%07d', $index),
            'email_verified_at' => $createdAt->copy()->addHour()->toDateTimeString(),
            'password' => $this->passwordHash(),
            'role' => UserRole::SBO_MANAGER->value,
            'permissions' => null,
            'admin_page_permissions' => null,
            'suspended_at' => 'declined' === $state['verification_status'] ? $createdAt->copy()->addDays(7)->toDateTimeString() : null,
            'suspended_by' => null,
            'suspension_reason' => 'declined' === $state['verification_status'] ? 'Demo declined application account.' : null,
            'profile_photo_path' => null,
            'kc_handle' => '@demo_sbo_' . mb_str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'updated_at' => now()->toDateTimeString(),
        ];

        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update($values);

            return (int) $existing->id;
        }

        $values['uuid'] = (string) Str::uuid();
        $values['email'] = $email;
        $values['remember_token'] = Str::random(10);
        $values['created_at'] = $createdAt->toDateTimeString();

        return (int) DB::table('users')->insertGetId($values);
    }

    /**
     * @param  array<string, string|null|bool>  $state
     */
    private function upsertSbo(
        int $index,
        int $userId,
        int $adminId,
        object $category,
        object $country,
        object $zone,
        array $state,
        CarbonInterface $createdAt,
    ): int {
        $industries = [
            'Radiant Logistics Cooperative',
            'Kingdom Media Works',
            'Gracefield Medical Supply',
            'New City Construction Group',
            'Impact Catering Services',
            'Shiloh Learning Systems',
            'FaithTech Cloud Services',
            'Royal Events Collective',
            'Dominion Print Studio',
            'Heritage Finance Partners',
            'Harvest Agro Traders',
            'Zion Facility Care',
            'Bluegate Legal Advisory',
            'Summit Interiors',
            'Lightway Transport Network',
            'Covenant Book Distribution',
            'Prime Hospitality Group',
            'Alpha Engineering Works',
            'Evergreen Fashion House',
            'Beacon Security Services',
        ];
        $cities = [
            'Nigeria' => ['Lagos', 'Abuja', 'Port Harcourt', 'Ibadan'],
            'Ghana' => ['Accra', 'Kumasi', 'Tema', 'Takoradi'],
            'South Africa' => ['Johannesburg', 'Cape Town', 'Durban', 'Pretoria'],
            'United Kingdom' => ['London', 'Manchester', 'Birmingham', 'Leeds'],
            'United States' => ['Houston', 'Atlanta', 'Dallas', 'New York'],
            'Canada' => ['Toronto', 'Calgary', 'Vancouver', 'Ottawa'],
            'Kenya' => ['Nairobi', 'Mombasa', 'Kisumu', 'Nakuru'],
        ];
        $states = [
            'Nigeria' => ['Lagos', 'FCT', 'Rivers', 'Oyo'],
            'Ghana' => ['Greater Accra', 'Ashanti', 'Greater Accra', 'Western'],
            'South Africa' => ['Gauteng', 'Western Cape', 'KwaZulu-Natal', 'Gauteng'],
            'United Kingdom' => ['England', 'England', 'England', 'England'],
            'United States' => ['Texas', 'Georgia', 'Texas', 'New York'],
            'Canada' => ['Ontario', 'Alberta', 'British Columbia', 'Ontario'],
            'Kenya' => ['Nairobi County', 'Mombasa County', 'Kisumu County', 'Nakuru County'],
        ];

        $countryName = (string) $country->name;
        $locationIndex = ($index - 1) % 4;
        $businessName = sprintf('Demo SBO %03d - %s', $index, $industries[($index - 1) % count($industries)]);
        $slug = sprintf('demo-sbo-%03d-%s', $index, Str::slug($industries[($index - 1) % count($industries)]));
        $approvedAt = in_array($state['verification_status'], ['approved', 'info_requested'], true)
            ? $createdAt->copy()->addDays(3)
            : null;
        $paidAt = 'paid' === $state['payment_status'] ? $createdAt->copy()->addDays(5) : null;
        $documentsAt = 'compliant' === $state['documents_status'] ? $createdAt->copy()->addDays(6) : null;
        $paymentSubmittedAt = 'paid' === $state['payment_status'] || 'pending' === $state['payment_review_status']
            ? $createdAt->copy()->addDays(4)
            : null;

        $values = [
            'application_id' => sprintf('DEMO-MBN-2026-%04d', $index),
            'name' => $businessName,
            'rc_number' => sprintf('DEMO-RC-%06d', 900000 + $index),
            'tax_number' => 0 === $index % 5 ? null : sprintf('DEMO-TIN-%06d', 700000 + $index),
            'business_type' => ['Manufacturer', 'Service Provider', 'Distributor', 'Retailer', 'Consultant'][($index - 1) % 5],
            'legal_structure' => ['Private Company', 'Public Company', 'Partnership', 'Sole Proprietorship'][($index - 1) % 4],
            'category' => $category->name,
            'region' => $countryName,
            'address' => sprintf('%d Demo Commerce Avenue', 10 + $index),
            'city' => $cities[$countryName][$locationIndex] ?? 'Demo City',
            'state' => $states[$countryName][$locationIndex] ?? 'Demo State',
            'country' => $countryName,
            'country_id' => (int) $country->id,
            'premise_type' => ['physical', 'virtual', 'both'][($index - 1) % 3],
            'established_at' => $createdAt->copy()->subYears(2 + ($index % 14))->toDateString(),
            'website' => sprintf('https://demo-sbo-%03d.example.test', $index),
            'social_links' => json_encode([
                'kingschat' => '@demo_sbo_' . mb_str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'website' => sprintf('https://demo-sbo-%03d.example.test', $index),
            ]),
            'staff_count' => 5 + (($index * 7) % 240),
            'business_model' => ['B2B', 'B2C', 'Hybrid', 'Marketplace'][($index - 1) % 4],
            'primary_contact_name' => sprintf('Demo Contact %03d', $index),
            'primary_contact_email' => sprintf('contact.demo.sbo%03d@example.test', $index),
            'owner_surname' => 'Owner' . mb_str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'owner_first_name' => 'Demo',
            'owner_country' => $countryName,
            'owner_residential_address' => sprintf('%d Demo Residence Street', 20 + $index),
            'church_zone' => $zone->name,
            'zone_id' => (int) $zone->id,
            'referred_via_code' => $zone->code ?? null,
            'local_assembly' => 'Demo Assembly ' . (($index % 12) + 1),
            'years_as_member' => 1 + ($index % 20),
            'zonal_pastor' => 'Pastor Demo Zone',
            'local_pastor' => 'Pastor Demo Local',
            'phone' => sprintf('+234801%07d', $index),
            'kingschat_handle' => '@demo_sbo_' . mb_str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'directors' => json_encode([
                ['name' => sprintf('Demo Director %03d', $index), 'role' => 'Managing Director'],
                ['name' => sprintf('Demo Finance Lead %03d', $index), 'role' => 'Finance Lead'],
            ]),
            'description' => sprintf('%s demo record. Seeded for local validation of %s workflow states.', $businessName, $state['label']),
            'is_active' => $state['is_active'] ? 1 : 0,
            'created_by' => $userId,
            'status' => $state['active_status'],
            'received_by' => $adminId,
            'date_received' => $createdAt->copy()->addDay()->toDateTimeString(),
            'verification_status' => $state['verification_status'],
            'approved_by' => $approvedAt ? $adminId : null,
            'date_approved' => $approvedAt?->toDateTimeString(),
            'moc_registration_number' => 'approved' === $state['verification_status'] ? sprintf('DEMO-MOC-2026-%04d', $index) : null,
            'decline_reason' => $state['decline_reason'],
            'info_request' => $state['info_request'],
            'resubmitted_at' => 'info_requested' === $state['verification_status'] ? null : $createdAt->copy()->addDays(2)->toDateTimeString(),
            'code_of_conduct_agreed_at' => $createdAt->toDateTimeString(),
            'documents_status' => $state['documents_status'],
            'payment_status' => $state['payment_status'],
            'documents_compliant_at' => $documentsAt?->toDateTimeString(),
            'payment_confirmed_at' => $paidAt?->toDateTimeString(),
            'verified_notified_at' => $state['verified_notified'] ? $createdAt->copy()->addDays(7)->toDateTimeString() : null,
            'share_structure' => 0 === $index % 4 ? 'public' : 'private',
            'share_capital_type' => 0 === $index % 3 ? 'preference' : 'ordinary',
            'issued_shares_volume' => 10000 + ($index * 2500),
            'per_share_value' => 10 + ($index % 10),
            'calculated_fee' => 25,
            'fee_band' => 'Demo flat fee',
            'payment_reference' => $paymentSubmittedAt ? sprintf('DEMO-PAY-2026-%04d', $index) : null,
            'payment_submitted_at' => $paymentSubmittedAt?->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        $existing = DB::table('sbos')->where('slug', $slug)->first();

        if ($existing) {
            DB::table('sbos')->where('id', $existing->id)->update($values);

            return (int) $existing->id;
        }

        $values['slug'] = $slug;
        $values['created_at'] = $createdAt->toDateTimeString();

        return (int) DB::table('sbos')->insertGetId($values);
    }

    private function resetSboChildren(int $sboId): void
    {
        DB::table('sbo_compliance_documents')->where('sbo_id', $sboId)->delete();
        DB::table('sbo_payment_proofs')->where('sbo_id', $sboId)->delete();
        DB::table('sbo_payment_transactions')->where('sbo_id', $sboId)->delete();
        DB::table('sbo_services')->where('sbo_id', $sboId)->delete();
        DB::table('sbo_opening_hours')->where('sbo_id', $sboId)->delete();
    }

    /**
     * Services and opening hours for a demo listing.
     *
     * These sections were a fixed array in BusinessProfileView.vue, so every
     * demo record advertised the same three services and the same schedule.
     * Seeding them per SBO is what makes the demo data actually demonstrate
     * the thing: the count varies, the wording follows the category, and one
     * in nine listings sets no hours at all so the "not specified" path gets
     * exercised rather than only ever being theoretical.
     */
    private function seedProfileSections(int $sboId, int $index, string $category, CarbonInterface $now): void
    {
        // Two to four services, drawn from the listing's own category.
        $catalogue = [
            $category . ' consulting',
            $category . ' project delivery',
            'Maintenance and support',
            'Training and onboarding',
            'Procurement and sourcing',
            'Compliance advisory',
        ];

        $count = 2 + ($index % 3);
        $offset = $index % count($catalogue);
        $rows = [];

        for ($n = 0; $n < $count; $n++) {
            $title = $catalogue[($offset + $n) % count($catalogue)];

            $rows[] = [
                'sbo_id' => $sboId,
                'title' => Str::ucfirst($title),
                'body' => sprintf(
                    'Delivered by our %s team for clients across the region.',
                    mb_strtolower($category),
                ),
                'position' => $n,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('sbo_services')->insert($rows);

        // Every ninth listing leaves its hours unset.
        if (0 === $index % 9) {
            return;
        }

        $opens = sprintf('%02d:00:00', 7 + ($index % 3));   // 07:00 - 09:00
        $closes = sprintf('%02d:00:00', 16 + ($index % 4)); // 16:00 - 19:00
        $hours = [];

        // Monday to Friday trading, Saturday for two listings in three,
        // Sunday closed throughout.
        foreach ([1, 2, 3, 4, 5] as $day) {
            $hours[] = [
                'sbo_id' => $sboId,
                'day_of_week' => $day,
                'opens_at' => $opens,
                'closes_at' => $closes,
                'is_closed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (0 !== $index % 3) {
            $hours[] = [
                'sbo_id' => $sboId,
                'day_of_week' => 6,
                'opens_at' => '09:00:00',
                'closes_at' => '14:00:00',
                'is_closed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $hours[] = [
            'sbo_id' => $sboId,
            'day_of_week' => 0,
            'opens_at' => null,
            'closes_at' => null,
            'is_closed' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('sbo_opening_hours')->insert($hours);
    }

    /**
     * @param  array<string, string|null|bool>  $state
     */
    private function seedComplianceDocument(int $sboId, int $countryId, array $state, int $adminId, CarbonInterface $createdAt): void
    {
        if (null === $state['document_status']) {
            return;
        }

        $typeId = DB::table('compliance_document_types')
            ->where('country_id', $countryId)
            ->where('is_active', true)
            ->where('is_required', true)
            ->orderBy('sort_order')
            ->value('id');

        $typeId ??= DB::table('compliance_document_types')
            ->whereNull('country_id')
            ->where('is_active', true)
            ->where('is_required', true)
            ->orderBy('sort_order')
            ->value('id');

        if ( ! $typeId) {
            return;
        }

        $reviewed = in_array($state['document_status'], ['approved', 'rejected'], true);

        DB::table('sbo_compliance_documents')->insert([
            'sbo_id' => $sboId,
            'compliance_document_type_id' => (int) $typeId,
            'status' => $state['document_status'],
            'rejection_reason' => 'rejected' === $state['document_status']
                ? 'Demo rejection: uploaded document is unclear or does not match the registered business name.'
                : null,
            'reviewed_by' => $reviewed ? $adminId : null,
            'reviewed_at' => $reviewed ? $createdAt->copy()->addDays(6)->toDateTimeString() : null,
            'created_at' => $createdAt->copy()->addDays(4)->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * @param  array<string, string|null|bool>  $state
     */
    private function seedPaymentTransaction(int $sboId, int $index, array $state, int $adminId, CarbonInterface $createdAt): void
    {
        if (null === $state['payment_review_status']) {
            return;
        }

        $reviewed = in_array($state['payment_review_status'], ['approved', 'rejected'], true);

        DB::table('sbo_payment_transactions')->insert([
            'sbo_id' => $sboId,
            'provider' => 'espees',
            'local_reference' => sprintf('DEMO-PAY-2026-%04d', $index),
            'provider_reference' => sprintf('DEMO-ESP-%04d', $index),
            'amount' => 25,
            'currency' => 'Espees',
            'gateway_status' => $state['gateway_status'],
            'review_status' => $state['payment_review_status'],
            'raw_payload' => json_encode([
                'demo' => true,
                'workflow' => $state['label'],
                'seeded_by' => self::class,
            ]),
            'rejection_reason' => 'rejected' === $state['payment_review_status']
                ? 'Demo rejection: payment could not be matched to the SBO.'
                : null,
            'reviewed_by' => $reviewed ? $adminId : null,
            'submitted_at' => $createdAt->copy()->addDays(4)->toDateTimeString(),
            'reviewed_at' => $reviewed ? $createdAt->copy()->addDays(5)->toDateTimeString() : null,
            'created_at' => $createdAt->copy()->addDays(4)->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    private function seedRfqs(array $sboIds, $categories, CarbonInterface $now): void
    {
        $existingOrderIds = DB::table('purchase_orders')
            ->where('title', 'like', 'Demo RFP %')
            ->pluck('id');

        if ($existingOrderIds->isNotEmpty()) {
            DB::table('purchase_order_bids')->whereIn('purchase_order_id', $existingOrderIds)->delete();

            if (Schema::hasTable('purchase_order_invites')) {
                DB::table('purchase_order_invites')->whereIn('purchase_order_id', $existingOrderIds)->delete();
            }

            DB::table('purchase_orders')->whereIn('id', $existingOrderIds)->delete();
        }

        $items = $this->rfqItems();
        $sboOwnerIds = DB::table('sbos')->whereIn('id', $sboIds)->pluck('created_by', 'id');

        foreach ($sboIds as $offset => $ownerSboId) {
            $index = $offset + 1;
            $item = $items[$offset % count($items)];
            $category = $categories[$offset % $categories->count()];
            $status = 'open';
            $requestType = ['product', 'service', 'product_service'][$offset % 3];
            $visibility = 0 === $index % 5 ? PurchaseOrder::VISIBILITY_INVITE_ONLY : PurchaseOrder::VISIBILITY_PUBLIC;
            $createdAt = $now->copy()->subDays(max(1, count($sboIds) - $index));

            $orderId = (int) DB::table('purchase_orders')->insertGetId([
                'sbo_id' => $ownerSboId,
                'category_id' => (int) $category->id,
                'request_type' => $requestType,
                'title' => sprintf('Demo RFP %03d - %s', $index, $item['title']),
                'description' => $item['description'],
                'document_path' => null,
                'budget' => $item['budget'],
                'quantity' => in_array($requestType, ['product', 'product_service'], true) ? $item['quantity'] : null,
                'unit_specification' => in_array($requestType, ['product', 'product_service'], true) ? $item['unit'] : null,
                'delivery_location' => in_array($requestType, ['product', 'product_service'], true) ? $item['location'] : null,
                'service_scope' => in_array($requestType, ['service', 'product_service'], true) ? $item['scope'] : null,
                'service_duration' => in_array($requestType, ['service', 'product_service'], true) ? $item['duration'] : null,
                'required_expertise' => in_array($requestType, ['service', 'product_service'], true) ? $item['expertise'] : null,
                'required_documents' => $item['documents'],
                'terms' => $item['terms'],
                'order_deadline' => $this->rfqDeadline($status, $index, $now),
                'status' => $status,
                'visibility' => $visibility,
                'completed_at' => 'completed' === $status ? $createdAt->copy()->addDays(45)->toDateTimeString() : null,
                'award_note' => in_array($status, ['awarded', 'completed'], true) ? 'Demo award based on best value and compliance readiness.' : null,
                'created_at' => $createdAt->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            if (PurchaseOrder::VISIBILITY_INVITE_ONLY === $visibility) {
                $this->seedRfqInvites($orderId, $ownerSboId, $sboIds, $createdAt);
            }

            $this->seedRfqBids($orderId, $ownerSboId, $sboIds, $sboOwnerIds, $status, $createdAt);
        }
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    private function rfqItems(): array
    {
        $titles = [
            'Bulk audiovisual equipment supply',
            'Regional conference catering services',
            'Fleet maintenance and repair support',
            'Branded apparel production',
            'Medical outreach consumables',
            'School furniture manufacturing',
            'Cloud migration consulting',
            'Event stage and lighting installation',
            'Printed devotional material distribution',
            'Facility cleaning contract',
            'Security patrol service',
            'Construction materials supply',
            'Solar backup power installation',
            'Bookstore inventory replenishment',
            'Legal documentation review',
            'Accounting system implementation',
            'Interior fit-out works',
            'Digital marketing campaign support',
            'Cold-chain logistics service',
            'Office stationery supply',
            'Training facilitator engagement',
            'Translation and captioning service',
            'Website redesign and support',
            'Public address system rental',
            'CCTV and access control installation',
            'Corporate transport hire',
            'Food packaging materials supply',
            'Graphic design retainer',
            'Data entry and records cleanup',
            'Warehouse racking supply',
            'Generator servicing contract',
            'Procurement audit support',
            'Mobile clinic equipment supply',
            'Youth program merchandise',
            'Hotel accommodation block booking',
            'Printing press maintenance',
            'Agricultural produce supply',
            'Call center setup advisory',
            'Customer service training',
            'Conference registration platform',
            'Building renovation service',
            'Courier route management',
            'Uniform embroidery and finishing',
            'Livestream production service',
            'Bulk water and refreshment supply',
            'Furniture repair and restoration',
            'Inventory barcode rollout',
            'Compliance research service',
            'Payment reconciliation support',
            'Outdoor signage fabrication',
        ];

        return array_map(static function (string $title, int $offset): array {
            $index = $offset + 1;

            return [
                'title' => $title,
                'description' => sprintf('Demo procurement request for %s. Vendors should provide timeline, compliance documents, pricing, and support assumptions.', mb_strtolower($title)),
                'budget' => 5000 + ($index * 1350),
                'quantity' => (20 + ($index * 3)) . ' units',
                'unit' => ['Standard commercial grade', 'Premium grade', 'Batch delivery', 'Turnkey package'][$offset % 4],
                'location' => ['Lagos, Nigeria', 'Accra, Ghana', 'Johannesburg, South Africa', 'London, United Kingdom', 'Houston, United States'][$offset % 5],
                'scope' => 'Discovery, delivery planning, execution, quality assurance, handover documentation, and post-delivery support.',
                'duration' => ['2 weeks', '30 days', '6 weeks', '90 days'][$offset % 4],
                'expertise' => ['Procurement delivery', 'Technical implementation', 'Operations support', 'Compliance documentation'][$offset % 4],
                'documents' => 'Company profile, tax registration, delivery timeline, three references, and compliance statement.',
                'terms' => 'Demo terms: fixed-price proposal, milestone reporting, and acceptance after registry review.',
            ];
        }, $titles, array_keys($titles));
    }

    private function rfqDeadline(string $status, int $index, CarbonInterface $now): ?string
    {
        if ('cancelled' === $status) {
            return $now->copy()->subDays($index % 10 + 1)->toDateString();
        }

        if (in_array($status, ['awarded', 'completed'], true)) {
            return $now->copy()->subDays(20 + ($index % 20))->toDateString();
        }

        return $now->copy()->addDays(10 + ($index % 30))->toDateString();
    }

    private function seedRfqInvites(int $orderId, int $ownerSboId, array $sboIds, CarbonInterface $createdAt): void
    {
        if ( ! Schema::hasTable('purchase_order_invites')) {
            return;
        }

        $invitees = array_values(array_filter($sboIds, static fn (int $id): bool => $id !== $ownerSboId));

        foreach (array_slice($invitees, 0, 3) as $offset => $inviteeId) {
            DB::table('purchase_order_invites')->insert([
                'purchase_order_id' => $orderId,
                'invited_sbo_id' => $inviteeId,
                'invited_by_sbo_id' => $ownerSboId,
                'status' => ['pending', 'accepted', 'declined'][$offset % 3],
                'responded_at' => 0 === $offset ? null : $createdAt->copy()->addDays(2 + $offset)->toDateTimeString(),
                'created_at' => $createdAt->copy()->addDay()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);
        }
    }

    private function seedRfqBids(int $orderId, int $ownerSboId, array $sboIds, $sboOwnerIds, string $status, CarbonInterface $createdAt): void
    {
        if ( ! in_array($status, ['open', 'awarded', 'completed'], true)) {
            return;
        }

        $bidderIds = array_values(array_filter($sboIds, static fn (int $id): bool => $id !== $ownerSboId));
        $bidCount = 3;
        $acceptedBidId = null;

        foreach (array_slice($bidderIds, 0, $bidCount) as $offset => $bidderSboId) {
            $bidStatus = 'open' === $status
                ? 'pending'
                : (0 === $offset ? 'accepted' : 'rejected');

            $bidId = (int) DB::table('purchase_order_bids')->insertGetId([
                'purchase_order_id' => $orderId,
                'user_id' => (int) ($sboOwnerIds[$bidderSboId] ?? $sboOwnerIds[$ownerSboId]),
                'business_id' => null,
                'bidder_sbo_id' => $bidderSboId,
                'proposal' => 'Demo bid proposal with delivery timeline, commercial offer, risk notes, and warranty details.',
                'attachment_path' => null,
                'status' => $bidStatus,
                'submitted_at' => $createdAt->copy()->addDays(3 + $offset)->toDateTimeString(),
                'created_at' => $createdAt->copy()->addDays(3 + $offset)->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            if ('accepted' === $bidStatus) {
                $acceptedBidId = $bidId;
            }
        }

        if (null !== $acceptedBidId) {
            DB::table('purchase_orders')->where('id', $orderId)->update([
                'awarded_bid_id' => $acceptedBidId,
                'updated_at' => now()->toDateTimeString(),
            ]);
        }
    }

    private function refreshCategoryCounts($categories, CarbonInterface $now): void
    {
        foreach ($categories as $category) {
            $businessCount = DB::table('businesses')->where('category_id', $category->id)->count();
            $sboCount = DB::table('sbos')
                ->whereRaw('LOWER(category) = ?', [mb_strtolower((string) $category->name)])
                ->count();

            DB::table('categories')
                ->where('id', $category->id)
                ->update([
                    'business_count' => $businessCount + $sboCount,
                    'updated_at' => $now->toDateTimeString(),
                ]);
        }
    }

    private function adminUserId(CarbonInterface $now): int
    {
        $adminId = DB::table('users')
            ->whereIn('role', [UserRole::SUPER_ADMIN->value, UserRole::ADMIN->value])
            ->orderByRaw("CASE role WHEN 'super_admin' THEN 0 ELSE 1 END")
            ->value('id');

        if ($adminId) {
            return (int) $adminId;
        }

        $email = 'demo.superadmin@example.test';
        $existing = DB::table('users')->where('email', $email)->first();

        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update([
                'role' => UserRole::SUPER_ADMIN->value,
                'updated_at' => now()->toDateTimeString(),
            ]);

            return (int) $existing->id;
        }

        return (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'firstname' => 'Demo',
            'lastname' => 'Super Admin',
            'name' => 'Super Admin Demo',
            'phone' => '+2348000000000',
            'email' => $email,
            'email_verified_at' => $now->toDateTimeString(),
            'password' => $this->passwordHash(),
            'remember_token' => Str::random(10),
            'role' => UserRole::SUPER_ADMIN->value,
            'permissions' => null,
            'admin_page_permissions' => null,
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ]);
    }

    private function passwordHash(): string
    {
        return $this->passwordHash ??= Hash::make(self::PASSWORD);
    }
}
