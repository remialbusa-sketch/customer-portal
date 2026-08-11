<?php

namespace App\Http\Controllers\Tsp;

use App\Http\Controllers\Controller;
use App\Models\TicketAcknowledgement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Handles the "Acknowledge this ticket" flow that begins from a
 * TSP's email alert.
 *
 * Two endpoints:
 *
 *   - GET /tsp/tickets/{id}/acknowledge?user={u}&expires=...&signature=...
 *     (public, signed-only) — renders a confirmation page that
 *     asks the TSP to log in (if they aren't already) and confirm.
 *     If they're already signed in, the button is a one-click POST.
 *
 *   - POST /tsp/tickets/{id}/acknowledge  (auth required)
 *     Records a row in `ticket_acknowledgements` (idempotent on
 *     re-click) and redirects to the ticket detail page.
 *
 * Why a confirmation page rather than an immediate record?
 *   - Signed URLs are bearer tokens. If the URL is forwarded to a
 *     wrong person or intercepted, we'd silently record a wrong
 *     acknowledgement. Putting a human-in-the-loop "Yes, this is
 *     me" step in front of the write is the standard protection.
 *   - The page also handles the not-logged-in case cleanly: the
 *     TSP clicks the email link from their phone, lands on the
 *     confirmation page, signs in, and the same form submits.
 */
class TspTicketAcknowledgementController extends Controller
{
    /**
     * Show the confirmation page. This is the entry point hit by
     * the email link.
     *
     * The signed URL guarantees the {id, user, expires, signature}
     * tuple wasn't tampered with. We use the `signed` middleware
     * on the route so this method is only reached for valid
     * signatures.
     */
    public function show(Request $request, string $id): View
    {
        // The signed URL carries the target user as `?user=`. The
        // route signature only verifies id+expires+signature, so we
        // need to re-read user from query. If absent (e.g. an older
        // link) we infer it from the authed user when possible.
        $targetUserId = (int) $request->query('user', 0);
        $signedInUser = $request->user();

        // If the user isn't logged in yet, we still want to render
        // the page — it has a "Log in to acknowledge" CTA. If they
        // ARE logged in and the link targets someone else, that
        // means the URL leaked; we render a clear "wrong user"
        // page so the legitimate recipient can see what happened.
        $alreadyAcked = false;
        if ($signedInUser) {
            $effectiveUserId = $targetUserId > 0
                ? $targetUserId
                : $signedInUser->id;
            $alreadyAcked = TicketAcknowledgement::existsFor($id, $effectiveUserId);
        }

        $mismatchedUser = $targetUserId > 0
            && $signedInUser
            && $targetUserId !== (int) $signedInUser->id;

        return view('tsp.acknowledge', [
            'mondayTicketId' => $id,
            'targetUserId'   => $targetUserId,
            'signedInUser'   => $signedInUser,
            'alreadyAcked'   => $alreadyAcked,
            'mismatchedUser' => $mismatchedUser,
        ]);
    }

    /**
     * Record the acknowledgement. Requires auth — the user is the
     * authoritative identity, not the URL.
     */
    public function acknowledge(Request $request, string $id): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()
                ->route('login')
                ->with('status', 'Please sign in to acknowledge this ticket.');
        }

        // Honour the `user` query param if present, otherwise attribute
        // the ack to whoever is signed in. This means a manager who's
        // logged in and clicks the link can still ack on behalf of
        // the right person when the target user param is present.
        $targetUserId = (int) $request->query('user', $user->id);
        if ($targetUserId <= 0) {
            $targetUserId = (int) $user->id;
        }

        try {
            $ack = TicketAcknowledgement::recordIfNew(
                mondayTicketId: $id,
                userId:         $targetUserId,
                ip:             $request->ip(),
                userAgent:      (string) $request->userAgent(),
            );
        } catch (\Throwable $e) {
            Log::error('Ticket acknowledgement failed', [
                'monday_ticket_id' => $id,
                'user_id'          => $targetUserId,
                'error'            => $e->getMessage(),
            ]);
            return redirect()
                ->route('tsp.tickets.show', ['id' => $id])
                ->with('error', 'Could not record acknowledgement. Please try again.');
        }

        $wasNew = $ack->wasRecentlyCreated;

        return redirect()
            ->route('tsp.tickets.show', ['id' => $id])
            ->with(
                'status',
                $wasNew
                    ? 'Acknowledgement recorded. The customer will see you picked it up.'
                    : 'You already acknowledged this ticket — no change made.'
            );
    }
}
