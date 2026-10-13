<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\GbpcConferenceRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * Conference registrations submitted from the GBPC landing page
 * (gbpc.lwregistry.org), a static site that POSTs here cross-origin, and the
 * admin screen that collects them.
 */

function gbpcPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Brother',
        'full_name' => 'John Doe',
        'phone' => '08031234567',
        'kingschat_handle' => '@john',
        'email' => 'john@example.com',
        'country_city' => 'Nigeria / Lagos',
        'zone' => 'Lagos Zone 1',
        'occupation' => 'Engineer',
        'owns_business' => 'Yes',
        'business_name' => 'Doe Engineering Ltd',
        'expectations' => 'Divine direction and breakthrough for my business.',
    ], $overrides);
}

test('the GBPC endpoint stores a conference registration', function (): void {
    $this->postJson(route('gbpc.conference-registrations.store'), gbpcPayload())
        ->assertCreated()
        ->assertJson(['ok' => true]);

    $this->assertDatabaseHas('gbpc_conference_registrations', [
        'full_name' => 'John Doe',
        'email' => 'john@example.com',
        'country_city' => 'Nigeria / Lagos',
        'zone' => 'Lagos Zone 1',
        'occupation' => 'Engineer',
        'owns_business' => 'Yes',
        'business_name' => 'Doe Engineering Ltd',
        'kingschat_handle' => '@john',
    ]);
});

test('the GBPC endpoint requires all the fields', function (): void {
    $this->postJson(route('gbpc.conference-registrations.store'), ['full_name' => 'John'])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'title', 'phone', 'kingschat_handle', 'email',
            'country_city', 'zone', 'occupation', 'owns_business', 'expectations',
        ]);

    expect(GbpcConferenceRegistration::count())->toBe(0);
});

test('the business name is required only when they own a business', function (): void {
    // Says Yes but gives no business name → rejected.
    $this->postJson(route('gbpc.conference-registrations.store'), gbpcPayload([
        'owns_business' => 'Yes',
        'business_name' => '',
    ]))->assertStatus(422)->assertJsonValidationErrors(['business_name']);

    // Says No → business name may be blank.
    $this->postJson(route('gbpc.conference-registrations.store'), gbpcPayload([
        'email' => 'nobiz@example.com',
        'owns_business' => 'No',
        'business_name' => '',
    ]))->assertCreated();

    expect(GbpcConferenceRegistration::where('email', 'nobiz@example.com')->exists())->toBeTrue();
});

test('an admin sees the registrations list and can export it', function (): void {
    GbpcConferenceRegistration::create(gbpcPayload(['email' => 'jane@example.com', 'full_name' => 'Jane Roe']));

    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);

    Volt::actingAs($admin)->test('admin.gbpc-registrations')
        ->assertSee('jane@example.com')
        ->assertSee('Jane Roe');

    $csv = $this->actingAs($admin)
        ->get(route('admin.gbpc-registrations.export'))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('jane@example.com')->toContain('Full name')->toContain('Expectations');
});

test('the registrations page is closed to non-admins', function (): void {
    $member = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    Volt::actingAs($member)->test('admin.gbpc-registrations')->assertForbidden();
});
