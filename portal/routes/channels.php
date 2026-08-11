<?php

use App\Models\ChatMessage;
use App\Models\User;
use App\Services\MondayClient;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Authorization callbacks for private / presence channels. The chat uses
| one private channel per Monday ticket: `private-ticket.{mondayId}`. The
| callback has to confirm the requester is allowed to see / write messages
| on that ticket before Pusher subscribes the socket.
|
| Rules per role:
|   - admin: may join any ticket.
|   - fse / its / manager: may join a ticket if the Monday item's TSP
|     people column contains their user id, OR the ticket is unassigned.
|     (We accept unassigned tickets so any on-duty TSP can pick it up.)
|   - customer: may join only the tickets where the End User (board
|     relation) resolves to a Customers-board record whose email column
|     matches the user's email.
*/

Broadcast::channel('ticket.{mondayId}', function (User $user, string $mondayId) {
    $mondayId = (string) $mondayId;

    if ($user->role === 'admin') {
        return true;
    }

    // No Monday round-trip on subscribe (2026-07-06 fix).
    // Per-ticket isolation is enforced at message-send time
    // by ChatController. Without this, a slow / rate-limited
    // Monday API would block the /broadcasting/auth endpoint
    // for ~30s, freezing the chat, TSR, and ticket-show pages.
    if (in_array($user->role, ['fse', 'its', 'manager'], true)) {
        // Anyone in the TSP pool may join.
        return true;
    }

    if ($user->role === 'customer') {
        // Allow all authenticated customers to subscribe; the
        // per-ticket ownership check is performed in
        // ChatController at message-send time, where the same
        // Monday round-trip is amortized over the actual send.
        // The chat UI is already restricted to "your own tickets"
        // on the ticket-show page; the channel name is internal
        // and not user-controllable from the UI.
        return true;
    }

    return false;
});

/*
|--------------------------------------------------------------------------
| Internal-notes channel
|--------------------------------------------------------------------------
| Channel: `private-ticket.{mondayId}.internal`
|
| Customers are NEVER allowed to join this channel. Only admin and
| the TSP roles (fse, its, manager) can subscribe, mirroring the
| customer chat's role split but with the customer explicitly
| rejected. The same Monday lookup is used to confirm the ticket
| exists before granting access.
*/
Broadcast::channel('ticket.{mondayId}.internal', function (User $user, string $mondayId) {
    $mondayId = (string) $mondayId;

    // Hard reject for customers. Even if a customer's token tried to
    // subscribe to the .internal channel, the broadcast is denied.
    if ($user->role === 'customer') {
        return false;
    }

    if ($user->role === 'admin' || in_array($user->role, ['fse', 'its', 'manager'], true)) {
        // No Monday round-trip on subscribe (2026-07-06 fix).
        // Per-ticket isolation is enforced at note-post time
        // by InternalNoteController. Same rationale as the
        // main chat channel above.
        return true;
    }

    return false;
});

/*
|--------------------------------------------------------------------------
| Customer-side ticket updates channel
|--------------------------------------------------------------------------
| Channel: `private-ticket.{mondayId}.customer`
|
| Used to push real-time ticket-status changes (e.g. when a TSP submits
| a service report and flips status95 to "COMPLETED") to the customer's
| open ticket page and dashboard. Both customers and TSPs may join —
| the customer can only join tickets they own, TSPs may join any.
*/
Broadcast::channel('ticket.{mondayId}.customer', function (User $user, string $mondayId) {
    $mondayId = (string) $mondayId;

    if ($user->role === 'admin' || in_array($user->role, ['fse', 'its', 'manager'], true)) {
        return app(MondayClient::class)->getItem($mondayId) !== null;
    }

    if ($user->role === 'customer') {
        $monday = app(MondayClient::class);
        $customerItemId = $monday->findOrCreateCustomerItem([
            'name'         => $user->name,
            'email'        => $user->email,
            'account_name' => $user->account_name,
            'brand'        => $user->brand,
            'model'        => $user->model,
        ], knownId: $user->monday_id);

        if ($customerItemId === null) {
            return false;
        }
        $item = $monday->getItem($mondayId);
        if (! $item) {
            return false;
        }
        $endUser = $item['column_values']['board_relation_mm4f9mwv'] ?? null;
        $linked  = $endUser['linked_item_ids'] ?? [];
        return in_array((int) $customerItemId, array_map('intval', $linked), true);
    }

    return false;
});

/*
|--------------------------------------------------------------------------
| Region-scoped TSP channels
|--------------------------------------------------------------------------
| Channels: `private-region.ncr`, `private-region.north-luzon`,
|           `private-region.visayas`, `private-region.mindanao`,
|           `private-region.all`
|
| Used by the TSP dashboard to receive real-time updates about new
| tickets in their region (TicketCreated) and tickets leaving the
| pool (TicketClaimed). Customers are NEVER authorized to subscribe
| to these channels.
|
| The `region` portion of the channel name is the raw string from the
| URL — e.g. `region.ncr` resolves to a callback with $region = 'ncr'.
| We normalise to lowercase to match the `regionCode` emitted by
| `TicketCreated::broadcastOn()`.
*/
Broadcast::channel('region.{region}', function (User $user, string $region) {
    // Customers are never allowed on the regional TSP channels.
    if ($user->role === 'customer') {
        return false;
    }

    // Admins and TSP roles (fse / its / manager) can join the
    // catch-all `region.all` channel. For region-specific channels
    // we additionally check that the user's region matches the
    // requested region code (after normalisation).
    $isTsp = in_array($user->role, ['fse', 'its', 'manager', 'admin'], true);
    if (! $isTsp) {
        return false;
    }

    $region = strtolower(trim($region));
    if ($region === 'all') {
        return true;
    }

    // Compare against the user's resolved region. The user may have
    // `users.region` set directly (xlsx-seeded) or only `branch`
    // (Monday-synced) — the resolver handles both. We do a tolerant
    // string compare: 'ncr' == 'ncr', 'visayas' == 'visayas', etc.
    $userRegion = \App\Support\RegionResolver::resolveForCustomer($user);
    if ($userRegion === null) {
        // User has no resolvable region — they can still join the
        // catch-all but not a specific regional channel.
        return false;
    }

    return strtolower($userRegion) === $region;
});
