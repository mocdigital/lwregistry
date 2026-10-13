<?php

declare(strict_types=1);
use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('dashboard shows the next registration payment action for an unpaid SBO and hides it when paid', function (): void {
    $manager = User::factory()->create(['role' => UserRole::SBO_MANAGER, 'email_verified_at' => now()]);
    $name = 'Pay CTA SBO';
    $sbo = Sbo::create([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $manager->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ]);

    Livewire::actingAs($manager)->test('sbo.dashboard')
        ->assertSee('Complete registration payment')
        ->assertSeeHtml('step=fees');

    // Mark fees paid → CTA should disappear.
    $sbo->update(['payment_status' => 'paid', 'payment_confirmed_at' => now()]);

    Livewire::actingAs($manager)->test('sbo.dashboard')
        ->assertDontSee('Complete registration payment');
});

test('dashboard hides the make-payment CTA for a declined SBO', function (): void {
    $manager = User::factory()->create(['role' => UserRole::SBO_MANAGER, 'email_verified_at' => now()]);
    $name = 'Declined SBO';
    Sbo::create([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $manager->id,
        'verification_status' => 'declined',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ]);

    Livewire::actingAs($manager)->test('sbo.dashboard')
        ->assertDontSee('Go here to Make Payment');
});

test('dashboard surfaces locked and unlocked rfq actions based on full validation', function (): void {
    $manager = User::factory()->create(['role' => UserRole::SBO_MANAGER, 'email_verified_at' => now()]);

    Sbo::create([
        'name' => 'Pending RFQ SBO',
        'slug' => 'pending-rfq-sbo-' . Str::random(5),
        'created_by' => $manager->id,
        'verification_status' => 'approved',
        'documents_status' => 'pending',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'pending',
        'is_active' => false,
    ]);

    Livewire::actingAs($manager)->test('sbo.dashboard')
        ->assertSee('Review validation steps')
        ->assertSee('Locked');

    Sbo::where('created_by', $manager->id)->delete();

    Sbo::create([
        'name' => 'Verified RFQ SBO',
        'slug' => 'verified-rfq-sbo-' . Str::random(5),
        'created_by' => $manager->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ]);

    Livewire::actingAs($manager)->test('sbo.dashboard')
        ->assertSee('Post a procurement request')
        ->assertSeeHtml('create=1')
        ->assertSee('Open SBO network')
        ->assertSee('Available');
});
