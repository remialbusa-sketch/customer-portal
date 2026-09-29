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

<div class="rounded-2xl bg-base-100 border border-base-300/70 shadow-[0_12px_36px_0_rgba(30,36,64,0.07)] px-9 py-8">
    {{-- Heading --}}
    <div class="mb-5">
        <h2 class="font-bold text-[28px] text-base-content leading-tight">Welcome back</h2>
        <p class="mt-1.5 text-sm text-base-content/60 leading-relaxed">Sign in to manage tickets and see the latest service updates.</p>
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="login" class="space-y-4">
        <div>
            <x-input-label for="email" :value="__('Work email')" />
            <div class="relative mt-1.5">
                <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-base-content/40 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                <x-text-input wire:model="form.email" id="email" type="email" name="email" required autofocus autocomplete="username" placeholder="name@hospital.org" class="pl-10 h-12 text-sm" />
            </div>
            <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <div class="relative mt-1.5" x-data="{ shown: false }">
                <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-base-content/40 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                <x-text-input wire:model="form.password" id="password"
                                :type="'password'"
                                name="password" x-bind:type="shown ? 'text' : 'password'"
                                required autocomplete="current-password" placeholder="••••••••••••" class="pl-10 pr-11 h-12 text-sm" />
                <button type="button" x-on:click="shown = !shown"
                        :aria-label="shown ? 'Hide password' : 'Show password'"
                        class="absolute top-1/2 -translate-y-1/2 right-0 flex items-center pr-3.5 text-base-content/40 hover:text-base-content">
                    <svg x-show="!shown" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0Z" /></svg>
                    <svg x-show="shown" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244l3.236 3.236m-3.236-3.236L14.12 14.12" /></svg>
                </button>
            </div>
            <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between pt-0.5">
            <label for="remember" class="inline-flex items-center gap-2 cursor-pointer">
                <input wire:model="form.remember" id="remember" type="checkbox"
                       class="checkbox checkbox-primary checkbox-sm rounded"
                       name="remember">
                <span class="text-xs text-base-content/60">{{ __('Keep me signed in') }}</span>
            </label>

            @if (Route::has('password.request'))
                <a class="text-xs font-semibold text-primary hover:underline"
                   href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot password?') }}
                </a>
            @endif
        </div>

        <x-primary-button class="w-full justify-center !h-12 !text-[13px]" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login" class="inline-flex items-center gap-2">
                {{ __('Continue to ticket dashboard') }}
                <svg class="w-[15px] h-[15px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
            </span>
            <span wire:loading wire:target="login" class="inline-flex items-center gap-2">
                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                {{ __('Signing in…') }}
            </span>
        </x-primary-button>
    </form>

    <div class="mt-5 flex items-center gap-2.5 rounded-lg bg-base-200/60 px-3.5 py-2.5">
        <svg class="w-[15px] h-[15px] text-success shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <p class="text-[11px] text-base-content/60">Secure, encrypted access to your organization’s support records.</p>
    </div>
</div>
