<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }

    /**
     * "Set up later" — hide the temporary-password notice for the
     * rest of this session. The notice returns on the next session
     * until the user sets a password of their own.
     */
    public function dismissPasswordNotice(): void
    {
        session(['passwordChangeDismissed' => true]);
    }
}; ?>

<nav x-data="{ open: false, noticeOpen: true }"
     class="bg-base-100 border-b border-base-300/60 sticky top-0 z-30 backdrop-blur supports-[backdrop-filter]:bg-base-100/85">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center gap-2">
                    @php
                        $user = auth()->user();
                        $role = $user?->role;
                        $homeRoute = match($role) {
                            'superadmin' => 'admin.kpi',
                            'admin'    => 'admin.kpi',
                            'manager'  => 'tsp.dashboard',
                            'fse', 'its' => 'tsp.dashboard',
                            'customer' => 'dashboard',
                            default    => 'dashboard',
                        };
                    @endphp
                    <a href="{{ route($homeRoute) }}" class="flex items-center gap-2 group">
                        <x-application-logo class="block h-9 w-auto fill-current text-base-content" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-2 sm:-my-px sm:ms-8 sm:flex sm:items-center">
                    <x-nav-link :href="route($homeRoute)" :active="request()->routeIs($homeRoute)">
                        @if($user?->isSuperAdmin() || $user?->isAdmin())
                            {{ __('KPI') }}
                        @else
                            {{ __('Dashboard') }}
                        @endif
                    </x-nav-link>
                    @if($user?->isSuperAdmin())
                        <x-nav-link :href="route('admin.deletion-requests')" :active="request()->routeIs('admin.deletion-requests*')" wire:navigate>
                            {{ __('Delete Requests') }}
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-4">
                @if ($user && $user->usingDefaultPassword() && ! session('passwordChangeDismissed'))
                    <button @click="noticeOpen = ! noticeOpen" type="button" aria-label="Temporary password notice"
                            class="relative me-1 inline-flex h-9 w-9 items-center justify-center rounded-full text-base-content/60 transition hover:bg-base-200 hover:text-base-content"
                            title="Your password is still the temporary one">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                        <span class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-amber-400 ring-2 ring-base-100"></span>
                    </button>
                @endif

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="btn btn-ghost btn-sm gap-2 normal-case text-base-content/80 hover:bg-base-200 rounded-full">
                            <span class="hidden md:inline-flex w-7 h-7 rounded-full bg-primary text-primary-content items-center justify-center text-xs font-semibold">
                                {{ strtoupper(substr($user?->name ?? '?', 0, 1)) }}
                            </span>
                            <span x-data="{{ json_encode(['name' => $user?->name ?? '']) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name" class="text-sm font-medium"></span>

                            <svg class="h-4 w-4 opacity-60" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile')" wire:navigate>
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>

            {{-- Temporary password notice: shown while the account is
                 still on the default password (whether auto-provisioned
                 or seeded). Dismissable for the session via
                 "Set up later"; disappears for good once the user sets
                 a password of their own. --}}
            @if ($user && $user->usingDefaultPassword() && ! session('passwordChangeDismissed'))
                <div x-show="noticeOpen" x-transition
                     class="fixed right-4 top-20 z-50 w-80 max-w-[calc(100vw-2rem)] rounded-2xl border border-amber-200 bg-white p-4 shadow-soft sm:right-6">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-amber-100 text-amber-600">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </span>
                            <p class="text-sm font-semibold text-brand-navy">Temporary password</p>
                        </div>
                        <button @click="noticeOpen = false" type="button" aria-label="Close notification"
                                class="text-base-content/40 transition hover:text-base-content">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <p class="mt-3 text-sm leading-relaxed text-brand-slate">
                        Your account is still using the temporary password. Set a password of your own
                        to keep your account secure.
                    </p>

                    <div class="mt-4 flex items-center justify-between gap-2">
                        <a href="{{ route('password.change') }}" wire:navigate
                           class="inline-flex items-center rounded-lg bg-brand-cta px-3 py-2 text-sm font-semibold text-white transition hover:opacity-95">
                            Set password now
                        </a>
                        <button wire:click="dismissPasswordNotice" @click="noticeOpen = false" type="button"
                                class="text-sm font-medium text-brand-slate transition hover:text-brand-navy">
                            Set up later
                        </button>
                    </div>
                </div>
            @endif

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open"
                        class="btn btn-ghost btn-circle btn-sm"
                        :aria-expanded="open"
                        aria-label="Toggle menu">
                    <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden border-t border-base-300/60">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route($homeRoute)" :active="request()->routeIs($homeRoute)">
                @if($user?->isSuperAdmin() || $user?->isAdmin())
                    {{ __('KPI') }}
                @else
                    {{ __('Dashboard') }}
                @endif
            </x-responsive-nav-link>
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800" x-data="{{ json_encode(['name' => $user?->name ?? '']) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm text-gray-500">{{ $user?->email ?? '' }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
