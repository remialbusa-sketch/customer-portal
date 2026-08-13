<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Set the user's own password and clear the first-login flag.
     */
    public function changePassword(): void
    {
        $validated = $this->validate([
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        $user = Auth::user();

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
            'remember_token' => null,
        ])->save();

        $this->redirect(route($user->homeRoute(), absolute: false), navigate: true);
    }
}; ?>

<div>
    <div class="mb-6">
        <h2 class="font-display text-2xl font-bold text-brand-navy">Set a password of your own</h2>
        <p class="mt-1 text-sm text-brand-slate">
            Welcome{{ auth()->user()?->name ? ', '.auth()->user()->name : '' }}! Your account is still using the
            temporary password — pick a password of your own to keep it secure.
        </p>
    </div>

    <form wire:submit="changePassword" class="space-y-5">
        <div>
            <x-input-label for="password" :value="__('New password')" />
            <x-text-input wire:model="password" id="password" class="mt-2"
                          type="password"
                          name="password"
                          required autocomplete="new-password" placeholder="••••••••" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password_confirmation" :value="__('Confirm new password')" />
            <x-text-input wire:model="password_confirmation" id="password_confirmation" class="mt-2"
                          type="password"
                          name="password_confirmation"
                          required autocomplete="new-password" placeholder="••••••••" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <x-primary-button class="w-full justify-center" wire:loading.attr="disabled" wire:target="changePassword">
            <span wire:loading.remove wire:target="changePassword">{{ __('Save new password') }}</span>
            <span wire:loading wire:target="changePassword" class="inline-flex items-center gap-2">
                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                {{ __('Saving…') }}
            </span>
        </x-primary-button>
    </form>
</div>