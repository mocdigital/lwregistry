<?php

declare(strict_types=1);

use App\Livewire\SecurityModal;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create([
        'password' => Hash::make('current-password'),
    ]);
});

describe('security modal', function (): void {
    it('changes password with valid credentials', function (): void {
        Livewire::actingAs($this->user)
            ->test(SecurityModal::class)
            ->set('current_password', 'current-password')
            ->set('password', 'new-password-123')
            ->set('password_confirmation', 'new-password-123')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('password-updated')
            ->assertDispatched('close-security-modal');

        $this->user->refresh();
        expect(Hash::check('new-password-123', $this->user->password))->toBeTrue();
    });

    it('validates current password is correct', function (): void {
        Livewire::actingAs($this->user)
            ->test(SecurityModal::class)
            ->set('current_password', 'wrong-password')
            ->set('password', 'new-password-123')
            ->set('password_confirmation', 'new-password-123')
            ->call('save')
            ->assertHasErrors(['current_password']);
    });

    it('validates password confirmation matches', function (): void {
        Livewire::actingAs($this->user)
            ->test(SecurityModal::class)
            ->set('current_password', 'current-password')
            ->set('password', 'new-password-123')
            ->set('password_confirmation', 'different-password')
            ->call('save')
            ->assertHasErrors(['password']);
    });

    it('validates new password meets requirements', function (): void {
        Livewire::actingAs($this->user)
            ->test(SecurityModal::class)
            ->set('current_password', 'current-password')
            ->set('password', 'short')
            ->set('password_confirmation', 'short')
            ->call('save')
            ->assertHasErrors(['password']);
    });

    it('validates current password is required', function (): void {
        Livewire::actingAs($this->user)
            ->test(SecurityModal::class)
            ->set('current_password', '')
            ->set('password', 'new-password-123')
            ->set('password_confirmation', 'new-password-123')
            ->call('save')
            ->assertHasErrors(['current_password' => 'required']);
    });

    it('validates new password is required', function (): void {
        Livewire::actingAs($this->user)
            ->test(SecurityModal::class)
            ->set('current_password', 'current-password')
            ->set('password', '')
            ->set('password_confirmation', '')
            ->call('save')
            ->assertHasErrors(['password' => 'required']);
    });

    it('clears password fields after failed validation', function (): void {
        $component = Livewire::actingAs($this->user)
            ->test(SecurityModal::class)
            ->set('current_password', 'wrong-password')
            ->set('password', 'new-password-123')
            ->set('password_confirmation', 'new-password-123')
            ->call('save');

        $component->assertSet('current_password', '');
        $component->assertSet('password', '');
        $component->assertSet('password_confirmation', '');
    });

    it('clears password fields after successful save', function (): void {
        Livewire::actingAs($this->user)
            ->test(SecurityModal::class)
            ->set('current_password', 'current-password')
            ->set('password', 'new-password-123')
            ->set('password_confirmation', 'new-password-123')
            ->call('save')
            ->assertSet('current_password', '')
            ->assertSet('password', '')
            ->assertSet('password_confirmation', '');
    });

    it('opens modal on event dispatch', function (): void {
        Livewire::actingAs($this->user)
            ->test(SecurityModal::class)
            ->dispatch('open-security-modal')
            ->assertDispatched('show-security-modal');
    });
});
