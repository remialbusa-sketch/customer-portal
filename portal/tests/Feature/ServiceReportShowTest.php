<?php

namespace Tests\Feature;

use App\Models\ServiceReport;
use App\Models\User;
use App\Services\MondayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The read-only TSR detail view (tsp/service-reports/{id}).
 * Pins the revamp: ticket NAME in the header (not the numeric id),
 * sync-state badge, work-detail sections, and signature panels.
 */
class ServiceReportShowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_tsr_show_renders_ticket_name_sections_and_signature_panels(): void
    {
        $tsp = User::factory()->create(['role' => 'fse']);
        $this->actingAs($tsp);

        $report = ServiceReport::create([
            'monday_ticket_id'      => '2831388203',
            'user_id'               => $tsp->id,
            'author_role'           => 'fse',
            'service_status'        => 'completed',
            'problem_and_concerns'  => 'Analyzer will not start',
            'job_done'              => 'Replaced main board, calibrated optics.',
            'parts_replaced'        => 'Lamp unit',
            'local_id'              => '00000000-0000-0000-0000-000000000001',
            'sync_state'            => 'synced',
        ]);

        $monday = Mockery::mock(MondayClient::class);
        // loadMondayTicket()
        $monday->shouldReceive('getItem')->andReturn([
            'id'            => '2831388203',
            'name'          => 'TICKET-00083',
            'column_values' => [],
        ]);
        // ticketName(): board listing misses, getItem fallback hits
        $monday->shouldReceive('listTickets')->andReturn([]);
        $monday->shouldReceive('ticketName')->andReturn('TICKET-00083');
        $monday->shouldReceive('getItem')->andReturnUsing(function ($id) {
            return [
                'id'            => (string) $id,
                'name'          => 'TICKET-00083',
                'column_values' => [],
            ];
        });
        $this->app->instance(MondayClient::class, $monday);

        $response = $this->get('/tsp/service-reports/' . $report->id);

        $response->assertOk()
            ->assertSee('TICKET-00083')
            ->assertSee('Service window')
            ->assertSee('Equipment')
            ->assertSee('Work details')
            ->assertSee('Replaced main board, calibrated optics.')
            ->assertSee('Synced to Monday')
            ->assertSee('Field service engineer')
            ->assertSee('Customer')
            ->assertSee('Biomed')
            ->assertSee('Not collected');
    }
}