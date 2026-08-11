<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MondayClient;
use Mockery;
use Tests\TestCase;

/**
 * End-to-end role tests:
 * For each role (admin, manager, its, fse, customer) verify:
 *  - which dashboards are accessible
 *  - which ticket pages are accessible
 *  - which time-tracker actions are allowed
 *  - which internal-notes actions are allowed
 *
 * Uses Mockery to stub out the Monday API so we don't need real tickets
 * to be available.
 */
class AllRolesE2ETest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Point at the real dev database so we have the seeded users
        config(['database.connections.sqlite.database' => database_path('database.sqlite')]);
        \DB::purge('sqlite');

        $mock = Mockery::mock(MondayClient::class);
        $mock->shouldReceive('getItem')->andReturn([
            'id' => '2749091149',
            'name' => 'Mindray - BC-6800 | Verify relation with name fallback',
            'column_values' => [
                // Stub every key resources/views/tsp/ticket-show.blade.php
                // reads. Without these, the view throws
                // "Undefined array key" and returns HTTP 500.
                'text_mm5c1w5n'        => ['text' => 'Mock subject'],
                'long_text_mm4f8ve0'   => ['text' => 'mock note'],
                'long_text7'           => ['text' => 'mock description'],
                'board_relation_mm4f9mwv' => ['linked_item_ids' => [12345]],
                'status95'             => ['text' => 'OPEN'],
                'request_type'         => ['text' => 'Service Request'],
                'lookup_mm4f1f6y'      => ['display_value' => 'Mock Hospital'],
                'lookup_mm4fj9gp'      => ['display_value' => 'Mock Branch'],
                'email'                => ['text' => 'mock-customer@example.com'],
                'date'                 => ['text' => '2026-08-01'],
                'text_mm5apcrc'        => ['text' => 'Mindray'],
                'text_mm5am2kf'        => ['text' => 'BC-6800'],
            ],
            'updates' => [],
        ]);
        $mock->shouldReceive('getBoardItems')->andReturn([]);
        $mock->shouldReceive('createUpdate')->andReturn('9999999');
        $mock->shouldReceive('changeMultipleColumnValues')->andReturn([]);
        $mock->shouldReceive('writeLongTextColumn')->andReturn([]);
        $mock->shouldReceive('getColumnFormat')->andReturn([]);
        $mock->shouldReceive('getBoardColumns')->andReturn([]);
        $mock->shouldReceive('searchTicketsForUser')->andReturn([]);
        $mock->shouldReceive('ticketsForTsp')->andReturn([]);
        $mock->shouldReceive('ticketsForCustomer')->andReturn([]);
        $mock->shouldReceive('findOrCreateCustomerItem')->andReturn('12345');
        $mock->shouldReceive('getItemUpdates')->andReturn([]);

        $this->app->instance(MondayClient::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_admin_role_access(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($admin);

        $this->assertEquals(200, $this->get('/tsp/dashboard')->getStatusCode(), 'admin should access /tsp/dashboard');
        $this->assertEquals(403, $this->get('/dashboard')->getStatusCode(), 'admin should be blocked from /dashboard');
        $this->assertEquals(200, $this->get('/tsp/tickets/2749091149')->getStatusCode(), 'admin should access /tsp/tickets/{id}');
        $this->assertEquals(403, $this->get('/tickets/2749091149')->getStatusCode(), 'admin should be blocked from /tickets/{id}');

        // Time tracker
        $resp = $this->postJson('/tsp/tickets/2749091149/time/start', ['note' => 'admin e2e']);
        $this->assertEquals(200, $resp->getStatusCode());
        $resp = $this->postJson('/tsp/tickets/2749091149/time/pause');
        $this->assertEquals(200, $resp->getStatusCode());
        $resp = $this->postJson('/tsp/tickets/2749091149/time/resume');
        $this->assertEquals(200, $resp->getStatusCode());
        $resp = $this->postJson('/tsp/tickets/2749091149/time/stop');
        $this->assertEquals(200, $resp->getStatusCode());

        // Internal notes
        $resp = $this->postJson('/tsp/tickets/2749091149/notes', ['body' => 'admin e2e']);
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function test_manager_role_access(): void
    {
        $mgr = User::where('email', 'randee.borinaga@mcbtsi.com')->firstOrFail();
        $this->actingAs($mgr);

        $this->assertEquals(200, $this->get('/tsp/dashboard')->getStatusCode(), 'manager should access /tsp/dashboard');
        $this->assertEquals(403, $this->get('/dashboard')->getStatusCode(), 'manager should be blocked from /dashboard');
        $this->assertEquals(200, $this->get('/tsp/tickets/2749091149')->getStatusCode(), 'manager should access /tsp/tickets/{id}');
        $this->assertEquals(403, $this->get('/tickets/2749091149')->getStatusCode(), 'manager should be blocked from /tickets/{id}');

        // Time tracker
        $resp = $this->postJson('/tsp/tickets/2749091149/time/start', ['note' => 'manager e2e']);
        $this->assertEquals(200, $resp->getStatusCode());
        $resp = $this->postJson('/tsp/tickets/2749091149/time/pause');
        $this->assertEquals(200, $resp->getStatusCode());
        $resp = $this->postJson('/tsp/tickets/2749091149/time/resume');
        $this->assertEquals(200, $resp->getStatusCode());
        $resp = $this->postJson('/tsp/tickets/2749091149/time/stop');
        $this->assertEquals(200, $resp->getStatusCode());

        // Internal notes
        $resp = $this->postJson('/tsp/tickets/2749091149/notes', ['body' => 'manager e2e']);
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function test_its_role_access(): void
    {
        $its = User::where('email', 'orfe.calderon@mcbtsi.com')->firstOrFail();
        $this->actingAs($its);

        $this->assertEquals(200, $this->get('/tsp/dashboard')->getStatusCode(), 'its should access /tsp/dashboard');
        $this->assertEquals(403, $this->get('/dashboard')->getStatusCode(), 'its should be blocked from /dashboard');
        $this->assertEquals(200, $this->get('/tsp/tickets/2749091149')->getStatusCode(), 'its should access /tsp/tickets/{id}');
        $this->assertEquals(403, $this->get('/tickets/2749091149')->getStatusCode(), 'its should be blocked from /tickets/{id}');

        // Time tracker
        $resp = $this->postJson('/tsp/tickets/2749091149/time/start', ['note' => 'its e2e']);
        $this->assertEquals(200, $resp->getStatusCode());
        $resp = $this->postJson('/tsp/tickets/2749091149/time/pause');
        $this->assertEquals(200, $resp->getStatusCode());
        $resp = $this->postJson('/tsp/tickets/2749091149/time/resume');
        $this->assertEquals(200, $resp->getStatusCode());
        $resp = $this->postJson('/tsp/tickets/2749091149/time/stop');
        $this->assertEquals(200, $resp->getStatusCode());

        // Internal notes
        $resp = $this->postJson('/tsp/tickets/2749091149/notes', ['body' => 'its e2e']);
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function test_fse_role_access(): void
    {
        $fse = User::where('email', 'adonis.ybanez@mcbtsi.com')->firstOrFail();
        $this->actingAs($fse);

        $this->assertEquals(200, $this->get('/tsp/dashboard')->getStatusCode(), 'fse should access /tsp/dashboard');
        $this->assertEquals(403, $this->get('/dashboard')->getStatusCode(), 'fse should be blocked from /dashboard');
        $this->assertEquals(200, $this->get('/tsp/tickets/2749091149')->getStatusCode(), 'fse should access /tsp/tickets/{id}');
        $this->assertEquals(403, $this->get('/tickets/2749091149')->getStatusCode(), 'fse should be blocked from /tickets/{id}');

        // Time tracker
        $resp = $this->postJson('/tsp/tickets/2749091149/time/start', ['note' => 'fse e2e']);
        $this->assertEquals(200, $resp->getStatusCode());
        $resp = $this->postJson('/tsp/tickets/2749091149/time/pause');
        $this->assertEquals(200, $resp->getStatusCode());
        $resp = $this->postJson('/tsp/tickets/2749091149/time/resume');
        $this->assertEquals(200, $resp->getStatusCode());
        $resp = $this->postJson('/tsp/tickets/2749091149/time/stop');
        $this->assertEquals(200, $resp->getStatusCode());

        // Internal notes
        $resp = $this->postJson('/tsp/tickets/2749091149/notes', ['body' => 'fse e2e']);
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function test_customer_role_access(): void
    {
        $cust = User::where('email', 'customer@example.com')->firstOrFail();
        $this->actingAs($cust);

        $this->assertEquals(200, $this->get('/dashboard')->getStatusCode(), 'customer should access /dashboard');
        $this->assertEquals(403, $this->get('/tsp/dashboard')->getStatusCode(), 'customer should be blocked from /tsp/dashboard');
        $this->assertEquals(200, $this->get('/tickets/2749091149')->getStatusCode(), 'customer should access /tickets/{id}');
        $this->assertEquals(403, $this->get('/tsp/tickets/2749091149')->getStatusCode(), 'customer should be blocked from /tsp/tickets/{id}');

        // Time tracker
        $resp = $this->postJson('/tsp/tickets/2749091149/time/start', ['note' => 'unauthorized']);
        $this->assertEquals(403, $resp->getStatusCode(), 'customer should be blocked from time/start');

        // Internal notes
        $resp = $this->postJson('/tsp/tickets/2749091149/notes', ['body' => 'unauthorized']);
        $this->assertEquals(403, $resp->getStatusCode(), 'customer should be blocked from notes');
    }
}
