<?php

use Livewire\Attributes\Title;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth'), Title('Verify Email | L
oveworld Registry')] class extends Component {

    /**
     * Send an email verification notification to the user.
     */
    public function sendVerification(): void
    {
        Auth::user()->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        redirect('/');
    }

    /**
     * Handle the component's rendering hook.
     */
    public function rendering(View $view): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);

            return;
        }
    }
}; ?>

<div class="rounded-2xl bg-white px-8 py-10 shadow-2xl">

    {{-- Illustration / Icon --}}
    <div class="mb-6 flex justify-center">
        <div class="flex h-16 w-16 items-center justify-center rounded-full"
             style="background-color: rgba(29, 191, 115, 0.15);">
            <svg xmlns="http://www.w3.org/2000/svg"
                 class="h-8 w-8"
                 fill="none"
                 viewBox="0 0 24 24"
                 stroke="#1DBF73">
                <path stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8m-18 8h18"/>
            </svg>
        </div>
    </div>

    {{-- Title --}}
    <h1 class="mb-3 text-center text-xl font-semibold"
        style="color:#0B2545;">
        Verify your email address
    </h1>

    {{-- Message --}}
    <p class="mb-6 text-center text-sm leading-relaxed text-gray-600">
        We’ve sent a verification link to your email address.
        Please click the link to activate your account.
    </p>

    {{-- Success Message --}}
    @if (session('status') == 'verification-link-sent')
        <div class="mb-5 rounded-lg px-4 py-3 text-center text-sm"
             style="background:#eafaf3;color:#1DBF73;">
            A new verification link has been sent to your email.
        </div>
    @endif

    {{-- CTA --}}
    <flux:button
        wire:click="sendVerification"
        class="w-full text-white font-medium"
        style="background:#1DBF73;">
        Resend verification email
    </flux:button>

    {{-- Secondary action --}}
    <div class="mt-6 text-center">
        <flux:link
            wire:click="logout"
            class="text-sm"
            style="color:#0B2545;">
            Log out and use a different account
        </flux:link>
    </div>

</div>
