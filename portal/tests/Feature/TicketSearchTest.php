<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MondayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Nav search quick-jump (GET /tickets/go).
 *
 * Pins: numeric ID jumps straight to the detail page, text with one
 * match jumps, several matches prefill the dashboard search (?q=),
 * and unknown/unauthorized/empty queries bounce back — never a 500.
 */
class TicketSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function ticketItem(string $id, string $name = 'TICKET-00081', string $subject = 'Analyzer will not start'): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'status_text' => 'Open',
            'subject_text' => $subject,
            'request_type_text' => 'Repair',
            'account_name' => 'St. Lukes',
            'tsp_person_ids' => [],
            'item' => ['column_values' => []],
            // End-user relation: the factory user owns this ticket so
            // the customer authorize path passes.
            'column_values' => [
                'board_relation_mm4f9mwv' => ['linked_item_ids' => [111]],
            ],
        ];
    }

    private function mockMondayForCustomer(array $getItem = []): void
    {
        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('findOrCreateCustomerItem')->andReturn('111');
        foreach ($getItem as $id => $item) {
            $monday->shouldReceive('getItem')->with($id)->andReturn($item);
        }
        $monday->shouldReceive('getItem')->byDefault()->andReturn(null);
        $this->app->instance(MondayClient::class, $monday);
    }

    public function test_numeric_id_jumps_to_customer_ticket(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $this->actingAs($user);

        $this->mockMondayForCustomer(['2831388203' => $this->ticketItem('2831388203')]);

        $this->get('/tickets/go?q=2831388203')
            ->assertRedirect('/tickets/2831388203');
    }

    public function test_unknown_numeric_id_bounces_back(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $this->actingAs($user);

        $this->mockMondayForCustomer();

        $this->from('/dashboard')->get('/tickets/go?q=9999999999')
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors('search');
    }

    public function test_single_text_match_jumps(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $this->actingAs($user);

        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('ticketsForCustomer')->andReturn([
            $this->ticketItem('2831388203'),
            $this->ticketItem('2831388204', 'TICKET-00082', 'Printer jam'),
        ]);
        $this->app->instance(MondayClient::class, $monday);

        $this->get('/tickets/go?q=' . urlencode('Analyzer'))
            ->assertRedirect('/tickets/2831388203');
    }

    public function test_multiple_text_matches_prefill_dashboard_search(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $this->actingAs($user);

        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('ticketsForCustomer')->andReturn([
            $this->ticketItem('2831388203'),
            $this->ticketItem('2831388204', 'TICKET-00082', 'Analyzer probe error'),
        ]);
        $this->app->instance(MondayClient::class, $monday);

        $response = $this->get('/tickets/go?q=' . urlencode('Analyzer'));
        $response->assertRedirect();
        $this->assertStringContainsString('q=Analyzer', urldecode($response->headers->get('Location')));
    }

    public function test_no_match_bounces_back(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $this->actingAs($user);

        $monday = Mockery::mock(MondayClient::class);
        $monday->shouldReceive('ticketsForCustomer')->andReturn([]);
        $this->app->instance(MondayClient::class, $monday);

        $this->from('/dashboard')->get('/tickets/go?q=' . urlencode('zzz-nope'))
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors('search');
    }

    public function test_superadmin_search_bounces_back(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($user);

        $this->from('/')->get('/tickets/go?q=123')
            ->assertRedirect('/')
            ->assertSessionHasErrors('search');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/tickets/go?q=123')->assertRedirect('/login');
    }
}
