<?php

declare(strict_types=1);

use App\Livewire\ProfileModal;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create([
        'firstname' => 'John',
        'lastname' => 'Doe',
        'phone' => '+12025551234',
    ]);
});

describe('profile modal', function (): void {
    it('mounts with user data', function (): void {
        Livewire::actingAs($this->user)
            ->test(ProfileModal::class)
            ->assertSet('firstname', 'John')
            ->assertSet('lastname', 'Doe');
    });

    it('updates profile with valid data', function (): void {
        Livewire::actingAs($this->user)
            ->test(ProfileModal::class)
            ->set('firstname', 'Jane')
            ->set('lastname', 'Smith')
            ->set('phone', '+12025559999')
            ->set('kcHandle', '@jane_smith')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('profile-updated')
            ->assertDispatched('close-profile-modal');

        $this->user->refresh();
        expect($this->user->firstname)->toBe('Jane');
        expect($this->user->lastname)->toBe('Smith');
        expect($this->user->kc_handle)->toBe('@jane_smith');
    });

    it('uploads a png or jpg profile photo up to one megabyte', function (): void {
        Storage::fake('public');

        Livewire::actingAs($this->user)
            ->test(ProfileModal::class)
            ->set('firstname', 'Jane')
            ->set('lastname', 'Smith')
            ->set('profilePhoto', UploadedFile::fake()->image('avatar.png')->size(512))
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('profile-updated')
            ->assertDispatched('close-profile-modal')
            ->assertSet('firstname', 'Jane')
            ->assertSet('lastname', 'Smith')
            ->assertSet('profilePhoto', null);

        $this->user->refresh();

        expect($this->user->profile_photo_path)->toStartWith('profile-photos/');
        Storage::disk('public')->assertExists($this->user->profile_photo_path);
    });

    it('rejects profile photos larger than one megabyte', function (): void {
        Storage::fake('public');

        Livewire::actingAs($this->user)
            ->test(ProfileModal::class)
            ->set('firstname', 'Jane')
            ->set('lastname', 'Smith')
            ->set('profilePhoto', UploadedFile::fake()->image('avatar.jpg')->size(1100))
            ->call('save')
            ->assertHasErrors(['profilePhoto']);
    });

    it('validates required firstname', function (): void {
        Livewire::actingAs($this->user)
            ->test(ProfileModal::class)
            ->set('firstname', '')
            ->set('lastname', 'Smith')
            ->call('save')
            ->assertHasErrors(['firstname' => 'required']);
    });

    it('validates required lastname', function (): void {
        Livewire::actingAs($this->user)
            ->test(ProfileModal::class)
            ->set('firstname', 'Jane')
            ->set('lastname', '')
            ->call('save')
            ->assertHasErrors(['lastname' => 'required']);
    });

    it('validates phone format', function (): void {
        Livewire::actingAs($this->user)
            ->test(ProfileModal::class)
            ->set('firstname', 'Jane')
            ->set('lastname', 'Smith')
            ->set('phone', 'invalid-phone')
            ->call('save')
            ->assertHasErrors(['phone']);
    });

    it('allows empty phone', function (): void {
        Livewire::actingAs($this->user)
            ->test(ProfileModal::class)
            ->set('firstname', 'Jane')
            ->set('lastname', 'Smith')
            ->set('phone', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->user->refresh();
        expect($this->user->phone)->toBeNull();
    });

    it('opens modal on event dispatch', function (): void {
        Livewire::actingAs($this->user)
            ->test(ProfileModal::class)
            ->dispatch('open-profile-modal')
            ->assertDispatched('show-profile-modal');
    });
});
