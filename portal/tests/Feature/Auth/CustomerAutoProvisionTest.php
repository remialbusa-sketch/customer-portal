<?php

namespace Tests\Feature\Auth;

use App\Livewire\Bell;
use App\Models\User;
use App\Services\MondayClient;
use App\Services\MondayCustomerDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Mockery;
use Tests\TestCase;

/**
 * Auto-provisioning: a customer on the monday.com Customer Details
 * board logs in with the default password on their FIRST login —
 * no admin invite needed. The account is created on the fly with
 * must_change_password = true and the navigation shows a
 * temporary-password notice (dismissable per session) with a link
 * to set a password of their own.
 */
class CustomerAutoProvisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * The customer dashboard pulls the ticket list from Monday at
     * render time. Bind a mock returning zero tickets so the page
     * renders without real credentials (CI has no MONDAY_API_TOKEN).
     */
    private function mockMondayClientForDashboard(): void
    {
        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('ticketsForCustomer')->andReturn([]);
        $this->app->instance(MondayClient::class, $monday);
    }

    public function test_new_customer_on_monday_board_is_provisioned_and_can_log_in(): void
    {
        $email = 'new-customer@hospital.test';

        $directory = Mockery::mock(MondayCustomerDirectory::class);
        $directory->shouldReceive('findByEmail')
            ->with($email)
            ->andReturn([
                'id'           => '2829595594',
                'name'         => 'Neil Darwin San Juan',
                'group'        => 'NCR',
                'region'       => 'NCR',
                'branch'       => "St. Luke's BGC",
                'account_name' => 'St. Luke’s Medical Center',
                'email'        => $email,
                'address'      => 'BGC, Taguig',
                'user_status'  => 'Active',
                'brand'        => null,
                'model'        => null,
            ]);
        $directory->shouldReceive('flushCache')->byDefault();
        $this->app->instance(MondayCustomerDirectory::class, $directory);

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $email)
            ->set('form.password', User::DEFAULT_PASSWORD);

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', $email)->first();

        $this->assertNotNull($user);
        $this->assertSame('customer', $user->role);
        $this->assertSame('active', $user->status);
        $this->assertTrue($user->must_change_password);
        $this->assertSame('2829595594', $user->monday_id);
        $this->assertSame('NCR', $user->region);
        $this->assertSame("St. Luke's BGC", $user->branch);
        $this->assertSame('St. Luke’s Medical Center', $user->account_name);
        $this->assertSame('Neil Darwin San Juan', $user->name);
        $this->assertTrue(Hash::check(User::DEFAULT_PASSWORD, $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_customer_not_on_monday_board_cannot_log_in_with_default_password(): void
    {
        $directory = Mockery::mock(MondayCustomerDirectory::class);
        $directory->shouldReceive('findByEmail')->andReturn(null);
        $directory->shouldReceive('flushCache');
        $this->app->instance(MondayCustomerDirectory::class, $directory);

        $component = Volt::test('pages.auth.login')
            ->set('form.email', 'nobody@example.test')
            ->set('form.password', User::DEFAULT_PASSWORD);

        $component->call('login');

        $component
            ->assertHasErrors()
            ->assertNoRedirect();

        $this->assertNull(User::where('email', 'nobody@example.test')->first());
        $this->assertGuest();
    }

    public function test_customer_on_board_with_wrong_password_is_not_provisioned(): void
    {
        $directory = Mockery::mock(MondayCustomerDirectory::class);
        $directory->shouldNotReceive('findByEmail');
        $this->app->instance(MondayCustomerDirectory::class, $directory);

        $component = Volt::test('pages.auth.login')
            ->set('form.email', 'new-customer@hospital.test')
            ->set('form.password', 'totally-wrong');

        $component->call('login');

        $component
            ->assertHasErrors()
            ->assertNoRedirect();

        $this->assertNull(User::where('email', 'new-customer@hospital.test')->first());
        $this->assertGuest();
    }

    public function test_pending_account_can_browse_normally(): void
    {
        // No forced redirect — the notice in the navigation is enough.
        $user = User::factory()->create([
            'role'                 => 'customer',
            'password'             => User::DEFAULT_PASSWORD,
            'must_change_password' => true,
        ]);

        $this->actingAs($user);
        $this->mockMondayClientForDashboard();

        $this->get('/dashboard')->assertOk();
    }

    public function test_navigation_shows_temporary_password_notice_to_pending_account(): void
    {
        $user = User::factory()->create([
            'role'                 => 'customer',
            'password'             => User::DEFAULT_PASSWORD,
            'must_change_password' => true,
        ]);

        $this->actingAs($user);

        // The temporary-password notice lives INSIDE the notification
        // bell (pinned entry), not as a separate floating card.
        Livewire::test(Bell::class)
            ->assertSee('Temporary password')
            ->assertSee('Set password now')
            ->assertSee('Set up later');

        // And it counts toward the unread badge.
        $this->assertSame(1, Livewire::test(Bell::class)->unreadCount);
    }

    public function test_navigation_shows_notice_to_any_user_still_on_the_default_password(): void
    {
        // Seeded-style account (TSP roster): never went through
        // auto-provisioning, flag is off — but the password is still
        // the default, so the notice must appear for them too.
        $user = User::factory()->create([
            'role'                 => 'fse',
            'password'             => User::DEFAULT_PASSWORD,
            'must_change_password' => false,
        ]);

        $this->actingAs($user);

        // Seeded-style TSP check moved to the Bell component too.
        Livewire::test(Bell::class)
            ->assertSee('Temporary password')
            ->assertSee('Set password now')
            ->assertSee('Set up later');
    }

    public function test_navigation_hides_notice_when_dismissed_for_the_session(): void
    {
        $user = User::factory()->create([
            'role'                 => 'customer',
            'password'             => User::DEFAULT_PASSWORD,
            'must_change_password' => true,
        ]);

        $this->withSession(['passwordChangeDismissed' => true]);
        $this->actingAs($user);

        Livewire::test(Bell::class)
            ->assertDontSee('Temporary password');
    }

    public function test_navigation_hides_notice_after_password_change(): void
    {
        $user = User::factory()->create([
            'role'                 => 'customer',
            'password'             => User::DEFAULT_PASSWORD,
            'must_change_password' => true,
        ]);

        $this->actingAs($user);

        Volt::test('pages.auth.change-password')
            ->set('password', 'New-Passw0rd!')
            ->set('password_confirmation', 'New-Passw0rd!')
            ->call('changePassword');

        $user->refresh();

        $this->assertFalse($user->must_change_password);

        Livewire::test(Bell::class)
            ->assertDontSee('Temporary password');
    }

    public function test_forced_password_change_clears_the_flag_and_returns_to_dashboard(): void
    {
        $user = User::factory()->create([
            'role'                 => 'customer',
            'password'             => User::DEFAULT_PASSWORD,
            'must_change_password' => true,
        ]);

        $this->actingAs($user);

        $component = Volt::test('pages.auth.change-password')
            ->set('password', 'New-Passw0rd!')
            ->set('password_confirmation', 'New-Passw0rd!');

        $component->call('changePassword');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('New-Passw0rd!', $user->password));

        $this->mockMondayClientForDashboard();
        $this->get('/dashboard')->assertOk();
    }

    public function test_user_with_own_password_can_browse_normally(): void
    {
        $user = User::factory()->create([
            'role'                 => 'customer',
            'must_change_password' => false,
        ]);

        $this->actingAs($user);
        $this->mockMondayClientForDashboard();

        $this->get('/dashboard')->assertOk();

        Livewire::test(Bell::class)
            ->assertDontSee('Temporary password');
    }
}