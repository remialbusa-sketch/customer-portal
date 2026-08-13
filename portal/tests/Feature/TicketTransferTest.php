<?php

namespace Tests\Feature;

use App\Events\TicketTransferRequested;
use App\Events\TicketTransferred;
use App\Livewire\Tsp\Dashboard;
use App\Models\TicketTransfer;
use App\Models\User;
use App\Services\MondayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * TSP-to-TSP ticket transfer workflow.
 *
 * The handoff is two-step by design:
 *   1. The current assignee REQUESTs a transfer to a target TSP
 *      (creates a PENDING TicketTransfer row; nothing on Monday).
 *   2. The target TSP ACCEPTS — only then is the People column on
 *      Monday.com rewritten (old TSP removed, new TSP added).
 *
 * A claim does NOT mark the ticket responded, and a transfer does
 * NOT touch status95 or response_status — it only swaps the
 * assignee.
 */
class TicketTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $tspA;
    private User $tspB;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->tspA = User::factory()->create([
            'role'      => 'fse',
            'region'    => 'VISAYAS',
            'monday_id' => 1001,
            'email'     => 'tsp-a@mcbtsi.com',
        ]);

        $this->tspB = User::factory()->create([
            'role'      => 'fse',
            'region'    => 'VISAYAS',
            'monday_id' => 1002,
            'email'     => 'tsp-b@mcbtsi.com',
        ]);
    }

    /**
     * Stub MondayClient with a ticket assigned to $tspA and empty
     * available pool.
     */
    private function stubMonday(array $extraExpectations = []): MondayClient
    {
        $ticketA = [
            'id'             => '55',
            'name'           => 'Printer not working',
            'status_text'    => 'AWAITING',
            'subject_text'   => 'Printer not working',
            'account_name'   => 'Acme Corp',
            'tsp_person_ids' => ['1001'],
            'is_open'        => true,
            'item'           => ['column_values' => []],
        ];

        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('ticketsForTsp')
            ->with('1001')
            ->andReturn([$ticketA]);
        $monday->shouldReceive('unclaimedTicketsForRegion')
            ->andReturn([]);

        foreach ($extraExpectations as $expectation) {
            $monday->shouldReceive($expectation['method'])
                ->withArgs($expectation['args'] ?? Mockery::any())
                ->{$expectation['times'] ?? 'once'}()
                ->andReturn($expectation['return'] ?? null);
        }

        $this->app->instance(MondayClient::class, $monday);

        return $monday;
    }

    public function test_request_creates_pending_transfer_and_broadcasts(): void
    {
        $this->stubMonday();

        Livewire::actingAs($this->tspA)
            ->test(Dashboard::class)
            ->call('openTransfer', '55')
            ->assertSet('transferTicketId', '55')
            ->assertSet('transferToUserId', null)
            ->set('transferToUserId', $this->tspB->id)
            ->call('requestTransfer')
            ->assertSet('transferTicketId', null)
            ->assertDispatched('toast');

        $this->assertDatabaseHas('ticket_transfers', [
            'monday_ticket_id' => '55',
            'from_user_id'     => $this->tspA->id,
            'to_user_id'       => $this->tspB->id,
            'status'           => TicketTransfer::STATUS_PENDING,
        ]);

        Event::assertDispatched(TicketTransferRequested::class, function (TicketTransferRequested $e) {
            return $e->mondayTicketId === '55'
                && $e->toUserId === (int) $this->tspB->id;
        });
    }

    public function test_duplicate_pending_request_is_a_noop(): void
    {
        $this->stubMonday();

        TicketTransfer::create([
            'monday_ticket_id' => '55',
            'from_user_id'     => $this->tspA->id,
            'to_user_id'       => $this->tspB->id,
            'status'           => TicketTransfer::STATUS_PENDING,
        ]);

        Livewire::actingAs($this->tspA)
            ->test(Dashboard::class)
            ->call('openTransfer', '55')
            ->set('transferToUserId', $this->tspB->id)
            ->call('requestTransfer')
            ->assertDispatched('toast', type: 'info');

        $this->assertSame(
            1,
            TicketTransfer::where('monday_ticket_id', '55')->where('status', 'pending')->count()
        );
    }

    public function test_accept_rewrites_monday_people_column(): void
    {
        $transfer = TicketTransfer::create([
            'monday_ticket_id' => '55',
            'from_user_id'     => $this->tspA->id,
            'to_user_id'       => $this->tspB->id,
            'status'           => TicketTransfer::STATUS_PENDING,
        ]);

        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('ticketsForTsp')->with('1002')->andReturn([]);
        $monday->shouldReceive('unclaimedTicketsForRegion')->andReturn([]);
        $monday->shouldReceive('getItem')
            ->with(55)
            ->andReturn(['id' => '55', 'name' => 'Printer not working', 'column_values' => []]);
        $monday->shouldReceive('reassignTicket')
            ->with(55, '1001', '1002')
            ->once();
        $monday->shouldReceive('forgetBoardCache')->once();
        $this->app->instance(MondayClient::class, $monday);

        Livewire::actingAs($this->tspB)
            ->test(Dashboard::class)
            ->call('acceptTransfer', $transfer->id)
            ->assertDispatched('toast', type: 'success');

        $this->assertDatabaseHas('ticket_transfers', [
            'id'     => $transfer->id,
            'status' => TicketTransfer::STATUS_ACCEPTED,
        ]);
        $this->assertNotNull($transfer->fresh()->resolved_at);

        Event::assertDispatched(TicketTransferred::class, function (TicketTransferred $e) {
            return $e->mondayTicketId === '55'
                && $e->fromUserId === (int) $this->tspA->id
                && $e->toUserId === (int) $this->tspB->id;
        });
    }

    public function test_only_target_tsp_can_accept(): void
    {
        $transfer = TicketTransfer::create([
            'monday_ticket_id' => '55',
            'from_user_id'     => $this->tspA->id,
            'to_user_id'       => $this->tspB->id,
            'status'           => TicketTransfer::STATUS_PENDING,
        ]);

        // A third TSP (not the target) tries to accept.
        $intruder = User::factory()->create([
            'role'      => 'fse',
            'region'    => 'VISAYAS',
            'monday_id' => 1003,
            'email'     => 'tsp-c@mcbtsi.com',
        ]);

        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('ticketsForTsp')->with('1003')->andReturn([]);
        $monday->shouldReceive('unclaimedTicketsForRegion')->andReturn([]);
        $monday->shouldNotReceive('reassignTicket');
        $this->app->instance(MondayClient::class, $monday);

        Livewire::actingAs($intruder)
            ->test(Dashboard::class)
            ->call('acceptTransfer', $transfer->id)
            ->assertDispatched('toast', type: 'error');

        $this->assertDatabaseHas('ticket_transfers', [
            'id'     => $transfer->id,
            'status' => TicketTransfer::STATUS_PENDING,
        ]);
        Event::assertNotDispatched(TicketTransferred::class);
    }

    public function test_decline_keeps_assignment_and_never_touches_monday(): void
    {
        $transfer = TicketTransfer::create([
            'monday_ticket_id' => '55',
            'from_user_id'     => $this->tspA->id,
            'to_user_id'       => $this->tspB->id,
            'status'           => TicketTransfer::STATUS_PENDING,
        ]);

        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('ticketsForTsp')->with('1002')->andReturn([]);
        $monday->shouldReceive('unclaimedTicketsForRegion')->andReturn([]);
        $monday->shouldReceive('getItem')
            ->with(55)
            ->andReturn(['id' => '55', 'name' => 'Printer not working', 'column_values' => []]);
        $monday->shouldNotReceive('reassignTicket');
        $this->app->instance(MondayClient::class, $monday);

        Livewire::actingAs($this->tspB)
            ->test(Dashboard::class)
            ->call('declineTransfer', $transfer->id)
            ->assertDispatched('toast', type: 'success');

        $this->assertDatabaseHas('ticket_transfers', [
            'id'     => $transfer->id,
            'status' => TicketTransfer::STATUS_DECLINED,
        ]);
        Event::assertNotDispatched(TicketTransferred::class);
    }

    public function test_requester_can_cancel_pending_request(): void
    {
        $transfer = TicketTransfer::create([
            'monday_ticket_id' => '55',
            'from_user_id'     => $this->tspA->id,
            'to_user_id'       => $this->tspB->id,
            'status'           => TicketTransfer::STATUS_PENDING,
        ]);

        $this->stubMonday();

        Livewire::actingAs($this->tspA)
            ->test(Dashboard::class)
            ->call('cancelPendingTransfer', $transfer->id)
            ->assertDispatched('toast', type: 'success');

        $this->assertDatabaseHas('ticket_transfers', [
            'id'     => $transfer->id,
            'status' => TicketTransfer::STATUS_CANCELLED,
        ]);
    }
}
