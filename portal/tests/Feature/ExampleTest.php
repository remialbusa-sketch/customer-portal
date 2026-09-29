<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;
    /**
     * Guests hitting / land on the sign-in page (no landing page).
     */
    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login', absolute: false));
    }

    /**
     * Signed-in users hitting / land on their home dashboard.
     */
    public function test_authenticated_users_are_redirected_home(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $this->actingAs($user);

        $response = $this->get('/');

        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
