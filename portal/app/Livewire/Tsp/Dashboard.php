<?php

declare(strict_types=1);

namespace App\Livewire\Tsp;

use App\Actions\SyncPendingTsrReports;
use App\Enums\SyncState;
use App\Events\TicketClaimed;
use App\Events\TicketTransferRequested;
use App\Events\TicketTransferred;
use App\Models\ServiceReport;
use App\Models\TicketTransfer;
use App\Models\User;
use App\Services\MondayClient;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Livewire view of the TSP dashboard.
 *
 * The legacy controller view required three round-trips to claim a
 * ticket: open modal → POST form → redirect to detail. This
 * component collapses that into a single click: `wire:click="claim"`
 * runs the Monday mutation, optimistically removes the ticket from
 * the Available list, optimistically adds it to My tickets, and
 * shows a success toast — all without a navigation.
 *
 * The non-JS POST route (`tsp.tickets.claim`) is kept as a fallback
 * so a customer with JS disabled can still claim. The route still
 * routes to TspDashboardController::claim().
 *
 * The list of "My tickets" carries the assigned TSP name (always
 * the current TSP after claim, but the customer side will use the
 * same resolver via the People column on Monday) so the row can
 * show "Assigned to: <name>" if the assignee differs from the
 * current viewer.
 */
#[Layout('layouts.app')]
class Dashboard extends Component
{
    /**
     * Last status the controller redirected with (success message),
     * surfaced as a toast. Cleared after the toast is shown so
     * subsequent visits don't re-display it.
     */
    public ?string $flashStatus = null;

    /**
     * Local cache of "My tickets" — refreshed after a claim so
     * the just-claimed ticket appears without a full page reload.
     * Stored as a plain array (not a Collection) so Livewire 3
     * serializes it without complaining about Eloquent proxies.
     *
     * @var array<int, array>
     */
    public array $myTickets = [];

    /**
     * Local cache of "Available tickets in your region". After a
     * successful claim the claimed ticket is filtered out so the
     * user sees their action take effect immediately.
     *
     * @var array<int, array>
     */
    public array $availableTickets = [];

    /**
     * Counter arrays, populated alongside the ticket lists.
     *
     * Ticket-status counters are derived from the Monday
     * `status95` column text on every `loadLists()` run. The
     * categories mirror what the dashboard cards show so the
     * card numbers always agree with the row badges:
     *
     *   - `open`          → status contains "new" or "open"
     *   - `in_progress`   → status contains "progress"
     *   - `awaiting_parts`→ status contains "awaiting"
     *   - `resolved`      → status contains "resolved" / "closed"
     *                        / "done" / "complete" / "completed"
     *   - (anything else) → counts in `open` so we never drop
     *                       a ticket from the visible total
     *
     * Each ticket contributes to exactly one of `open`,
     * `in_progress`, `awaiting_parts`, or `resolved`, AND
     * always contributes to `total`. This keeps the card
     * numbers consistent with the row list — a previous
     * version of this code incremented both `open` and
     * `in_progress` for an in-progress ticket, which made
     * the cards look like they were double-counting.
     *
     * The pending_sync counter is split into two banner categories:
     *   - `pending_count`: rows still queued or in-flight (in
     *     `pending` / `syncing` state). These will go through
     *     automatically and only need a soft "queued" banner.
     *   - `error_count`: rows in `error` state. These need user
     *     attention (typically a permanently-broken source ticket
     *     on Monday, or invalid data). The "needs attention"
     *     banner surfaces them with retry / discard actions.
     *
     * `pending_sync` is kept for the soft "queued" callout (the
     * legacy single-banner UX) so other parts of the code that
     * read this key still work.
     *
     * @var array{total:int, open:int, in_progress:int, awaiting_parts:int, resolved:int,
     *            pending_sync:int, pending_count:int, error_count:int}
     */
    public array $stats = [
        'total'         => 0,
        'open'          => 0,
        'in_progress'   => 0,
        'awaiting_parts'=> 0,
        'resolved'      => 0,
        'pending_sync'  => 0,
        'pending_count' => 0,
        'error_count'   => 0,
    ];

    /**
     * Detailed view of the rows that need user attention (error
     * state). Each entry has enough info to render a row in the
     * "needs attention" banner: id (local DB id), monday_ticket_id,
     * sync_error, created_at, and a short label. Capped to the
     * most recent 5 so the banner doesn't explode if a user has
     * a lot of errored rows.
     *
     * @var array<int, array{id:int, ticket:?string, error:?string, created_at:?string}>
     */
    public array $errorReports = [];

    /**
     * Set true while a claim is in flight. Used to disable the
     * button so the TSP can't double-click and create two Monday
     * writes.
     */
    public bool $claiming = false;

    /**
     * Tracks which ticket id is currently being claimed (so we
     * can show a spinner on exactly that row).
     */
    public ?string $claimingId = null;

    /**
     * Holds the ticket data while the claim-confirmation modal is
     * open. Null when no modal is shown. The modal displays
     * account name, brand/model, subject, and description before
     * the TSP confirms.
     *
     * @var ?array{id:string, name:string, status_text:?string, subject_text:?string, account_name:?string, item:array}
     */
    public ?array $claimingTicket = null;

    // -----------------------------------------------------------------
    // Ticket-transfer state (TSP-to-TSP handoff)
    // -----------------------------------------------------------------

    /**
     * Pending transfer requests addressed to the current user —
     * each carries the requester's name and a ticket label so the
     * "Incoming transfer requests" card can render without another
     * Monday round-trip.
     *
     * @var array<int, array{id:int, monday_ticket_id:string, from_name:string, from_region:?string, ticket_label:string, created_at:?string}>
     */
    public array $incomingTransfers = [];

    /**
     * The current user's OWN pending requests (outgoing). Used to
     * render a small "Transfer pending" note on the affected ticket
     * row and to power the Cancel action.
     *
     * @var array<int, array{id:int, monday_ticket_id:string, to_name:string, status:string}>
     */
    public array $myPendingTransfers = [];

    /**
     * Ticket id while the transfer-target picker modal is open.
     * Null when the modal is hidden.
     */
    public ?string $transferTicketId = null;

    /**
     * Monday item name (e.g. "TICKET-00079") of the ticket in the
     * transfer modal, for display. Empty when the name is unknown.
     */
    public ?string $transferTicketName = null;

    /**
     * Cached list of candidate target TSPs for the open transfer
     * modal: every TSP in scope except the current user and anyone
     * already assigned to the ticket. Members without a Monday
     * person id are included with `assignable: false` so the list
     * reflects the full roster (the UI renders them disabled with
     * the reason). Scoped to the same branch by default; "all"
     * includes every region (used when the local branch has no
     * available TSPs).
     *
     * @var array<int, array{id:int, name:string, email:string, region:?string, assignable:bool}>
     */
    public array $transferTargets = [];

    /**
     * Branch scope of the transfer-target picker:
     *   'same' — TSPs in the current user's own region only (default)
     *   'all'  — TSPs across all four branches (cross-branch handoff)
     */
    public string $transferScope = 'same';

    /**
     * Selected target user id in the transfer modal (wire:model).
     */
    public ?int $transferToUserId = null;

    /**
     * True while a transfer request or accept is in flight, so the
     * buttons disable against double-clicks.
     */
    public bool $transferring = false;

    /**
     * Client-side filter state for both ticket lists. Bound with
     * wire:model.live so every change triggers a Livewire round-
     * trip and the #[Computed] getters re-evaluate immediately.
     *
     * @var array{query:string, status:string[], sort: string}
     */
    public array $filters = [
        'query'  => '',
        'status' => [],
        'sort'   => 'newest',
    ];

    /**
     * Human-readable warning to show when a TSP's region can't be
     * resolved (region/branch/address are all blank). Non-null
     * means "show the warning card". The user can't claim tickets
     * until the region is set — silently showing an empty list
     * was confusing (user filed a bug 2026-08-07: "no claimable
     * tickets even though there is an open ticket for NCR").
     *
     * @var ?string
     */
    public ?string $regionWarning = null;

    public function mount(MondayClient $monday): void
    {
        $user = auth()->user();
        $this->flashStatus = session('status');

        $this->loadLists($monday);
    }

    /**
     * Reload both ticket lists from Monday + the local DB. Called
     * on mount and after every successful claim.
     */
    protected function loadLists(MondayClient $monday): void
    {
        $user = auth()->user();
        $this->regionWarning = null;  // reset on every load
        $stats = [
            'total'         => 0,
            'open'          => 0,
            'in_progress'   => 0,
            'awaiting_parts'=> 0,
            'resolved'      => 0,
            'pending_sync'  => 0,
            'pending_count' => 0,
            'error_count'   => 0,
        ];
        $myTickets = [];
        $available = [];
        $errorReports = [];

        if (! empty($user->monday_id)) {
            $myTickets = $monday->ticketsForTsp((string) $user->monday_id);

            foreach ($myTickets as $t) {
                $stats['total']++;
                $status = strtolower((string) ($t['status_text'] ?? ''));
                if ($status === '') {
                    // Tickets with no status text still count in
                    // `total` (so the counter never silently hides
                    // a ticket) but don't get bucketed. The row
                    // shows a ghost badge so the TSP can see why.
                    continue;
                }
                // Mutual-exclusive categorisation. A ticket goes
                // into exactly one bucket so the four cards
                // (Open / In progress / Awaiting / Resolved) sum
                // to `total` for tickets that have a status.
                if (str_contains($status, 'resolved')
                    || str_contains($status, 'closed')
                    || str_contains($status, 'done')
                    || str_contains($status, 'complete')
                ) {
                    $stats['resolved']++;
                } elseif (str_contains($status, 'progress')) {
                    $stats['in_progress']++;
                } elseif (str_contains($status, 'awaiting')) {
                    $stats['awaiting_parts']++;
                } else {
                    // "new", "open", "responded", "working on it",
                    // or any future "still open" label we haven't
                    // seen yet — bucket as Open so we never drop a
                    // ticket from the visible total.
                    $stats['open']++;
                }
            }
        }

        // Resolve the TSP's region. Most users have it set in the
        // `users.region` column (PersonnelXlsxSeeder sets it from the
        // xlsx branch name), but accounts that came in via the Monday
        // sync (e.g. gerard.galindo@mcbtsi.com) sometimes have
        // `region = NULL` with their physical area stored only in the
        // free-text `branch` field ("NATIONAL CAPITAL REGION").
        //
        // Without this fallback, those TSPs would see an empty
        // Available list and have no way to claim regional tickets.
        //
        // RegionResolver is named "ForCustomer" but it operates on
        // any User (just inspects region/branch/address fields) so
        // we reuse it here. If both are null/unresolvable, the
        // Available list stays empty and a regionWarning is shown
        // so the TSP knows why they see no tickets (was a silent
        // failure as of 2026-08-07).
        $tspRegion = $user->region;
        if (empty($tspRegion)) {
            $tspRegion = \App\Support\RegionResolver::resolveForCustomer($user);
        }
        if (! empty($tspRegion)) {
            try {
                $available = $monday->unclaimedTicketsForRegion($tspRegion);
            } catch (\Throwable $e) {
                $available = [];
            }
        } else {
            $this->regionWarning = 'No region is set on your account, so the "Available tickets in your region" list is empty. Contact your manager to have your region (NCR, North Luzon, Visayas, Mindanao) set on your profile.';
        }

        // Split outstanding TSRs into two buckets so the banner
        // can be honest about what's actually going on:
        //   - pending + syncing → "queued" (auto, no action needed)
        //   - error              → "needs attention" (show error
        //                          message + retry / discard)
        // Rows in `discarded` state are excluded from the count
        // entirely — the user has already given up on them, and
        // we don't want the drainer to keep retrying.
        try {
            $stats['pending_count'] = (int) ServiceReport::query()
                ->where('user_id', $user->id)
                ->whereIn('sync_state', [SyncState::Pending->value, SyncState::Syncing->value])
                ->count();

            $stats['error_count'] = (int) ServiceReport::query()
                ->where('user_id', $user->id)
                ->where('sync_state', SyncState::Error->value)
                ->count();

            // Legacy single-number key, used by the soft "queued"
            // callout below the stats grid.
            $stats['pending_sync'] = $stats['pending_count'] + $stats['error_count'];

            // Pull the actual error rows so the banner can show
            // WHY each one is stuck. Capped to the most recent 5
            // so the banner doesn't explode.
            $errorReports = ServiceReport::query()
                ->where('user_id', $user->id)
                ->where('sync_state', SyncState::Error->value)
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get(['id', 'monday_ticket_id', 'sync_error', 'created_at'])
                ->map(static function (ServiceReport $r) {
                    return [
                        'id'         => (int) $r->id,
                        'ticket'     => $r->monday_ticket_id,
                        'error'      => $r->sync_error,
                        'created_at' => optional($r->created_at)->toDateTimeString(),
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            // Already zero-initialised; swallow DB errors so the
            // dashboard still renders even if service_reports is
            // somehow broken.
            Log::warning('Dashboard pending-sync count query failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        $this->myTickets        = array_values($myTickets);
        $this->availableTickets = array_values($available);
        $this->stats            = $stats;
        $this->errorReports     = $errorReports;

        // Transfer requests are local-DB state, so they never go
        // through the Monday round-trip — cheap to refresh on every
        // load.
        $this->loadIncomingTransfers($monday);
        $this->loadMyPendingTransfers();
    }

    /**
     * Reload pending transfer requests addressed to the current user.
     * The ticket label is resolved from the last known ticket info
     * (Monday item name via getItem, cached) with a "#id" fallback.
     */
    protected function loadIncomingTransfers(MondayClient $monday): void
    {
        $user = auth()->user();

        $this->incomingTransfers = TicketTransfer::query()
            ->where('to_user_id', $user->id)
            ->where('status', TicketTransfer::STATUS_PENDING)
            ->with('fromUser:id,name,region')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function (TicketTransfer $t) use ($monday) {
                $label = "Ticket #{$t->monday_ticket_id}";
                $item  = $monday->getItem((int) $t->monday_ticket_id);
                if ($item) {
                    $label = ($item['name'] ?? '') !== ''
                        ? $item['name']
                        : $label;
                }

                return [
                    'id'               => (int) $t->id,
                    'monday_ticket_id' => (string) $t->monday_ticket_id,
                    'from_name'        => (string) ($t->fromUser?->name ?? 'A TSP'),
                    'from_region'      => $t->fromUser?->region,
                    'ticket_label'     => $label,
                    'created_at'       => optional($t->created_at)->diffForHumans(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Reload the current user's own pending (outgoing) requests so
     * the affected ticket rows can show a "Transfer pending" hint
     * and a Cancel action.
     */
    protected function loadMyPendingTransfers(): void
    {
        $user = auth()->user();

        $this->myPendingTransfers = TicketTransfer::query()
            ->where('from_user_id', $user->id)
            ->where('status', TicketTransfer::STATUS_PENDING)
            ->with('toUser:id,name')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (TicketTransfer $t) => [
                'id'               => (int) $t->id,
                'monday_ticket_id' => (string) $t->monday_ticket_id,
                'to_name'          => (string) ($t->toUser?->name ?? 'a TSP'),
            ])
            ->values()
            ->all();
    }

    // ---------------------------------------------------------------------
    // Claim modal
    // ---------------------------------------------------------------------

    /**
     * Open the claim-confirmation modal for a ticket. Finds the
     * ticket in the current `availableTickets` array and stores a
     * reference so the modal can render its details.
     */
    public function showClaimModal(string $id): void
    {
        foreach ($this->availableTickets as $t) {
            if ((string) $t['id'] === $id) {
                $this->claimingTicket = $t;
                return;
            }
        }
    }

    /**
     * Close the claim-confirmation modal without claiming.
     */
    public function cancelClaim(): void
    {
        $this->claimingTicket = null;
    }

    /**
     * Confirm the claim from the modal. Calls the existing
     * claim() method with the ticket id stored in the modal
     * state, then closes the modal.
     */
    public function confirmClaim(MondayClient $monday): void
    {
        $ticket = $this->claimingTicket;
        $this->claimingTicket = null;

        if ($ticket === null || empty($ticket['id'])) {
            return;
        }

        $this->claim((string) $ticket['id'], $monday);
    }

    /**
     * Claim an unclaimed ticket: write the TSP's person ID into
     * the People column on Monday and flip status95 to "AWAITING"
     * (so the ticket counts under the Awaiting card).
     *
     * response_status stays "NOT YET" until the TSP actually
     * replies via chat (Tsp\ChatController::send flips it to
     * "RESPONDED") — a claim is not a response.
     *
     * After a successful claim:
     *   - The ticket is removed from `availableTickets` (it now
     *     has a People column value, so the unclaimed pool won't
     *     include it on the next refresh).
     *   - The ticket is prepended to `myTickets` (the TSP just
     *     claimed it, so it now shows in their queue).
     *   - The success toast is dispatched.
     *
     * On failure the row stays in the pool and an error toast is
     * shown — the TSP can retry.
     */
    public function claim(string $id, MondayClient $monday): void
    {
        if ($this->claiming) {
            return;
        }

        // Idempotency guard: if the ticket is already in the
        // current viewer's queue (e.g. a double-click on the
        // Claim button before the first response lands), don't
        // call MondayClient a second time. The optimistic UI
        // already removed it from `availableTickets`, so we
        // just no-op.
        foreach ($this->myTickets as $existing) {
            if ((string) ($existing['id'] ?? '') === $id) {
                return;
            }
        }

        $user = auth()->user();
        if (empty($user->monday_id)) {
            $this->dispatch('toast', type: 'error', title: 'Account not linked', body: 'Your account is missing a Monday ID — ask an admin to set it before claiming tickets.');
            return;
        }

        // Region guard: the Available pool is already scoped to the
        // TSP's region via unclaimedTicketsForRegion(), but claim()
        // accepts an arbitrary ticket id, so re-verify the ticket's
        // customer region here BEFORE writing to Monday. This stops
        // a TSP from claiming a ticket outside their region by
        // crafting a direct POST to this Livewire action.
        $tspRegion = $user->region;
        if (empty($tspRegion)) {
            $tspRegion = \App\Support\RegionResolver::resolveForCustomer($user);
        }
        if (empty($tspRegion) || ! $monday->ticketIsInRegion((int) $id, $tspRegion)) {
            Log::warning('Livewire Dashboard::claim rejected — ticket outside TSP region', [
                'ticket_id' => $id,
                'user_id'   => $user->id,
                'region'    => $tspRegion,
            ]);
            $this->dispatch('toast', type: 'error', title: 'Not in your region', body: "Ticket #{$id} is outside your assigned region and cannot be claimed.");
            return;
        }

        $this->claiming = true;
        $this->claimingId = $id;

        try {
            $monday->claimTicket((int) $id, (string) $user->monday_id);
        } catch (\Throwable $e) {
            Log::warning('Livewire Dashboard::claim failed', [
                'ticket_id' => $id,
                'user_id'   => $user->id,
                'error'     => $e->getMessage(),
            ]);
            $this->claiming = false;
            $this->claimingId = null;
            $this->dispatch('toast', type: 'error', title: 'Could not claim', body: 'Monday.com returned an error. Please try again.');
            return;
        }

        // Bust the board-items cache so loadLists() fetches
        // fresh data from Monday — without this, the 30s
        // Cache::remember in listTickets() would put the ticket
        // back in Available for up to 30 seconds.
        $monday->forgetBoardCache();

        // Optimistic UI: remove from available, add to mine,
        // refresh stats, and toast success. No page reload, no
        // redirect — the TSP stays on the dashboard.
        $this->availableTickets = array_values(array_filter(
            $this->availableTickets,
            static fn (array $t) => (string) $t['id'] !== $id,
        ));

        $this->myTickets = $this->buildClaimedTickets($id, $this->myTickets);

        // Recount stats from the (now-updated) myTickets list.
        $this->recomputeStats();

        $this->claiming = false;
        $this->claimingId = null;

        // Broadcast the claim so other TSPs' dashboards drop the
        // ticket from their Available pool instantly (via the
        // region.all Pusher channel + realtime-dashboard.js).
        event(new TicketClaimed(
            mondayTicketId: $id,
            tspName: $user->name,
            tspRole: $user->role,
        ));

        $this->dispatch('toast', type: 'success', title: 'Ticket claimed', body: "Ticket #{$id} is now in your queue.");
    }

    // ---------------------------------------------------------------------
    // Ticket transfer (TSP-to-TSP handoff)
    // ---------------------------------------------------------------------

    /**
     * Open the transfer-target picker modal for one of my tickets.
     * Only the current assignee may initiate a transfer, and only
     * while the ticket is still open.
     *
     * The modal opens even when the current branch has no candidates
     * — the TSP can then switch the scope to "all branches" for a
     * cross-branch handoff (e.g. their region has no available TSP).
     */
    public function openTransfer(string $id, MondayClient $monday): void
    {
        $user   = auth()->user();
        $ticket = null;
        foreach ($this->myTickets as $t) {
            if ((string) $t['id'] === $id) {
                $ticket = $t;
                break;
            }
        }
        if (! $ticket) {
            $this->dispatch('toast', type: 'error', title: 'Not your ticket', body: "Ticket #{$id} is not in your queue anymore.");
            return;
        }

        // Only the TSP who is actually assigned can hand it over.
        $mine = in_array((string) $user->monday_id, array_map('strval', $ticket['tsp_person_ids'] ?? []), true);
        if (! $mine) {
            $this->dispatch('toast', type: 'error', title: 'Not your ticket', body: "Ticket #{$id} is not assigned to you.");
            return;
        }

        $status = strtolower((string) ($ticket['status_text'] ?? ''));
        if (str_contains($status, 'resolved')
            || str_contains($status, 'closed')
            || str_contains($status, 'done')
            || str_contains($status, 'complete')
        ) {
            $this->dispatch('toast', type: 'error', title: 'Ticket closed', body: "Ticket #{$id} is already resolved and can't be transferred.");
            return;
        }

        $this->transferTicketId   = $id;
        $this->transferTicketName = (string) ($ticket['name'] ?? '');
        $this->transferScope      = 'same';
        $this->transferToUserId   = null;
        $this->loadTransferTargets();
    }

    /**
     * (Re)build the candidate TSP list for the open transfer modal
     * from the current scope: same region only, or all four branches.
     * Shared by openTransfer() and the updatedTransferScope() hook so
     * toggling the scope in the modal re-filters live.
     */
    protected function loadTransferTargets(): void
    {
        $this->transferTargets = [];

        if ($this->transferTicketId === null) {
            return;
        }

        $user   = auth()->user();
        $ticket = null;
        foreach ($this->myTickets as $t) {
            if ((string) $t['id'] === $this->transferTicketId) {
                $ticket = $t;
                break;
            }
        }
        if (! $ticket) {
            return;
        }

        $crossBranch = $this->transferScope === 'all';
        $tspRegion   = $user->region
            ?: \App\Support\RegionResolver::resolveForCustomer($user);

        if (! $crossBranch && $tspRegion === null) {
            return;
        }

        $assignedMondayIds = array_map('intval', $ticket['tsp_person_ids'] ?? []);
        $directory         = \App\Support\PersonnelDirectory::forCustomerAssignment(
            $crossBranch ? null : $tspRegion,
        );

        $targets = [];
        foreach ($directory as $group) {
            foreach ($group['members'] as $member) {
                if ((int) $member['id'] === (int) $user->id) {
                    continue;
                }
                if (in_array((int) $member['monday_id'], $assignedMondayIds, true)) {
                    continue;
                }
                $targets[] = [
                    'id'         => (int) $member['id'],
                    'name'       => (string) $member['name'],
                    'email'      => (string) $member['email'],
                    'region'     => $member['region'],
                    'assignable' => $member['assignable'],
                ];
            }
        }

        $this->transferTargets = array_values($targets);
    }

    /**
     * Toggle the branch scope of the transfer modal (same branch /
     * all branches). The previous selection may not exist in the new
     * list, so it is reset.
     */
    public function setTransferScope(string $scope): void
    {
        if (! in_array($scope, ['same', 'all'], true) || $scope === $this->transferScope) {
            return;
        }

        $this->transferScope    = $scope;
        $this->transferToUserId = null;
        $this->loadTransferTargets();
    }

    /**
     * Close the transfer modal without sending a request.
     */
    public function cancelTransfer(): void
    {
        $this->transferTicketId   = null;
        $this->transferTicketName = null;
        $this->transferTargets    = [];
        $this->transferToUserId   = null;
        $this->transferring     = false;

        // The modal's Alpine handler removes the body scroll lock on
        // this event; dispatching from the server guarantees the lock
        // is released even when the modal disappears mid-round-trip
        // (e.g. after a successful requestTransfer).
        $this->dispatch('close-transfer-modal');
    }

    /**
     * Send a transfer request to the selected TSP. Creates a PENDING
     * TicketTransfer row and notifies the target via the region.all
     * Pusher channel (the target's 20s poll is the fallback). Nothing
     * is written to Monday.com yet — the People column only changes
     * when the target ACCEPTS.
     */
    public function requestTransfer(MondayClient $monday): void
    {
        if ($this->transferring) {
            return;
        }
        $user = auth()->user();
        $id   = $this->transferTicketId;
        if ($id === null || $this->transferToUserId === null) {
            return;
        }

        $target = User::query()
            ->where('id', $this->transferToUserId)
            ->whereIn('role', ['fse', 'its'])
            ->whereNotNull('monday_id')
            ->first(['id', 'name', 'monday_id']);
        if (! $target) {
            $this->dispatch('toast', type: 'error', title: 'Not available', body: 'That TSP is no longer available for transfers.');
            return;
        }

        // One pending request per (ticket, target) — re-sending to
        // the same TSP is a no-op instead of a stack of duplicates.
        $existing = TicketTransfer::query()
            ->where('monday_ticket_id', $id)
            ->where('to_user_id', $target->id)
            ->where('status', TicketTransfer::STATUS_PENDING)
            ->exists();
        if ($existing) {
            $this->dispatch('toast', type: 'info', title: 'Already requested', body: "You already have a pending transfer request to {$target->name} for ticket #{$id}.");
            $this->cancelTransfer();
            return;
        }

        $this->transferring = true;

        try {
            $transfer = TicketTransfer::create([
                'monday_ticket_id' => $id,
                'from_user_id'     => $user->id,
                'to_user_id'       => $target->id,
                'status'           => TicketTransfer::STATUS_PENDING,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Dashboard::requestTransfer failed to persist', [
                'ticket_id' => $id,
                'to_user'   => $target->id,
                'error'     => $e->getMessage(),
            ]);
            $this->transferring = false;
            $this->dispatch('toast', type: 'error', title: 'Could not request', body: 'Something went wrong saving the request. Please try again.');
            return;
        }

        $this->cancelTransfer();

        // Notify the target through the shared TSP channel. Their
        // dashboard shows the "Incoming transfer requests" card.
        event(new TicketTransferRequested(
            mondayTicketId: $id,
            fromUserId:     (int) $user->id,
            fromName:       (string) $user->name,
            toUserId:       (int) $target->id,
            toName:         (string) $target->name,
        ));

        $this->loadMyPendingTransfers();

        $this->dispatch('toast', type: 'success', title: 'Transfer requested', body: "{$target->name} needs to accept ticket #{$id} before it moves. They'll see the request on their dashboard.");
    }

    /**
     * Cancel one of my own pending transfer requests. The assignment
     * never changed on Monday, so this is a local state change only.
     */
    public function cancelPendingTransfer(int $transferId): void
    {
        $transfer = TicketTransfer::query()
            ->where('id', $transferId)
            ->where('from_user_id', auth()->id())
            ->where('status', TicketTransfer::STATUS_PENDING)
            ->first();

        if (! $transfer) {
            $this->dispatch('toast', type: 'error', title: 'Not found', body: 'That request is no longer pending.');
            return;
        }

        $transfer->update([
            'status'      => TicketTransfer::STATUS_CANCELLED,
            'resolved_at' => now(),
        ]);

        $this->loadMyPendingTransfers();

        $this->dispatch('toast', type: 'success', title: 'Request cancelled', body: "Ticket #{$transfer->monday_ticket_id} stays with you.");
    }

    /**
     * Accept an incoming transfer request. This is the ONLY action
     * that touches Monday.com: the People column is rewritten to
     * remove the original assignee and add the accepting TSP, then
     * both dashboards refresh (the old holder loses the ticket, the
     * new holder gains it).
     *
     * Guarded server-side: only the target TSP (to_user_id) can
     * accept their own pending request.
     */
    public function acceptTransfer(int $transferId, MondayClient $monday): void
    {
        $user = auth()->user();

        $transfer = TicketTransfer::query()
            ->where('id', $transferId)
            ->where('to_user_id', $user->id)
            ->where('status', TicketTransfer::STATUS_PENDING)
            ->first();

        if (! $transfer) {
            $this->dispatch('toast', type: 'error', title: 'Not found', body: 'That transfer request is no longer pending.');
            return;
        }

        if (empty($user->monday_id) || empty($transfer->fromUser->monday_id)) {
            $this->dispatch('toast', type: 'error', title: 'Missing Monday ID', body: 'The transfer can\'t complete — one of the accounts is missing a Monday person ID.');
            return;
        }

        $this->transferring = true;

        try {
            $monday->reassignTicket(
                (int) $transfer->monday_ticket_id,
                (string) $transfer->fromUser->monday_id,
                (string) $user->monday_id,
            );
        } catch (\Throwable $e) {
            Log::warning('Dashboard::acceptTransfer failed on Monday', [
                'ticket_id'   => $transfer->monday_ticket_id,
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
            ]);
            $this->transferring = false;
            $this->dispatch('toast', type: 'error', title: 'Could not transfer', body: 'Monday.com returned an error. Please try again.');
            return;
        }

        $transfer->update([
            'status'      => TicketTransfer::STATUS_ACCEPTED,
            'resolved_at' => now(),
        ]);

        // Bust the board-items cache so loadLists() fetches the new
        // People column immediately (same pattern as claim()).
        $monday->forgetBoardCache();

        $this->transferring = false;

        // Tell every TSP dashboard to refresh: the old holder's list
        // drops the ticket, the new holder's list picks it up.
        event(new TicketTransferred(
            mondayTicketId: $transfer->monday_ticket_id,
            fromUserId:     (int) $transfer->from_user_id,
            toUserId:       (int) $user->id,
            toName:         (string) $user->name,
        ));

        $this->loadLists($monday);

        $this->dispatch('toast', type: 'success', title: 'Transfer accepted', body: "Ticket #{$transfer->monday_ticket_id} is now assigned to you.");
    }

    /**
     * Decline an incoming transfer request. The assignment stays with
     * the original TSP (nothing is written to Monday) and the
     * requester sees the request no longer on their row.
     */
    public function declineTransfer(int $transferId): void
    {
        $transfer = TicketTransfer::query()
            ->where('id', $transferId)
            ->where('to_user_id', auth()->id())
            ->where('status', TicketTransfer::STATUS_PENDING)
            ->first();

        if (! $transfer) {
            $this->dispatch('toast', type: 'error', title: 'Not found', body: 'That transfer request is no longer pending.');
            return;
        }

        $transfer->update([
            'status'      => TicketTransfer::STATUS_DECLINED,
            'resolved_at' => now(),
        ]);

        $this->loadIncomingTransfers(app(MondayClient::class));

        $this->dispatch('toast', type: 'success', title: 'Request declined', body: "Ticket #{$transfer->monday_ticket_id} stays with the current TSP.");
    }

    /**
     * A transfer request was broadcast (new request for some TSP).
     * Reload the request card so the target sees it without waiting
     * for the 20s poll.
     */
    #[On('transfer.requested')]
    public function handleTransferRequested(MondayClient $monday): void
    {
        $this->loadIncomingTransfers($monday);
    }

    /**
     * A transfer was accepted elsewhere. Reload everything so the
     * old holder's "My tickets" drops the ticket and the new
     * holder's list picks it up.
     */
    #[On('ticket.transferred')]
    public function handleTicketTransferred(array $payload, MondayClient $monday): void
    {
        $toId = (int) ($payload['to_user_id'] ?? 0);

        if ($toId === (int) auth()->id()) {
            // This TSP just received the ticket via an accept that
            // happened on ANOTHER TSP's session.
            $monday->forgetBoardCache();
            $this->loadLists($monday);
            $ticketId = (string) ($payload['monday_ticket_id'] ?? '');
            $this->dispatch('toast', type: 'info', title: 'Ticket transferred to you', body: "Ticket #{$ticketId} was transferred to you by another TSP's action.");
            return;
        }

        // Someone else's handoff — a light refresh is enough to drop
        // the ticket from this TSP's list if it was theirs.
        $this->loadLists($monday);
    }

    /**
     * Refresh both lists from Monday (no claim). Useful as a
     * "Refresh" affordance or called after a long-lived session
     * where the cache might be stale.
     */
    public function refresh(MondayClient $monday): void
    {
        $this->loadLists($monday);
    }

    /**
     * Lightweight poll target. Called every ~20s by
     * `wire:poll.20s` on the root dashboard div. Same as
     * refresh() but skips the optimistic-UI state so a poll
     * never stomps a claim that's mid-flight.
     *
     * The poll only fires when the tab is visible (Livewire's
     * `wire:poll.keep-alive` keeps the timer alive; the
     * `wire:poll` directive itself is pause-aware via the
     * `poll.keep-alive` modifier).
     *
     * Cost: one Monday round-trip per poll. At 20s cadence and
     * a typical dashboard session of ~10 minutes, that's ~30
     * requests — well within the Monday per-minute budget. If
     * the budget gets tight, swap to `poll.30s`.
     */
    public function pollRefresh(MondayClient $monday): void
    {
        if ($this->claiming) {
            return; // don't yank a claim out from under the user
        }
        $this->loadLists($monday);
    }

    /**
     * Re-attempt the drainer for a single errored TSR row. Called
     * from the "Retry" button on each row in the "needs
     * attention" banner.
     *
     * This calls into the same SyncPendingTsrReports action as
     * the auto-drainer, which means it'll handle the relation-
     * strip fallback for archived tickets, the partial-success
     * guard, and the signature-upload recovery. The user just
     * sees: "I clicked Retry; if it works the row disappears
     * from the banner; if it doesn't, the new error message
     * shows up."
     */
    public function retrySync(int $id, SyncPendingTsrReports $drainer, MondayClient $monday): void
    {
        $user = auth()->user();
        $row = ServiceReport::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $row) {
            $this->dispatch('toast', type: 'error', title: 'Not found', body: "TSR #{$id} is no longer available.");
            $this->loadLists($monday);
            return;
        }

        // Only error rows are retriable from the banner. Pending
        // and syncing rows are already in flight.
        if ($row->sync_state !== SyncState::Error) {
            $this->dispatch('toast', type: 'info', title: 'Already in progress', body: "TSR #{$id} is no longer in the error state.");
            $this->loadLists($monday);
            return;
        }

        $result = $drainer->syncOneRow($row);
        $this->loadLists($monday);

        if (($result['succeeded'] ?? 0) > 0) {
            $this->dispatch('toast', type: 'success', title: 'Synced', body: "TSR #{$id} mirrored to Monday.");
        } else {
            // Reload the row to surface the new error message.
            $row->refresh();
            $msg = $row->sync_error ?: 'Unknown error.';
            $this->dispatch('toast', type: 'error', title: 'Still failing', body: substr($msg, 0, 140));
        }
    }

    /**
     * Mark an errored TSR as discarded. The row stays in the DB
     * for audit purposes but is hidden from the banner and
     * excluded from the drainer. This is the user-facing escape
     * hatch when the source ticket is in monday trash and the
     * row will never sync.
     */
    public function discardReport(int $id, MondayClient $monday): void
    {
        $user = auth()->user();
        $row = ServiceReport::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $row) {
            $this->dispatch('toast', type: 'error', title: 'Not found', body: "TSR #{$id} is no longer available.");
            $this->loadLists($monday);
            return;
        }

        $row->sync_state = SyncState::Discarded;
        $row->sync_error = sprintf(
            'Discarded by user (%s) on %s. Reason: no longer recoverable.',
            $user->email,
            now()->toDateTimeString()
        );
        $row->save();

        $this->loadLists($monday);
        $this->dispatch('toast', type: 'success', title: 'Discarded', body: "TSR #{$id} removed from the pending-sync list.");
    }

    /**
     * Bulk-retry all errored rows. The drainer handles each row
     * the same way as the auto-drainer, including the relation-
     * strip fallback. Rows that still fail stay in the error
     * state and the banner keeps showing them; rows that succeed
     * drop off the banner.
     */
    public function retryAll(SyncPendingTsrReports $drainer, MondayClient $monday): void
    {
        $user = auth()->user();
        $rows = ServiceReport::query()
            ->where('user_id', $user->id)
            ->where('sync_state', SyncState::Error->value)
            ->get();

        if ($rows->isEmpty()) {
            $this->dispatch('toast', type: 'info', title: 'Nothing to retry', body: 'No errored reports.');
            return;
        }

        $succeeded = 0;
        $failed    = 0;
        foreach ($rows as $r) {
            $res = $drainer->syncOneRow($r);
            $succeeded += $res['succeeded'] ?? 0;
            $failed    += $res['failed']    ?? 0;
        }

        $this->loadLists($monday);

        if ($failed === 0) {
            $this->dispatch('toast', type: 'success', title: 'All synced', body: "{$succeeded} report(s) mirrored to Monday.");
        } elseif ($succeeded === 0) {
            $this->dispatch('toast', type: 'error', title: 'Still failing', body: "None of the {$failed} report(s) could be synced. See the banner for details.");
        } else {
            $this->dispatch('toast', type: 'success', title: 'Partially synced', body: "{$succeeded} synced, {$failed} still failing.");
        }
    }

    /**
     * After a TSR is submitted on the ticket-detail page, the
     * ticket status on Monday changes. The TSP can come back to
     * the dashboard and the new state should be reflected. This
     * listener picks that up via the `tsr.synced` event the
     * offline-tsr.js script dispatches.
     */
    #[On('tsr.synced')]
    public function handleTsrSynced(MondayClient $monday): void
    {
        $this->loadLists($monday);
    }

    /**
     * Dispatched by `echo.js` when a `ticket.created` Pusher event
     * lands on `region.<tspRegion>` or `region.all`. Triggers a
     * dashboard refresh so the new ticket appears in the
     * Available pool without waiting for the 20s poll. We also
     * fire a toast so the TSP notices the new work immediately.
     */
    #[On('ticket.created')]
    public function handleTicketCreated(array $payload, MondayClient $monday): void
    {
        $this->loadLists($monday);

        $subject = (string) ($payload['subject'] ?? 'New ticket');
        $id      = (string) ($payload['monday_ticket_id'] ?? '');
        $this->dispatch(
            'toast',
            type:  'info',
            title: 'New ticket in your region',
            body:  $id ? "Ticket #{$id} — {$subject}" : $subject,
        );
    }

    /**
     * Dispatched by `echo.js` when a `ticket.claimed` event lands
     * on `region.all`. Used by TSPs viewing the regional pool —
     * the claimed ticket drops out of Available the moment the
     * other TSP claims it, so two TSPs never race to claim the
     * same ticket.
     */
    #[On('ticket.claimed')]
    public function handleTicketClaimed(array $payload, MondayClient $monday): void
    {
        $claimedId = (string) ($payload['monday_ticket_id'] ?? '');
        if ($claimedId === '') {
            return;
        }
        // Bust the board cache so loadLists() fetches fresh
        // data — the broadcast means state changed on Monday.
        $monday->forgetBoardCache();

        // Drop the just-claimed ticket from the available pool
        // before re-loading from Monday, so a race condition
        // (e.g. another TSP claims between our last poll and now)
        // is repaired immediately.
        $this->availableTickets = array_values(array_filter(
            $this->availableTickets,
            static fn (array $t) => (string) ($t['id'] ?? '') !== $claimedId,
        ));
        $this->loadLists($monday);
    }

    /**
     * Build a minimal ticket payload to prepend to `myTickets`
     * after a successful claim. We don't have the full item —
     * we only know the id and the current display subject from
     * the row we just removed. A real fresh load is safer, so
     * callers should prefer a full refresh when possible. This
     * method is a best-effort optimistic-UI helper.
     *
     * @param  string  $id
     * @param  array<int, array>  $existing
     * @return array<int, array>
     */
    protected function buildClaimedTickets(string $id, array $existing): array
    {
        // The optimistic-UI path: find the just-claimed ticket in
        // the previous available list and copy its full payload
        // into myTickets. If we can't find it (e.g. the page was
        // reloaded and state was lost), fall back to a stub.
        $claimed = null;
        foreach ($this->availableTickets as $t) {
            if ((string) $t['id'] === $id) {
                $claimed = $t;
                break;
            }
        }
        if (! $claimed) {
            // Try a local query first, then a Monday fetch.
            // Include `subject_text` and `account_name` (even if
            // empty) so the view's `?:` Elvis operator doesn't
            // error on an undefined key in PHP 8.1+.
            $claimed = [
                'id'           => $id,
                'status_text'  => 'AWAITING',
                'name'         => "Ticket #{$id}",
                'subject_text' => null,
                'account_name' => null,
                'tsp_person_ids' => [],
                'item'         => ['column_values' => []],
            ];
        } else {
            // claimTicket() flipped status95 to "AWAITING" on Monday;
            // the copy we lifted from the available pool still carries
            // the pre-claim status (e.g. "OPEN"). Overwrite it so the
            // optimistic stats bucket this ticket under Awaiting right
            // away instead of waiting for the next loadLists() poll.
            $claimed['status_text'] = 'AWAITING';
        }
        // Mark the ticket as "claimed just now" — annotate the
        // list. We don't actually need this in the view, but it
        // helps with debugging during dev.
        $claimed['_just_claimed'] = true;
        array_unshift($existing, $claimed);
        return array_values($existing);
    }

    /**
     * Re-derive the stats counters from the current myTickets
     * list. Called after a claim, which mutates the list.
     */
    protected function recomputeStats(): void
    {
        // Only the ticket-derived counters change here. The
        // pending-sync buckets (pending_count, error_count,
        // pending_sync) are kept from the previous loadLists() run
        // because a claim doesn't touch the local service_reports
        // table. They'll be refreshed next time loadLists() runs
        // (e.g. on the next poll tick).
        $stats = [
            'total'         => 0,
            'open'          => 0,
            'in_progress'   => 0,
            'awaiting_parts'=> 0,
            'resolved'      => 0,
            'pending_sync'  => $this->stats['pending_sync']  ?? 0,
            'pending_count' => $this->stats['pending_count'] ?? 0,
            'error_count'   => $this->stats['error_count']   ?? 0,
        ];

        foreach ($this->myTickets as $t) {
            $stats['total']++;
            $status = strtolower((string) ($t['status_text'] ?? ''));
            if ($status === '') {
                continue;
            }
            // Same mutual-exclusive bucketing as loadLists() —
            // see the comment there for why each ticket goes into
            // exactly one bucket. (The in_progress card and the
            // Open card now add up cleanly without any
            // double-counting.)
            if (str_contains($status, 'resolved')
                || str_contains($status, 'closed')
                || str_contains($status, 'done')
                || str_contains($status, 'complete')
            ) {
                $stats['resolved']++;
            } elseif (str_contains($status, 'progress')) {
                $stats['in_progress']++;
            } elseif (str_contains($status, 'awaiting')) {
                $stats['awaiting_parts']++;
            } else {
                $stats['open']++;
            }
        }

        $this->stats = $stats;
    }

    // ---------------------------------------------------------------------
    // Filtering
    // ---------------------------------------------------------------------

    /**
     * Toggle a status bucket in/out of the filters.status array.
     * Called from the Blade dropdown menu items.
     */
    public function toggleStatusFilter(string $bucket): void
    {
        $idx = array_search($bucket, $this->filters['status'], true);
        if ($idx !== false) {
            unset($this->filters['status'][$idx]);
            $this->filters['status'] = array_values($this->filters['status']);
        } else {
            $this->filters['status'][] = $bucket;
        }
    }

    /**
     * Reset all filters to defaults. Called from the Clear button.
     */
    public function resetFilters(): void
    {
        $this->filters = ['query' => '', 'status' => [], 'sort' => 'newest'];
    }

    /**
     * Filtered view of $this->myTickets. Re-evaluated by
     * Livewire whenever myTickets or filters changes.
     *
     * @return array<int, array>
     */
    #[Computed]
    public function filteredMyTickets(): array
    {
        return $this->applyFilters($this->myTickets);
    }

    /**
     * Filtered view of $this->availableTickets. Same lifecycle
     * as filteredMyTickets.
     *
     * @return array<int, array>
     */
    #[Computed]
    public function filteredAvailable(): array
    {
        return $this->applyFilters($this->availableTickets, isAvailable: true);
    }

    /**
     * Shared filter/sort pipeline used by both computed methods.
     *
     * @param  array<int, array>  $list
     * @param  bool  $isAvailable  True for the Available pool (fewer ticket fields).
     * @return array<int, array>
     */
    private function applyFilters(array $list, bool $isAvailable = false): array
    {
        $f = $this->filters;

        // Text search — subject, name, ticket id, account name
        if (! empty($f['query'])) {
            $q = strtolower($f['query']);
            $list = array_values(array_filter($list, static function (array $t) use ($q) {
                $subject = strtolower((string) ($t['subject_text'] ?? $t['name'] ?? ''));
                $id      = (string) ($t['id'] ?? '');
                $account = strtolower((string) ($t['account_name'] ?? ''));
                return str_contains($subject, $q)
                    || str_contains($id, $q)
                    || str_contains($account, $q);
            }));
        }

        // Status bucket filter
        if (! empty($f['status'])) {
            $list = array_values(array_filter($list, static function (array $t) use ($f) {
                $s = strtolower((string) ($t['status_text'] ?? ''));
                foreach ($f['status'] as $bucket) {
                    if ($bucket === 'open'
                        && (str_contains($s, 'new') || str_contains($s, 'open') || ($s !== '' && $s !== '—'))
                    ) {
                        return true;
                    }
                    if ($bucket === 'in_progress' && str_contains($s, 'progress')) {
                        return true;
                    }
                    if ($bucket === 'awaiting' && str_contains($s, 'awaiting')) {
                        return true;
                    }
                    if ($bucket === 'resolved'
                        && (str_contains($s, 'resolved') || str_contains($s, 'closed')
                            || str_contains($s, 'done') || str_contains($s, 'complete'))
                    ) {
                        return true;
                    }
                }
                return false;
            }));
        }

        // Sort
        if ($f['sort'] === 'oldest') {
            $list = array_reverse($list);
        }

        return array_values($list);
    }

    /**
     * Resolve the name of a TSP from a Monday person id, falling
     * back to a stub. Used by the "My tickets" rows that show
     * "Assigned to: <name>" when the assignee isn't the current
     * viewer (e.g. on a co-owned queue). For the current user's
     * own queue the name will always be the current TSP, so the
     * UI hides the badge in that case.
     *
     * @return array<int, string>  Map of monday_person_id => name
     */
    #[Computed]
    public function tspNameMap(): array
    {
        $ids = [];
        foreach ($this->myTickets as $t) {
            foreach ($t['tsp_person_ids'] ?? [] as $id) {
                $ids[] = (string) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }
        return \App\Models\User::query()
            ->whereIn('monday_id', $ids)
            ->pluck('name', 'monday_id')
            ->map(static fn ($n) => (string) $n)
            ->toArray();
    }

    public function render(): View
    {
        return view('livewire.tsp.dashboard');
    }
}
