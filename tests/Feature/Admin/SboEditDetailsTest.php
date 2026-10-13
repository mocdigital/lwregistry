<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

function editableSbo(User $admin, array $overrides = []): Sbo
{
    return Sbo::create(array_merge([
        'name' => 'Original Name',
        'slug' => 'original-name-' . Str::random(5),
        'created_by' => $admin->id,
        'primary_contact_name' => 'Old Contact',
        'primary_contact_email' => 'old@example.com',
        'rc_number' => 'RC-1',
        'description' => 'old desc',
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

beforeEach(function (): void {
    $this->admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN->value]);
});

it('saves edited details for an SBO that has no directors on record', function (): void {
    $sbo = editableSbo($this->admin, ['directors' => null]);

    Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->set('name', 'Edited Name')
        ->set('rc_number', 'RC-999')
        ->set('description', 'new desc')
        ->call('updateSbo')
        ->assertHasNoErrors();

    $sbo->refresh();

    expect($sbo->name)->toBe('Edited Name')
        ->and($sbo->rc_number)->toBe('RC-999')
        ->and($sbo->description)->toBe('new desc')
        ->and($sbo->directors)->toBe([]);
});

it('lets an admin switch the entity type between SBO and COB', function (): void {
    $sbo = editableSbo($this->admin, ['entity_type' => Sbo::ENTITY_SBO, 'directors' => null]);

    Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->assertSet('entity_type', Sbo::ENTITY_SBO)
        ->set('entity_type', Sbo::ENTITY_COB)
        ->call('updateSbo')
        ->assertHasNoErrors();

    expect($sbo->refresh()->entity_type)->toBe(Sbo::ENTITY_COB);
});

it('rejects an entity type that is neither SBO nor COB', function (): void {
    $sbo = editableSbo($this->admin, ['directors' => null]);

    Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->set('entity_type', 'XYZ')
        ->call('updateSbo')
        ->assertHasErrors('entity_type');
});

it('loads every editable field into the form, formatting the establishment date', function (): void {
    $sbo = editableSbo($this->admin, [
        'tax_number' => 'TIN-1',
        'business_type' => 'Partnership',
        'category' => 'Education',
        'region' => 'East Africa',
        'established_at' => '2019-04-07',
        'website' => 'https://old.example.com',
        'phone' => '111',
        'kingschat_handle' => 'oldhandle',
        'staff_count' => 4,
        'business_model' => 'B2C',
        'directors' => null,
    ]);

    $component = Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit');

    expect($component->get('established_at'))->toBe('2019-04-07')
        ->and($component->get('tax_number'))->toBe('TIN-1')
        ->and($component->get('business_type'))->toBe('Partnership')
        ->and($component->get('category'))->toBe('Education')
        ->and($component->get('region'))->toBe('East Africa')
        ->and($component->get('website'))->toBe('https://old.example.com')
        ->and($component->get('phone'))->toBe('111')
        ->and($component->get('kingschat_handle'))->toBe('oldhandle')
        ->and($component->get('staff_count'))->toBe(4)
        ->and($component->get('business_model'))->toBe('B2C');
});

it('persists edits to every field exposed by the modal', function (): void {
    $sbo = editableSbo($this->admin, ['directors' => null, 'established_at' => '2019-04-07']);

    Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->set('name', 'Edited Name')
        ->set('rc_number', 'RC-999')
        ->set('tax_number', 'TIN-999')
        ->set('business_type', 'Cooperative')
        ->set('category', 'NGO')
        ->set('region', 'Asia')
        ->set('established_at', '2021-09-15')
        ->set('website', 'https://new.example.com')
        ->set('primary_contact_name', 'New Contact')
        ->set('primary_contact_email', 'new@example.com')
        ->set('phone', '999')
        ->set('kingschat_handle', 'newhandle')
        ->set('staff_count', 42)
        ->set('business_model', 'Hybrid')
        ->set('description', 'new desc')
        ->call('updateSbo')
        ->assertHasNoErrors();

    $sbo->refresh();

    expect($sbo->name)->toBe('Edited Name')
        ->and($sbo->rc_number)->toBe('RC-999')
        ->and($sbo->tax_number)->toBe('TIN-999')
        ->and($sbo->business_type)->toBe('Cooperative')
        ->and($sbo->category)->toBe('NGO')
        ->and($sbo->region)->toBe('Asia')
        ->and($sbo->established_at->format('Y-m-d'))->toBe('2021-09-15')
        ->and($sbo->website)->toBe('https://new.example.com')
        ->and($sbo->primary_contact_name)->toBe('New Contact')
        ->and($sbo->primary_contact_email)->toBe('new@example.com')
        ->and($sbo->phone)->toBe('999')
        ->and($sbo->kingschat_handle)->toBe('newhandle')
        ->and($sbo->staff_count)->toBe(42)
        ->and($sbo->business_model)->toBe('Hybrid')
        ->and($sbo->description)->toBe('new desc');
});

it('rejects a malformed website url', function (): void {
    $sbo = editableSbo($this->admin, ['directors' => null]);

    Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->set('website', 'not-a-url')
        ->call('updateSbo')
        ->assertHasErrors(['website']);

    expect($sbo->refresh()->name)->toBe('Original Name');
});

it('renders the edit modal as three tabbed sections', function (): void {
    $sbo = editableSbo($this->admin, ['directors' => null]);

    Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->assertSeeHtml('id="edit-org-identity"')
        ->assertSeeHtml('id="edit-org-contact"')
        ->assertSeeHtml('id="edit-org-profile"')
        // Tab switching is Livewire-driven so the state survives re-renders.
        ->assertSeeHtml('wire:click="setEditTab(\'identity\')"')
        ->assertSeeHtml('wire:click="setEditTab(\'contact\')"')
        ->assertSeeHtml('wire:click="setEditTab(\'profile\')"');
});

it('badges the tab holding a validation error so hidden failures stay findable', function (): void {
    $sbo = editableSbo($this->admin, ['directors' => null]);

    // Website lives on Identity; the contact email on Contact. Break both.
    $component = Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->set('website', 'not-a-url')
        ->set('primary_contact_email', 'not-an-email')
        ->call('updateSbo')
        ->assertHasErrors(['website', 'primary_contact_email']);

    $html = $component->html();

    // Str::between() spans to the *last* delimiter, so cut at the first
    // </button> to keep each assertion scoped to its own tab.
    $badgeFor = fn (string $tab): string => Str::before(
        Str::after($html, 'id="edit-org-' . $tab . '-tab"'),
        '</button>'
    );

    // Both affected tabs carry their own count badge...
    expect($badgeFor('identity'))->toContain('text-bg-danger')
        ->and($badgeFor('contact'))->toContain('text-bg-danger')
        // ...and the untouched tab does not.
        ->and($badgeFor('profile'))->not->toContain('text-bg-danger');
});

it('keeps the chosen tab active across a re-render', function (): void {
    $sbo = editableSbo($this->admin, ['directors' => null]);

    $component = Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->call('setEditTab', 'profile');

    expect($component->get('editTab'))->toBe('profile');

    // A re-render triggered by unrelated input must not reset the tab.
    $component->set('description', 'edited while on the profile tab');

    expect($component->get('editTab'))->toBe('profile');

    $profileTab = Str::before(Str::after($component->html(), 'id="edit-org-profile-tab"'), '</button>');
    expect($profileTab)->toContain('aria-selected="true"');
});

it('ignores an unknown tab key', function (): void {
    $sbo = editableSbo($this->admin, ['directors' => null]);

    $component = Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->call('setEditTab', 'nonsense');

    expect($component->get('editTab'))->toBe('identity');
});

it('jumps to the tab owning the failure when a save is rejected', function (): void {
    $sbo = editableSbo($this->admin, ['directors' => null]);

    // Sitting on Profile, but the broken field lives on Contact.
    $component = Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->call('setEditTab', 'profile')
        ->set('primary_contact_email', 'not-an-email')
        ->call('updateSbo')
        ->assertHasErrors(['primary_contact_email']);

    expect($component->get('editTab'))->toBe('contact');
});

it('jumps to the profile tab when only a director row is invalid', function (): void {
    $sbo = editableSbo($this->admin, ['directors' => null]);

    $component = Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->call('addDirector')
        ->set('directors.0.name', 'No Email Given')
        ->call('updateSbo')
        ->assertHasErrors(['directors.0.email']);

    expect($component->get('editTab'))->toBe('profile');
});

it('reopening the modal returns to the first tab and clears stale errors', function (): void {
    $sbo = editableSbo($this->admin, ['directors' => null]);

    $component = Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->set('primary_contact_email', 'not-an-email')
        ->call('updateSbo')
        ->assertHasErrors(['primary_contact_email']);

    expect($component->get('editTab'))->toBe('contact');

    $component->call('edit')->assertHasNoErrors();

    expect($component->get('editTab'))->toBe('identity');
});

it('saves when a stored director row is missing keys, normalising it on load', function (): void {
    $sbo = editableSbo($this->admin, ['directors' => [['name' => 'D One']]]);

    $component = Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit');

    expect($component->get('directors'))
        ->toBe([['name' => 'D One', 'email' => '', 'phone' => '']]);

    $component->set('directors.0.email', 'd1@example.com')
        ->set('name', 'Edited Name')
        ->call('updateSbo')
        ->assertHasNoErrors();

    $sbo->refresh();

    expect($sbo->name)->toBe('Edited Name')
        ->and($sbo->directors)->toBe([
            ['name' => 'D One', 'email' => 'd1@example.com', 'phone' => ''],
        ]);
});

it('drops a wholly blank director row instead of blocking the save', function (): void {
    $sbo = editableSbo($this->admin, [
        'directors' => [['name' => 'D One', 'email' => 'd1@example.com', 'phone' => '222']],
    ]);

    Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->call('addDirector')
        ->set('name', 'Edited Name')
        ->call('updateSbo')
        ->assertHasNoErrors();

    $sbo->refresh();

    expect($sbo->name)->toBe('Edited Name')
        ->and($sbo->directors)->toHaveCount(1);
});

it('still rejects a partially filled director row', function (): void {
    $sbo = editableSbo($this->admin, ['directors' => null]);

    Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->call('addDirector')
        ->set('directors.0.name', 'No Email Given')
        ->call('updateSbo')
        ->assertHasErrors(['directors.0.email']);

    expect($sbo->refresh()->name)->toBe('Original Name');
});

it('removes a director row and reindexes', function (): void {
    $sbo = editableSbo($this->admin, [
        'directors' => [
            ['name' => 'D One', 'email' => 'd1@example.com', 'phone' => '1'],
            ['name' => 'D Two', 'email' => 'd2@example.com', 'phone' => '2'],
        ],
    ]);

    Volt::actingAs($this->admin)->test('admin.sbos.show', ['sbo' => $sbo])
        ->call('edit')
        ->call('removeDirector', 0)
        ->call('updateSbo')
        ->assertHasNoErrors();

    expect($sbo->refresh()->directors)->toBe([
        ['name' => 'D Two', 'email' => 'd2@example.com', 'phone' => '2'],
    ]);
});
