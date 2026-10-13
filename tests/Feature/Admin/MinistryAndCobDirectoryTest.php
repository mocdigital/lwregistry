<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Ministry;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * The admin-managed ministry list and the COB half of the directory.
 *
 * Ministries are a plain reference list an admin curates; the active ones are
 * offered at registration (only when the entity type is SBO). The directory is
 * split into an SBO page and a COB page, each scoped to its entity_type.
 */

function adminUser(): User
{
    return User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);
}

function makeSbo(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Biz ' . Str::random(5);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(4),
        'created_by' => User::factory()->create()->id,
        'entity_type' => Sbo::ENTITY_SBO,
        'category' => 'Software Development',
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

test('an admin can add, rename, toggle and delete a ministry', function (): void {
    $admin = adminUser();

    Volt::actingAs($admin)->test('admin.ministries')
        ->set('name', 'Campus Ministry')
        ->call('saveMinistry')
        ->assertHasNoErrors();

    $ministry = Ministry::where('name', 'Campus Ministry')->firstOrFail();
    expect($ministry->is_active)->toBeTrue()
        ->and($ministry->slug)->toBe('campus-ministry');

    Volt::actingAs($admin)->test('admin.ministries')
        ->call('startRename', $ministry->id)
        ->set('editingName', 'Campus Ministry International')
        ->call('renameMinistry')
        ->assertHasNoErrors();

    expect($ministry->fresh()->name)->toBe('Campus Ministry International');

    Volt::actingAs($admin)->test('admin.ministries')
        ->call('toggleActive', $ministry->id);
    expect($ministry->fresh()->is_active)->toBeFalse();

    Volt::actingAs($admin)->test('admin.ministries')
        ->call('deleteMinistry', $ministry->id);
    expect(Ministry::find($ministry->id))->toBeNull();
});

test('a ministry in use by a listing cannot be deleted', function (): void {
    $admin = adminUser();
    $ministry = Ministry::create(['name' => 'Rhapsody', 'slug' => 'rhapsody', 'is_active' => true]);
    makeSbo(['ministry' => 'Rhapsody']);

    Volt::actingAs($admin)->test('admin.ministries')
        ->call('deleteMinistry', $ministry->id)
        ->assertHasErrors('delete');

    expect(Ministry::find($ministry->id))->not->toBeNull();
});

test('the SBO directory shows only SBO entities, the COB directory only COB', function (): void {
    $admin = adminUser();
    makeSbo(['name' => 'Sbo Alpha', 'entity_type' => Sbo::ENTITY_SBO]);
    makeSbo(['name' => 'Cob Beta', 'entity_type' => Sbo::ENTITY_COB]);

    Volt::actingAs($admin)->test('admin.businesses.index')
        ->assertSee('Sbo Alpha')
        ->assertDontSee('Cob Beta');

    Volt::actingAs($admin)->test('admin.cob.index')
        ->assertSee('Cob Beta')
        ->assertDontSee('Sbo Alpha');
});

test('the export can be scoped to one entity type', function (): void {
    makeSbo(['name' => 'Sbo Export', 'entity_type' => Sbo::ENTITY_SBO]);
    makeSbo(['name' => 'Cob Export', 'entity_type' => Sbo::ENTITY_COB]);

    $csv = $this->actingAs(adminUser())
        ->get(route('admin.businesses.export', ['format' => 'csv', 'entity' => Sbo::ENTITY_COB]))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Cob Export')->not->toContain('Sbo Export');
});
