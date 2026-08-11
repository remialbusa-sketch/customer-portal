<?php

namespace Tests\Feature;

use App\Models\InternalNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalNoteFlowTest extends TestCase
{
    use RefreshDatabase;

    protected ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        // RefreshDatabase gives us an empty in-memory DB. Seed the
        // minimum user the controller tests need.
        $this->admin = User::factory()->create(['role' => 'admin']);

        // These tests assert Monday-side effects (ticket lookup on the
        // live Tickets board, mirroring to the long_text column), so they
        // need a real ticket id on the CURRENT board and a network call
        // to api.monday.com. They are gated behind an explicit opt-in env
        // flag so the default `php artisan test` run stays offline-safe.
        if (env('RUN_LIVE_MONDAY_TESTS') !== '1') {
            $this->markTestSkipped(
                'Live-Monday integration test. Set RUN_LIVE_MONDAY_TESTS=1 '
                . 'and point the ticket ids below at items on the current '
                . 'Tickets - Customer board (5029331350) to run.'
            );
        }
    }

    public function test_tsp_ticket_page_renders_internal_notes_panel(): void
    {
        $resp = $this->actingAs($this->admin)->get('/tsp/tickets/2749091149');

        $resp->assertStatus(200);
        $resp->assertSee('Internal notes');
        $resp->assertSee('internal-note-body', false);
    }

    public function test_tsp_can_post_internal_note(): void
    {
        $body = 'E2E test note at ' . now()->toIso8601String();

        $resp = $this->actingAs($this->admin)
            ->postJson('/tsp/tickets/2749091149/notes', ['body' => $body]);

        $resp->assertStatus(200);
        $resp->assertJson(['ok' => true, 'body' => $body]);

        $note = InternalNote::orderBy('id', 'desc')->first();
        $this->assertNotNull($note);
        $this->assertEquals($body, $note->body);
        $this->assertNotNull($note->mirrored_to_monday_at, 'Monday mirror did not run.');
    }

    public function test_empty_body_is_rejected(): void
    {
        $resp = $this->actingAs($this->admin)
            ->postJson('/tsp/tickets/2749091149/notes', ['body' => '']);
        $status = $resp->getStatusCode();
        $body   = $resp->getContent();
        $this->assertTrue(in_array($status, [302, 422]), "Got status: {$status} body: " . substr($body, 0, 200));
    }

    public function test_customer_cannot_post_internal_note(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $resp = $this->actingAs($customer)
            ->postJson('/tsp/tickets/2749091149/notes', ['body' => 'sneaky']);
        $this->assertContains($resp->getStatusCode(), [403, 404]);
    }
}
