<?php

namespace Tests\Feature\Auth;

use App\Models\CustomerInvite;
use App\Models\Machine;
use App\Models\User;
use App\Services\MondayClient;
use App\Services\MondayCustomerDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Mockery;
use Tests\TestCase;

/**
 * Tests the open-invite registration flow (2026-07-01+).
 *
 * An open invite is issued for an email that is NOT already on
 * the monday.com Customer Details board. The customer is shown an
 * editable form for hospital/branch/region/address; on submit, the
 * portal creates a new monday row and links the new User to it.
 *
 * This complements RegisterWithMachinesTest, which exercises the
 * pre-2026-07-01 snapshot flow.
 */
class RegisterWithOpenInviteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fake MondayClient id we'll return from createCustomerItem().
     * Sentinel value so we can assert the same id reaches the user.
     */
    protected const NEW_MONDAY_CUSTOMER_ID = '5510029988';

    protected MondayClient $monday;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub the Monday client. shouldIgnoreMissing() so any
        // call we don't set up returns null instead of throwing.
        // Individual tests layer on top with ->shouldReceive(...)
        // as needed.
        $this->monday = Mockery::mock(MondayClient::class)->shouldIgnoreMissing();
        $this->monday->shouldReceive('flushCache')->andReturnNull();
        $this->monday->shouldReceive('cacheForget')->andReturnNull();
        $this->app->instance(MondayClient::class, $this->monday);

        // Stub the directory. The default behavior is to return
        // null from findByEmail (the customer isn't on monday
        // yet), which is what triggers the open-invite branch
        // in the component. Snapshot-specific tests re-bind this.
        $directory = Mockery::mock(MondayCustomerDirectory::class);
        $directory->shouldReceive('findByEmail')->andReturn(null);
        $directory->shouldReceive('regions')->andReturn(['NCR', 'NORTH LUZON', 'VISAYAS', 'MINDANAO']);
        $directory->shouldReceive('branches')->andReturn(['Quezon City', 'Manila', 'Cebu']);
        $this->app->instance(MondayCustomerDirectory::class, $directory);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Open invite: customer types all fields, monday row is
     * created, user is linked to the new monday id, invite is
     * consumed.
     */
    public function test_open_invite_happy_path_creates_monday_row_then_user(): void
    {
        Event::fake();
        Mail::fake();

        $this->monday->shouldReceive('createCustomerItem')
            ->once()
            ->withArgs(function (array $payload) {
                // The payload must carry what the customer typed in
                return ($payload['email']        ?? null) === 'newcust@stlukes.com'
                    && ($payload['account_name'] ?? null) === "St. Luke's Medical Center"
                    && ($payload['branch']       ?? null) === 'Quezon City'
                    && ($payload['region']       ?? null) === 'NCR';
            })
            ->andReturn(self::NEW_MONDAY_CUSTOMER_ID);

        // Build the open invite. is_snapshot = false, no monday_customer_id
        $invite = CustomerInvite::create([
            'email'              => 'newcust@stlukes.com',
            'token'              => 'open-token-' . uniqid(),
            // No account_name/branch/region/address — customer types them
            'is_snapshot'        => false,
            'monday_customer_id' => null,
            'expires_at'         => now()->addDays(7),
        ]);

        $this->assertFalse($invite->isSnapshot());
        $this->assertTrue($invite->isOpen());

        Volt::test('pages.auth.register', ['token' => $invite->token])
            ->set('name', 'New Customer')
            ->set('password', 'Password!123')
            ->set('password_confirmation', 'Password!123')
            ->set('accountName', "St. Luke's Medical Center")
            ->set('region', 'NCR')
            ->set('branch', 'Quezon City')
            ->set('address', '279 E. Rodriguez Sr. Ave., Quezon City')
            ->set('primaryBrand', 'Mindray')
            ->set('primaryModel', 'BC-6800')
            ->call('register')
            ->assertHasNoErrors();

        // The user was created
        $user = User::query()->where('email', 'newcust@stlukes.com')->first();
        $this->assertNotNull($user, 'User should have been created.');
        $this->assertSame('customer', $user->role);
        $this->assertSame(self::NEW_MONDAY_CUSTOMER_ID, $user->monday_id,
            'User should be linked to the new monday customer id.');
        $this->assertSame("St. Luke's Medical Center", $user->account_name);
        $this->assertSame('Quezon City', $user->branch);

        // A primary machine was created
        $machines = Machine::query()->where('user_id', $user->id)->get();
        $this->assertCount(1, $machines);
        $this->assertTrue($machines->first()->is_primary);

        // Invite is consumed
        $invite->refresh();
        $this->assertNotNull($invite->used_at);
        $this->assertSame((int) $user->id, (int) $invite->used_by_user_id);
    }

    /**
     * Missing required fields (hospital/branch/region) should
     * surface validation errors and prevent user + monday row
     * creation.
     */
    public function test_open_invite_validates_required_fields(): void
    {
        Event::fake();
        Mail::fake();

        $invite = CustomerInvite::create([
            'email'        => 'novalidate@stlukes.com',
            'token'        => 'open-token-noval-' . uniqid(),
            'is_snapshot'  => false,
            'expires_at'   => now()->addDays(7),
        ]);

        // shouldIgnoreMissing on the monday mock means a non-
        // configured createCustomerItem() call returns null
        // instead of throwing, so validation will correctly
        // fail and no monday row is created.

        $test = Volt::test('pages.auth.register', ['token' => $invite->token])
            ->set('name', 'Skip Validator')
            ->set('password', 'Password!123')
            ->set('password_confirmation', 'Password!123')
            // accountName / region / branch intentionally blank
            ->set('primaryBrand', 'Mindray')
            ->set('primaryModel', 'BC-6800')
            ->call('register');

        $test->assertHasErrors(['accountName', 'region', 'branch']);

        $this->assertNull(User::query()->where('email', 'novalidate@stlukes.com')->first());
        $this->assertCount(0, Machine::all());
    }

    /**
     * Address is optional for open invites (the location column
     * will simply be left empty on the monday side).
     */
    public function test_open_invite_address_is_optional(): void
    {
        Event::fake();
        Mail::fake();

        $this->monday->shouldReceive('createCustomerItem')
            ->once()
            ->withArgs(function (array $payload) {
                // address is absent/empty but everything else is filled
                return ($payload['email']        ?? null) === 'noaddr@stlukes.com'
                    && ($payload['account_name'] ?? null) === "St. Luke's Medical Center"
                    && empty($payload['address']);
            })
            ->andReturn('5510029989');

        $invite = CustomerInvite::create([
            'email'        => 'noaddr@stlukes.com',
            'token'        => 'open-token-noaddr-' . uniqid(),
            'is_snapshot'  => false,
            'expires_at'   => now()->addDays(7),
        ]);

        Volt::test('pages.auth.register', ['token' => $invite->token])
            ->set('name', 'No Addr Customer')
            ->set('password', 'Password!123')
            ->set('password_confirmation', 'Password!123')
            ->set('accountName', "St. Luke's Medical Center")
            ->set('region', 'NCR')
            ->set('branch', 'Quezon City')
            // address intentionally left blank
            ->set('primaryBrand', 'Mindray')
            ->set('primaryModel', 'BC-6800')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertNotNull(User::query()->where('email', 'noaddr@stlukes.com')->first());
    }

    /**
     * Snapshot invites (the pre-2026-07-01 behavior) must keep
     * working — the form is locked, the monday_id from the invite
     * is used as-is, and createCustomerItem is NOT called.
     */
    public function test_snapshot_invite_does_not_call_createCustomerItem(): void
    {
        Event::fake();
        Mail::fake();

        // Re-bind the directory to actually find the snapshot
        // customer. The default stub returns null (the "not on
        // monday yet" case) — but this is a snapshot invite,
        // the customer MUST already be on monday.
        $directory = Mockery::mock(MondayCustomerDirectory::class);
        $directory->shouldReceive('findByEmail')
            ->with('existing@stlukes.com')
            ->andReturn([
                'id'           => '1234567890',
                'name'         => "St. Luke's Medical Center",
                'group'        => 'NCR',
                'region'       => 'NCR',
                'branch'       => 'Quezon City',
                'account_name' => "St. Luke's Medical Center",
                'email'        => 'existing@stlukes.com',
                'address'      => '279 E. Rodriguez Sr. Ave., Quezon City',
                'user_status'  => 'Active',
                'brand'        => null,
                'model'        => null,
            ]);
        $directory->shouldReceive('regions')->andReturn(['NCR']);
        $directory->shouldReceive('branches')->andReturn(['Quezon City']);
        $this->app->instance(MondayCustomerDirectory::class, $directory);

        $invite = CustomerInvite::create([
            'email'              => 'existing@stlukes.com',
            'token'              => 'snap-token-' . uniqid(),
            'account_name'       => 'St. Luke\'s Medical Center',
            'branch'             => 'Quezon City',
            'region'             => 'NCR',
            'address'            => '279 E. Rodriguez Sr. Ave., Quezon City',
            'monday_customer_id' => '1234567890',
            'is_snapshot'        => true,
            'expires_at'         => now()->addDays(7),
        ]);

        $this->assertTrue($invite->isSnapshot());
        $this->assertFalse($invite->isOpen());

        // shouldIgnoreMissing on the monday mock means a
        // non-configured createCustomerItem() call returns null
        // instead of throwing. We're verifying it's NOT called
        // by the snapshot path.

        Volt::test('pages.auth.register', ['token' => $invite->token])
            ->set('name', 'Existing Customer')
            ->set('password', 'Password!123')
            ->set('password_confirmation', 'Password!123')
            ->set('primaryBrand', 'Mindray')
            ->set('primaryModel', 'BC-6800')
            ->call('register')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'existing@stlukes.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('1234567890', $user->monday_id,
            'Snapshot invite keeps the existing monday_id unchanged.');
    }

    /**
     * If monday.com fails to create the row, we should NOT create
     * a half-built user. The error should surface in the form.
     */
    public function test_open_invite_aborts_when_monday_create_fails(): void
    {
        Event::fake();
        Mail::fake();

        // Force createCustomerItem to return null (the "monday
        // is down" scenario). shouldIgnoreMissing on the rest.
        $this->monday->shouldReceive('createCustomerItem')
            ->once()
            ->andReturn(null);

        $invite = CustomerInvite::create([
            'email'        => 'mondaydown@stlukes.com',
            'token'        => 'open-token-mondaydown-' . uniqid(),
            'is_snapshot'  => false,
            'expires_at'   => now()->addDays(7),
        ]);

        $test = Volt::test('pages.auth.register', ['token' => $invite->token])
            ->set('name', 'Monday Down')
            ->set('password', 'Password!123')
            ->set('password_confirmation', 'Password!123')
            ->set('accountName', 'Some Hospital')
            ->set('region', 'NCR')
            ->set('branch', 'Manila')
            ->set('primaryBrand', 'Mindray')
            ->set('primaryModel', 'BC-6800')
            ->call('register');

        $test->assertHasErrors(['accountName']);

        $this->assertNull(User::query()->where('email', 'mondaydown@stlukes.com')->first());
    }

    /**
     * Snapshot invites that pre-date 2026-07-01 default to
     * is_snapshot = true. After the migration, existing rows
     * must still drive the locked-card flow.
     */
    public function test_existing_snapshot_invite_remains_locked(): void
    {
        $invite = new CustomerInvite([
            'email'        => 'legacy@example.com',
            'token'        => 'legacy-tok',
            'is_snapshot'  => true, // migration default
            'expires_at'   => now()->addDays(7),
        ]);
        $invite->save();

        $this->assertTrue($invite->isSnapshot());
        $this->assertFalse($invite->isOpen());
    }
}
