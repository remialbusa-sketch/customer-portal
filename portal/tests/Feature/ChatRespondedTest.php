<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MondayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The response_status column flips to "RESPONDED" on the TSP's first
 * chat reply — NOT on claim. Claiming only writes the People column
 * and status95='AWAITING' (see MondayClient::claimTicket); the chat
 * send in Tsp\ChatController::send is what marks the ticket responded.
 */
class ChatRespondedTest extends TestCase
{
    use RefreshDatabase;

    public function test_tsp_chat_message_marks_ticket_responded(): void
    {
        $tsp = User::factory()->create([
            'role'      => 'fse',
            'monday_id' => 98765,
        ]);

        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('getItem')
            ->with('2749091149')
            ->once()
            ->andReturn(['id' => '2749091149', 'column_values' => []]);
        $monday->shouldReceive('markTicketResponded')
            ->with(2749091149)
            ->once();

        $this->app->instance(MondayClient::class, $monday);

        $response = $this->actingAs($tsp)
            ->postJson('/tsp/tickets/2749091149/chat', ['body' => 'We are on our way.']);

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('chat_messages', [
            'monday_ticket_id' => '2749091149',
            'user_id'          => $tsp->id,
            'sender_role'      => 'fse',
            'body'             => 'We are on our way.',
        ]);
    }
}
