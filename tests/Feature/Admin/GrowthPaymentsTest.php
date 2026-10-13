<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\SboPaymentTransaction;
use App\Models\User;
use App\Support\AdminPages;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

function growthPaymentsAdmin(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::ADMIN,
        'email_verified_at' => now(),
        'admin_page_permissions' => [AdminPages::GROWTH_PAYMENTS],
        'permissions' => AdminPages::capabilitiesForPages([AdminPages::GROWTH_PAYMENTS]),
    ], $overrides));
}

function growthPaymentsOwner(string $email): User
{
    return User::factory()->create([
        'email' => $email,
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
}

function growthPaymentsSbo(User $owner, string $name, array $overrides = []): Sbo
{
    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
        'created_by' => $owner->id,
        'country' => 'Nigeria',
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'payment_submitted_at' => now()->subMinutes(5),
        'calculated_fee' => 250,
        'payment_reference' => 'MANUAL-' . Str::upper(Str::random(6)),
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

test('growth payments page lists confirmed Espees and manual SBO payments with details', function (): void {
    $admin = growthPaymentsAdmin();
    $espeesOwner = growthPaymentsOwner('espees-owner@example.test');
    $manualOwner = growthPaymentsOwner('manual-owner@example.test');

    $espeesSbo = growthPaymentsSbo($espeesOwner, 'Confirmed Espees Growth SBO', [
        'application_id' => 'MBN-2026-1001',
        'calculated_fee' => null,
        'payment_reference' => 'PAYREF-ESPEES-1001',
    ]);
    SboPaymentTransaction::create([
        'sbo_id' => $espeesSbo->id,
        'provider' => 'espees',
        'local_reference' => 'ESPEES-LOCAL-1001',
        'provider_reference' => 'PAYREF-ESPEES-1001',
        'amount' => 1,
        'currency' => 'Espees',
        'gateway_status' => 'submitted',
        'review_status' => 'approved',
        'submitted_at' => now()->subMinutes(5),
        'reviewed_at' => now()->subMinutes(4),
    ]);

    $manualSbo = growthPaymentsSbo($manualOwner, 'Confirmed Manual Growth SBO', [
        'application_id' => 'MBN-2026-1002',
        'payment_reference' => 'MANUAL-1002',
    ]);
    $unpaidOwner = growthPaymentsOwner('unpaid-owner@example.test');
    growthPaymentsSbo($unpaidOwner, 'Unpaid Growth SBO', [
        'payment_status' => 'unpaid',
        'payment_confirmed_at' => null,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.growth.payments'))
        ->assertOk()
        ->assertSee('Payment history')
        ->assertSee($espeesSbo->name)
        ->assertSee($espeesOwner->email)
        ->assertSee('PAYREF-ESPEES-1001')
        ->assertSee('Espees')
        ->assertSee($manualSbo->name)
        ->assertSee($manualOwner->email)
        ->assertSee('MANUAL-1002')
        ->assertSee('Manual confirmation')
        ->assertDontSee('Unpaid Growth SBO');
});

test('growth payments page supports method and search filters', function (): void {
    $admin = growthPaymentsAdmin();
    $espeesOwner = growthPaymentsOwner('filtered-espees@example.test');
    $manualOwner = growthPaymentsOwner('filtered-manual@example.test');

    $espeesSbo = growthPaymentsSbo($espeesOwner, 'Filtered Espees SBO');
    SboPaymentTransaction::create([
        'sbo_id' => $espeesSbo->id,
        'provider' => 'espees',
        'local_reference' => 'ESPEES-FILTERED',
        'provider_reference' => 'PAYREF-FILTERED',
        'amount' => 250,
        'currency' => 'Espees',
        'gateway_status' => 'submitted',
        'review_status' => 'approved',
        'submitted_at' => now(),
        'reviewed_at' => now(),
    ]);
    growthPaymentsSbo($manualOwner, 'Filtered Manual SBO');

    Volt::actingAs($admin)
        ->test('admin.growth.payments')
        ->set('methodFilter', 'espees')
        ->assertSee('Filtered Espees SBO')
        ->assertDontSee('Filtered Manual SBO')
        ->set('methodFilter', 'all')
        ->set('search', 'Filtered Manual SBO')
        ->assertSee('Filtered Manual SBO')
        ->assertDontSee('Filtered Espees SBO');
});

test('growth payments page requires its page permission', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'email_verified_at' => now(),
        'admin_page_permissions' => [],
        'permissions' => [],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.growth.payments'))
        ->assertForbidden();
});
