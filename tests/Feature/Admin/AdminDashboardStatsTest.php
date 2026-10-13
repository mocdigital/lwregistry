<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function dashboardBusiness(string $entity, string $verification): Sbo
{
    $owner = User::factory()->create(['role' => UserRole::SBO_MANAGER, 'email_verified_at' => now()]);

    return Sbo::create([
        'name' => $entity . ' ' . Str::random(6),
        'slug' => Str::lower($entity) . '-' . Str::random(6),
        'created_by' => $owner->id,
        'entity_type' => $entity,
        'verification_status' => $verification,
        'status' => 'active',
        'is_active' => true,
        'payment_status' => 'unpaid',
    ]);
}

test('the admin dashboard reports SBO and COB totals separately', function (): void {
    dashboardBusiness('SBO', 'approved');
    dashboardBusiness('SBO', 'pending');
    dashboardBusiness('COB', 'approved');

    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);

    Volt::actingAs($admin)->test('admin.dashboard')
        ->assertOk()
        // SBO cards.
        ->assertSee('Total SBOs')
        ->assertSee('Approved SBOs')
        ->assertSee('Pending SBOs')
        // COB cards, added alongside.
        ->assertSee('Total COBs')
        ->assertSee('Approved COBs')
        ->assertSee('Pending COBs')
        // Total users stays a whole-account count.
        ->assertSee('Total users');

    // The counts are entity-scoped and partition the table: the one COB is
    // counted as a COB and excluded from the SBO total. (User factories can add
    // their own SBOs, so this checks the split rather than an exact SBO count.)
    $sbos = fn () => Sbo::query()->where(fn ($q) => $q->where('entity_type', 'SBO')->orWhereNull('entity_type'));
    expect(Sbo::where('entity_type', 'COB')->count())->toBe(1)
        ->and($sbos()->count())->toBe(Sbo::count() - 1);
});
