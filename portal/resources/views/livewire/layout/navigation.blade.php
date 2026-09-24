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
}; ?>

<nav x-data="{ open: false }"
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
                @if ($user)
                    <livewire:bell />
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

            <!-- Bell + Hamburger (mobile) -->
            <div class="-me-2 flex items-center sm:hidden">
                @if ($user)
                    <livewire:bell />
                @endif
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
