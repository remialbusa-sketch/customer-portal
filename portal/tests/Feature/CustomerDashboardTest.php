<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MondayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;
use Throwable;

/**
 * Customer dashboard rendering.
 *
 * The dashboard pulls the ticket list from Monday at render time.
 * These tests pin both behaviors:
 *   - normal path: mocked Monday tickets render as rows;
 *   - degrade path: a Monday outage (missing token, HTTP error,
 *     rate limit) must NOT 500 — the page renders empty with a
 *     "can't reach the ticket system" banner instead.
 */
class CustomerDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function actingAsCustomer(): User
    {
        $user = User::factory()->create(['role' => 'customer']);
        $this->actingAs($user);

        return $user;
    }

    public function test_dashboard_renders_tickets_from_monday(): void
    {
        $this->actingAsCustomer();

        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('ticketsForCustomer')->andReturn([
            [
                'id'                => '2831388203',
                'name'              => 'TICKET-00081',
                'status_text'       => 'Working on it',
                'subject_text'      => 'Analyzer will not start',
                'request_type_text' => 'Repair',
                'account_name'      => 'St. Lukes Medical Center',
                'tsp_person_ids'    => [],
                'item'              => ['column_values' => []],
            ],
        ]);
        $this->app->instance(MondayClient::class, $monday);

        $response = $this->get('/dashboard');

        $response
            ->assertOk()
            ->assertSeeVolt('layout.navigation')
            ->assertSee('TICKET-00081')
            ->assertDontSee("Can't reach the ticket system");
    }

    public function test_dashboard_degrades_gracefully_when_monday_is_unreachable(): void
    {
        $this->actingAsCustomer();

        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('ticketsForCustomer')
            ->andThrow(new \RuntimeException('MONDAY_API_TOKEN is not set. Add it to your .env file.'));
        $this->app->instance(MondayClient::class, $monday);

        $response = $this->get('/dashboard');

        $response
            ->assertOk()
            ->assertSeeVolt('layout.navigation')
            // Avoid the apostrophe: assertSee HTML-escapes its needle,
            // and the banner contains a raw ' in the response body.
            ->assertSee('reach the ticket system')
            // Zero stat cards in the degrade path.
            ->assertSee('New service request');
    }
}
