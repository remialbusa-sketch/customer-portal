<?php

namespace App\Http\Controllers\Tsp;

use App\Events\MessageSent;
use App\Http\Controllers\Concerns\AssertsTicketAccess;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\MondayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChatController extends Controller
{
    use AssertsTicketAccess;

    /**
     * Show the TSP ticket detail (read-only ticket info + customer chat panel).
     * Internal notes + time tracker land in Phase 4 / 5.
     */
    public function show(string $id): View
    {
        $user = auth()->user();
        $item = $this->loadMondayTicket($id);
        $this->authorizeTicketAccess($user, $item);

        // Seed the session cache for the polling endpoint. The TSP
        // access path itself is cheap (no Monday round-trip in the
        // cached branch), but caching the fact "this user is on
        // this ticket" still avoids a re-check on every poll tick.
        session()->put("chat-access:{$user->id}:{$id}", true);

        $messages = $this->loadMessageHistory($id, $user);

        return view('tsp.ticket-show', [
            'user'     => $user,
            'ticket'   => $item,
            'messages' => $messages,
        ]);
    }

    /**
     * Polling endpoint — see Customer\ChatController::poll for the
     * full design rationale. The TSP-side check is much cheaper
     * than the customer side: any TSP/admin/fse/its/manager who
     * loaded the page already passed `authorizeTicketAccess()`,
     * so the poll only needs to make sure they're still that role
     * and the ticket id is well-formed. We do still go through
     * the access check (cheap — no Monday round-trip because the
     * user already has fse/its/manager/admin, which short-circuits
     * early) so a stale tab can never poll a ticket that the user
     * has since lost access to.
     */
    public function poll(Request $request, string $id): JsonResponse
    {
        $user = auth()->user();
        $this->authorizeTicketAccess($user, ['id' => $id, 'column_values' => []]);

        $since = (int) $request->query('since', 0);
        $since = max(0, $since);

        $messages = ChatMessage::with('user')
            ->where('monday_ticket_id', $id)
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(function (ChatMessage $msg) use ($user) {
                return [
                    'id'          => (int) $msg->id,
                    'body'        => (string) $msg->body,
                    'sender_role' => (string) $msg->sender_role,
                    'sender_name' => (string) ($msg->user?->name ?? 'Unknown'),
                    'mine'        => (int) $msg->user_id === (int) $user->id,
                    'created_at'  => optional($msg->created_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'messages'  => $messages,
            'max_id'    => (int) (ChatMessage::where('monday_ticket_id', $id)->max('id') ?? 0),
            'server_ts' => now()->toIso8601String(),
        ]);
    }

    /**
     * Persist a new TSP chat message and broadcast it. Returns JSON
     * (no redirect) so Livewire doesn't re-render the whole page —
     * see Customer\ChatController::send for the full rationale.
     */
    public function send(Request $request, string $id, MondayClient $monday): JsonResponse
    {
        $user = auth()->user();
        $item = $this->loadMondayTicket($id);
        $this->authorizeTicketAccess($user, $item);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message = ChatMessage::create([
            'monday_ticket_id' => $id,
            'user_id'          => $user->id,
            'sender_role'      => $user->role,
            'body'             => $data['body'],
        ]);

        // Flip response_status from "NOT YET" → "RESPONDED" on
        // the TSP's first chat message. Idempotent — Monday
        // accepts the same label repeatedly.
        $monday->markTicketResponded((int) $id);

        $message->load('user');
        broadcast(new MessageSent($message))->toOthers();

        return response()->json([
            'ok'   => true,
            'id'   => (int) $message->id,
            'body' => (string) $message->body,
            'at'   => $message->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Load the full chat history for a ticket as a plain array — see
     * Customer\ChatController::loadMessageHistory for the shared
     * shape contract.
     */
    protected function loadMessageHistory(string $mondayTicketId, User $viewer): array
    {
        return ChatMessage::with('user')
            ->where('monday_ticket_id', $mondayTicketId)
            ->orderBy('created_at')
            ->get()
            ->map(function (ChatMessage $msg) use ($viewer) {
                return [
                    'id'          => (int) $msg->id,
                    'body'        => (string) $msg->body,
                    'sender_role' => (string) $msg->sender_role,
                    'sender_name' => (string) ($msg->user?->name ?? 'Unknown'),
                    'mine'        => (int) $msg->user_id === (int) $viewer->id,
                    'created_at'  => optional($msg->created_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
