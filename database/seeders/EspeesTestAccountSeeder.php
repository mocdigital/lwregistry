<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use App\Support\EspeesTestAccount;
use Illuminate\Database\Seeder;

final class EspeesTestAccountSeeder extends Seeder
{
    public function run(): void
    {
        if ( ! EspeesTestAccount::enabled()) {
            $this->command->warn('Espees test account seeding is disabled. Set ESPEES_TEST_ACCOUNT_ENABLED=true and use local/testing/staging, or explicitly set ESPEES_TEST_ACCOUNT_ALLOW_PRODUCTION=true for a production triage account, then run php artisan optimize:clear.');

            return;
        }

        $user = User::query()->updateOrCreate(
            ['email' => EspeesTestAccount::username()],
            [
                'firstname' => 'Espees',
                'lastname' => 'Test User',
                'phone' => null,
                'password' => EspeesTestAccount::password(),
                'role' => UserRole::SBO_MANAGER,
                'email_verified_at' => now(),
            ],
        );

        Sbo::query()->updateOrCreate(
            ['slug' => 'espees-integration-test-sbo'],
            [
                'name' => 'Espees Integration Test SBO',
                'created_by' => $user->id,
                'status' => 'active',
                'is_active' => true,
                'verification_status' => 'approved',
                'documents_status' => 'pending',
                'documents_compliant_at' => null,
                'payment_status' => 'unpaid',
                'payment_confirmed_at' => null,
                'payment_reference' => null,
                'payment_submitted_at' => null,
                'calculated_fee' => null,
                'share_structure' => null,
                'share_capital_type' => null,
                'issued_shares_volume' => null,
                'per_share_value' => null,
                'fee_band' => null,
            ],
        );

        $this->command->info('Espees test account created/reset: ' . EspeesTestAccount::username());
    }
}
