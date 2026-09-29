<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    /**
     * Send an email verification notification to the user.
     */
    public function sendVerification(): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);

            return;
        }

        Auth::user()->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div class="animate-enter rounded-2xl bg-base-100 border border-base-300/70 shadow-[0_12px_36px_0_rgba(30,36,64,0.07)] px-9 py-8 space-y-5">
    <div>
        <p class="text-xs font-semibold tracking-widest uppercase text-base-content/50 mb-1">
            Almost there
        </p>
        <h1 class="font-semibold text-2xl text-base-content leading-tight">
            Verify your email
        </h1>
        <p class="text-sm text-base-content/70 mt-2">
            Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn't receive the email, we will gladly send you another.
        </p>
    </div>

    @if (session('status') == 'verification-link-sent')
        <div role="alert" class="alert alert-success shadow-sm flex-col items-start gap-1 p-3 text-sm">
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </div>
    @endif

    <div class="flex items-center justify-between">
        <button
            type="button"
            wire:click="sendVerification"
            class="btn btn-primary btn-sm gap-1"
        >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            {{ __('Resend verification email') }}
        </button>

        <button
            type="button"
            wire:click="logout"
            class="underline text-sm text-base-content/70 hover:text-base-content rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
        >
            {{ __('Log out') }}
        </button>
    </div>
</div>

