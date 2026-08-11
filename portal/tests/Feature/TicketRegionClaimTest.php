<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MondayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Server-side region guard on the TSP claim flow.
 *
 * The Available pool is already scoped to the TSP's region via
 * unclaimedTicketsForRegion(), but the claim POST route / Livewire
 * action accepts an arbitrary ticket id. These tests verify a TSP
 * cannot claim a ticket whose customer region does not match their
 * own assigned region.
 */
class TicketRegionClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_is_rejected_when_ticket_is_outside_tsp_region(): void
    {
        $tsp = User::factory()->create([
            'role'      => 'fse',
            'region'    => 'VISAYAS',
            'monday_id' => 98765,
        ]);

        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('ticketIsInRegion')
            ->with(2749091149, 'VISAYAS')
            ->once()
            ->andReturn(false);
        $monday->shouldNotReceive('claimTicket');

        $this->app->instance(MondayClient::class, $monday);

        $response = $this->actingAs($tsp)
            ->post('/tsp/tickets/2749091149/claim');

        $response->assertStatus(302);
        $response->assertSessionHasErrors('claim');
    }

    public function test_claim_is_allowed_when_ticket_is_in_tsp_region(): void
    {
        $tsp = User::factory()->create([
            'role'      => 'fse',
            'region'    => 'VISAYAS',
            'monday_id' => 98765,
        ]);

        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('ticketIsInRegion')
            ->with(2749091149, 'VISAYAS')
            ->once()
            ->andReturn(true);
        $monday->shouldReceive('claimTicket')
            ->with(2749091149, '98765')
            ->once();

        $this->app->instance(MondayClient::class, $monday);

        $response = $this->actingAs($tsp)
            ->post('/tsp/tickets/2749091149/claim');

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
    }

    public function test_claim_requires_monday_id(): void
    {
        $tsp = User::factory()->create([
            'role'   => 'fse',
            'region' => 'VISAYAS',
        ]);

        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldNotReceive('ticketIsInRegion');
        $monday->shouldNotReceive('claimTicket');

        $this->app->instance(MondayClient::class, $monday);

        $response = $this->actingAs($tsp)
            ->post('/tsp/tickets/2749091149/claim');

        $response->assertStatus(302);
        $response->assertSessionHasErrors('claim');
    }
}
