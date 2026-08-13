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

    public function test_claim_ticket_flips_status95_to_awaiting(): void
    {
        config([
            'services.monday.tickets_board_id'        => 5029331350,
            'services.monday.tickets_columns.tsp'     => 'multiple_person_mm4fqar3',
            'services.monday.tickets_columns.status'  => 'status95',
            'services.monday.tickets_columns.response_status' => 'color_mm4vbp35',
        ]);

        $client = new class extends MondayClient {
            public array $calls = [];

            public function __construct()
            {
                // Skip the parent constructor (no API key needed —
                // every write method is stubbed below).
            }

            public function changeColumnValues(int $boardId, int|string $itemId, array $columnValues): void
            {
                $this->calls[] = ['changeColumnValues', $boardId, (int) $itemId, $columnValues];
            }

            public function writeSingleStatusColumn(int $boardId, int $itemId, string $columnId, string $label): void
            {
                $this->calls[] = ['writeSingleStatusColumn', $boardId, $itemId, $columnId, $label];
            }

            public function markTicketResponded(int $ticketItemId): void
            {
                $this->calls[] = ['markTicketResponded', $ticketItemId];
            }
        };

        $client->claimTicket(2749091149, '98765');

        // A claim writes the People column and the AWAITING status
        // only. It must NOT mark the ticket as RESPONDED — that
        // happens on the TSP's first chat reply, not on claim.
        $this->assertSame([
            ['changeColumnValues', 5029331350, 2749091149, [
                'multiple_person_mm4fqar3' => [
                    'personsAndTeams' => [
                        ['id' => 98765, 'kind' => 'person'],
                    ],
                ],
            ]],
            ['writeSingleStatusColumn', 5029331350, 2749091149, 'status95', 'AWAITING'],
        ], $client->calls);
    }
}
