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
    $homeActive = request()->routeIs($homeRoute);
    // Account-chip subtitle per role (Figma "Hospital Name" line).
    $chipSub = match($role) {
        'customer' => $user?->account_name ?: 'Customer portal',
        'fse' => 'Field engineer' . ($user?->region ? ' · ' . $user->region : ''),
        'its' => 'IT specialist' . ($user?->region ? ' · ' . $user->region : ''),
        'manager' => 'Manager' . ($user?->region ? ' · ' . $user->region : ''),
        'admin' => 'Administration',
        'superadmin' => 'System',
        default => '',
    };
@endphp

{{-- =============================================================
     Top navigation — Figma "Customer portal - All-in-One" frame:
     60px white bar, logo + pill links, search box, help icon,
     notification bell, account chip. Primary stays portal navy.
     ============================================================= --}}
<nav x-data="{ open: false }"
     class="bg-base-100 border-b border-base-300/60 sticky top-0 z-30 backdrop-blur supports-[backdrop-filter]:bg-base-100/85">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-[60px] gap-3">
            {{-- Left: brand + links --}}
            <div class="flex items-center gap-6 min-w-0">
                <a href="{{ route($homeRoute) }}" class="flex items-center shrink-0" aria-label="Home">
                    <x-application-logo class="block h-9 w-auto fill-current text-base-content" />
                </a>

                <div class="hidden sm:flex items-center gap-1">
                    <a href="{{ route($homeRoute) }}" wire:navigate
                       @class(['flex items-center h-9 px-3 rounded-lg text-[13px] font-semibold transition', $homeActive ? 'bg-primary/10 text-primary' : 'text-base-content/60 hover:text-base-content hover:bg-base-200/60'])>
                        @if($user?->isSuperAdmin() || $user?->isAdmin())
                            {{ __('KPI') }}
                        @else
                            {{ __('Dashboard') }}
                        @endif
                    </a>
                    @if($user?->isSuperAdmin())
                        <a href="{{ route('admin.deletion-requests') }}" wire:navigate
                           @class(['flex items-center h-9 px-3 rounded-lg text-[13px] font-semibold transition', request()->routeIs('admin.deletion-requests*') ? 'bg-primary/10 text-primary' : 'text-base-content/60 hover:text-base-content hover:bg-base-200/60'])>
                            {{ __('Delete Requests') }}
                        </a>
                    @endif
                </div>
            </div>

            {{-- Right: search + help + bell + account --}}
            <div class="hidden sm:flex items-center gap-3">
                @if ($user && $role !== 'superadmin')
                    <form method="GET" action="{{ route('tickets.go') }}" class="relative" role="search">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-base-content/40 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search for tickets"
                               class="input input-bordered input-sm w-[220px] h-9 pl-8 text-[13px] bg-base-200/50 rounded-lg"
                               aria-label="Search for tickets">
                    </form>
                @endif

                @if ($user)
                    <a href="{{ route('help') }}" wire:navigate title="Help & Support"
                       class="w-9 h-9 rounded-lg border border-base-300/70 bg-base-100 text-base-content/60 hover:text-base-content hover:bg-base-200/60 transition inline-flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </a>
                    <livewire:bell />
                @endif

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="flex items-center gap-2 h-9 pl-2.5 pr-3 rounded-lg border border-base-300/70 bg-base-100 hover:bg-base-200/60 transition">
                            <span class="w-6 h-6 rounded-full bg-primary text-primary-content inline-flex items-center justify-center text-[11px] font-semibold">
                                {{ strtoupper(substr($user?->name ?? '?', 0, 1)) }}
                            </span>
                            <span class="text-left leading-tight">
                                <span x-data="{{ json_encode(['name' => $user?->name ?? '']) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name" class="block text-xs font-semibold text-base-content"></span>
                                <span class="block text-[10px] text-base-content/50">{{ $chipSub }}</span>
                            </span>
                            <svg class="h-3.5 w-3.5 opacity-60" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
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

            {{-- Mobile: bell + hamburger --}}
            <div class="flex items-center sm:hidden">
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
        <div class="px-4 pt-3 space-y-3">
            @if ($user && $role !== 'superadmin')
                <form method="GET" action="{{ route('tickets.go') }}" class="relative" role="search">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-base-content/40 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search for tickets"
                           class="input input-bordered input-sm w-full h-9 pl-8 text-[13px] bg-base-200/50 rounded-lg"
                           aria-label="Search for tickets">
                </form>
            @endif
            <div class="pb-1 space-y-1">
                <x-responsive-nav-link :href="route($homeRoute)" :active="request()->routeIs($homeRoute)">
                    @if($user?->isSuperAdmin() || $user?->isAdmin())
                        {{ __('KPI') }}
                    @else
                        {{ __('Dashboard') }}
                    @endif
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('help')" :active="request()->routeIs('help')">
                    {{ __('Help & Support') }}
                </x-responsive-nav-link>
            </div>
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
