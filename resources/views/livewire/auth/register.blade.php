<?php

use App\Models\User;
use App\Rules\ValidTurnstileToken;
use App\Support\TurnstileVerifier;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Propaganistas\LaravelPhone\Rules\Phone;

new #[Layout('components.layouts.auth')] class extends Component
{
    public string $firstname = '';

    public string $lastname = '';

    public string $phone = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $turnstileToken = '';

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        $validated = $this->validate([
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', (new Phone())->international()],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
            'turnstileToken' => [Rule::requiredIf(app(TurnstileVerifier::class)->enabled()), new ValidTurnstileToken()],
        ]);

        $user = new User();
        $user->firstname = $validated['firstname'];
        $user->lastname = $validated['lastname'];
        $user->phone = $validated['phone'] ?? null;
        $user->email = $validated['email'];
        $user->password = Hash::make($validated['password']);
        $user->save();

        event(new Registered($user));

        Auth::login($user);

        redirect()->route('verification.notice');

    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form method="POST" wire:submit.prevent="register" class="flex flex-col gap-6" data-ui-form>
        <!-- First Name -->
        <flux:input
            wire:model="firstname"
            :label="__('First Name')"
            type="text"
            required
            autofocus
            autocomplete="given-name"
            :placeholder="__('First name')"
        />

        <!-- Last Name -->
        <flux:input
            wire:model="lastname"
            :label="__('Last Name')"
            type="text"
            required
            autocomplete="family-name"
            :placeholder="__('Last name')"
        />

        <!-- Phone -->
        <flux:input
            wire:model="phone"
            :label="__('Phone Number (optional)')"
            type="tel"
            autocomplete="tel"
            :placeholder="__('+2348100449069 or +14155552671')"
        >
            <x-slot:description>
                <span class="text-sm text-zinc-500 dark:text-zinc-400">Enter phone number in international format starting with +</span>
            </x-slot:description>
        </flux:input>

        <!-- Email Address -->
        <flux:input
            wire:model="email"
            :label="__('Email address')"
            type="email"
            required
            autocomplete="email"
            placeholder="email@example.com"
        />

        <!-- Password -->
        <flux:input
            wire:model="password"
            :label="__('Password')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('Password')"
            viewable
        />

        <!-- Confirm Password -->
        <flux:input
            wire:model="password_confirmation"
            :label="__('Confirm password')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('Confirm password')"
            viewable
        />

        <x-turnstile wire-model="turnstileToken" />

        <div class="flex flex-col items-stretch gap-2">
            <x-ui.form-state />
            <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                {{ __('Create account') }}
            </flux:button>
        </div>
    </form>

    <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
        <span>{{ __('Already have an account?') }}</span>
        <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
    </div>
</div>
