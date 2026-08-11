<?php

namespace App\Http\Controllers\Tsp;

use App\Events\TicketClaimed;
use App\Http\Controllers\Controller;
use App\Services\MondayClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;

class TspDashboardController extends Controller
{
    /**
     * Render the Livewire TSP dashboard. The view lives in
     * resources/views/livewire/tsp/dashboard.blade.php; this
     * controller just delegates the initial render. All claim
     * interactions happen client-side via `wire:click` so the
     * page never navigates away.
     */
    public function index(MondayClient $monday): \Illuminate\Contracts\View\View
    {
        return view('tsp.dashboard', [
            'user' => auth()->user(),
        ]);
    }

    /**
     * Placeholder ticket-detail view. Full chat + internal notes
     * will be built on top of this in Phase 4.
     */
    public function show(string $id, MondayClient $monday): View
    {
        $item = $monday->getItem((int) $id);

        abort_if(! $item, 404, "Ticket {$id} not found in Monday.com.");

        // Resolve the assigned TSP(s) from the People column.
        // `$item['column_values'][people_col]['value']` is JSON with
        // shape {"personsAndTeams": [{"id": 12345, ...}, ...]} when
        // the column is populated. We pull the ids, then ask the
        // local User table for the matching names.
        $tspIds = [];
        $peopleCol = config('services.monday.tickets_columns.tsp');
        $peopleValue = $item['column_values'][$peopleCol]['value'] ?? null;
        if ($peopleValue) {
            $decoded = json_decode($peopleValue, true);
            if (is_array($decoded) && isset($decoded['personsAndTeams'])) {
                foreach ($decoded['personsAndTeams'] as $row) {
                    if (isset($row['id'])) {
                        $tspIds[] = (string) $row['id'];
                    }
                }
            }
        }
        $tspNameMap = MondayClient::resolveTspNames($tspIds);
        $assignedNames = [];
        foreach ($tspIds as $pid) {
            $name = $tspNameMap[$pid] ?? null;
            if ($name) {
                $assignedNames[] = $name;
            } else {
                $assignedNames[] = 'TSP #' . $pid;
            }
        }

        return view('tsp.ticket-show', [
            'user'           => auth()->user(),
            'ticket'         => $item,
            'assignedNames'  => $assignedNames,
        ]);
    }

    /**
     * Claim an unclaimed ticket: write the TSP's person ID into the
     * People column on Monday and flip the response status.
     *
     * This is a POST-only action (no GET). On success the TSP is
     * redirected to the ticket detail page; on failure back to the
     * dashboard with an error flash.
     */
    public function claim(string $id, MondayClient $monday): RedirectResponse
    {
        $user = auth()->user();

        if (empty($user->monday_id)) {
            return back()->withErrors([
                'claim' => 'Your account is not linked to Monday.com. An admin needs to set your monday_id before you can claim tickets.',
            ]);
        }

        // Region guard: the Available pool is already scoped to the
        // TSP's region via unclaimedTicketsForRegion(), but this POST
        // route accepts an arbitrary ticket id, so re-verify the
        // ticket's customer region here BEFORE writing to Monday.
        // Without this a TSP could claim a ticket outside their
        // region by posting a crafted ticket id.
        $tspRegion = $user->region;
        if (empty($tspRegion)) {
            $tspRegion = \App\Support\RegionResolver::resolveForCustomer($user);
        }
        if (empty($tspRegion) || ! $monday->ticketIsInRegion((int) $id, $tspRegion)) {
            Log::warning('TspDashboardController::claim rejected — ticket outside TSP region', [
                'ticket_id' => $id,
                'user_id'   => $user->id,
                'region'    => $tspRegion,
            ]);
            return back()->withErrors([
                'claim' => "Ticket #{$id} is outside your assigned region and cannot be claimed.",
            ]);
        }

        try {
            $monday->claimTicket((int) $id, (string) $user->monday_id);
        } catch (\Throwable $e) {
            Log::warning('TspDashboardController::claim failed', [
                'ticket_id' => $id,
                'user_id'   => $user->id,
                'error'     => $e->getMessage(),
            ]);
            return back()->withErrors([
                'claim' => 'Could not claim ticket — Monday.com returned an error. Please try again.',
            ]);
        }

        // Broadcast the claim on the customer-side channel so the
        // ticket's customer sees the assignment the moment it
        // happens (without waiting for the 15s ticket page poll).
        // Also broadcast on the region-wide channel so any other
        // TSPs viewing the same regional pool see the ticket leave
        // the available list in real-time.
        try {
            broadcast(new TicketClaimed(
                mondayTicketId: (string) $id,
                tspName:        (string) $user->name,
                tspRole:        (string) $user->role,
                previousStatus: 'NOT YET',
                newStatus:      'RESPONDED',
            ));
        } catch (\Throwable $e) {
            Log::warning('TicketClaimed broadcast failed', [
                'ticket_id' => $id,
                'user_id'   => $user->id,
                'error'     => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('tsp.tickets.show', $id)
            ->with('status', "Ticket #{$id} claimed — it's now in your queue.");
    }
}
