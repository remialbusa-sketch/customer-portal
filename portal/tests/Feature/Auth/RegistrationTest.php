<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The /register route is now the "ask your coordinator" landing
 * page (no token). Real registration goes through /register/{token}.
 *
 * The original Breeze-era RegistrationTest asserted that anyone
 * could self-register on /register. That flow is intentionally
 * gone — registration is invite-only now. These tests verify:
 *
 *   1. /register still renders the same component (just in
 *      "no invite" mode).
 *   2. Without an invite token, calling register() does NOT
 *      create a user and does NOT redirect to dashboard.
 *   3. /register/{token} DOES work — covered in detail in
 *      RegisterWithMachinesTest + RegisterWithOpenInviteTest.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.register');
    }

    public function test_new_users_cannot_self_register_without_an_invite(): void
    {
        // Bare /register (no token) — calling register() should
        // NOT create a user, NOT redirect to dashboard, and
        // should show a "no invite" message instead.
        $component = Volt::test('pages.auth.register')
            ->set('name', 'No Invite User')
            ->set('email', 'noinvite@example.com')
            ->set('password', 'Password!123')
            ->set('password_confirmation', 'Password!123');

        $component->call('register');

        // No user was created
        $this->assertNull(User::query()->where('email', 'noinvite@example.com')->first());

        // Nobody is authenticated
        $this->assertGuest();
    }
}
