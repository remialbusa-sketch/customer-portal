<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $user = auth()->user();
        $this->redirectIntended(
            default: route($user->homeRoute(), absolute: false),
            navigate: true
        );
    }
}; ?>

<div>
    {{-- Page heading --}}
    <div class="mb-6">
        <h2 class="font-display text-2xl font-bold text-brand-navy">Welcome back</h2>
        <p class="mt-1 text-sm text-brand-slate">Sign in to your MC BioTechnical Solutions portal.</p>
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="login" class="space-y-5">
        <div>
            <x-input-label for="email" :value="__('Email address')" />
            <x-text-input wire:model="form.email" id="email" class="mt-2" type="email" name="email" required autofocus autocomplete="username" placeholder="you@hospital.com" />
            <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input wire:model="form.password" id="password" class="mt-2"
                            type="password"
                            name="password"
                            required autocomplete="current-password" placeholder="••••••••" />
            <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between">
            <label for="remember" class="inline-flex items-center gap-2">
                <input wire:model="form.remember" id="remember" type="checkbox"
                       class="rounded border-brand-mist text-brand-blue shadow-sm focus:ring-brand-blue/30"
                       name="remember">
                <span class="text-sm text-brand-slate">{{ __('Remember me') }}</span>
            </label>

            @if (Route::has('password.request'))
                <a class="text-sm font-medium text-brand-blue hover:text-brand-blue-3 underline-offset-2 hover:underline focus:outline-none focus:ring-2 focus:ring-brand-blue/30 rounded"
                   href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot password?') }}
                </a>
            @endif
        </div>

        <x-primary-button class="w-full justify-center" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">{{ __('Sign in') }}</span>
            <span wire:loading wire:target="login" class="inline-flex items-center gap-2">
                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                {{ __('Signing in…') }}
            </span>
        </x-primary-button>
    </form>

    @if (Route::has('register'))
        <p class="mt-6 text-center text-sm text-brand-slate">
            {{ __("Don't have an account?") }}
            <a href="{{ route('register') }}" wire:navigate
               class="font-semibold text-brand-blue hover:text-brand-blue-3 underline-offset-2 hover:underline">
                {{ __('Get an invite link') }}
            </a>
        </p>
    @endif
</div>
