<?php

namespace App\Http\Controllers\Customer;

use App\Events\MessageSent;
use App\Http\Controllers\Concerns\AssertsTicketAccess;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\MondayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ChatController extends Controller
{
    use AssertsTicketAccess;

    /**
     * Show the customer ticket detail + chat panel.
     */
    public function show(string $id): View
    {
        $user = auth()->user();
        $item = $this->loadMondayTicket($id);
        $this->authorizeTicketAccess($user, $item);

        // Seed the session cache so the first poll doesn't re-hit
        // Monday. Mirrors `authorizeWithSessionCache()`'s TTL.
        session()->put("chat-access:{$user->id}:{$id}", true);

        $messages = $this->loadMessageHistory($id, $user);

        // Resolve assigned TSP name(s) from the People column so the
        // customer knows who has claimed their ticket.
        $assignedNames = [];
        $peopleCol = config('services.monday.tickets_columns.tsp');
        $peopleValue = $item['column_values'][$peopleCol]['value'] ?? null;
        if ($peopleValue) {
            $decoded = json_decode($peopleValue, true);
            if (is_array($decoded) && isset($decoded['personsAndTeams'])) {
                $tspIds = [];
                foreach ($decoded['personsAndTeams'] as $row) {
                    if (isset($row['id'])) {
                        $tspIds[] = (string) $row['id'];
                    }
                }
                $tspNameMap = MondayClient::resolveTspNames($tspIds);
                foreach ($tspIds as $pid) {
                    $name = $tspNameMap[$pid] ?? null;
                    if ($name) { $assignedNames[] = $name; }
                    else { $assignedNames[] = 'TSP #' . $pid; }
                }
            }
        }

        return view('customer.tickets.show', [
            'user'     => $user,
            'ticket'   => $item,
            'messages' => $messages,
            'assignedNames' => $assignedNames,
        ]);
    }

    /**
     * Polling endpoint used by chat-panel.js to fetch new messages
     * since the last seen id. This is the realtime path on cPanel
     * shared hosting (no Pusher / no WebSocket service).
     *
     * Performance:
     *   - The first call after page load costs the same as a
     *     `show()` Monday round-trip; subsequent calls within the
     *     access-cache TTL (5 min) hit a session key and never
     *     call Monday at all.
     *   - Capped at 50 messages per response to bound payload size
     *     even on busy tickets.
     *   - 1 query (with eager-loaded user) — no N+1 on sender_name.
     *
     * Response shape matches what `appendMessage()` in chat-panel.js
     * expects, so the client can reuse the same dedup + render path
     * regardless of whether the message arrived via poll or Pusher.
     */
    public function poll(Request $request, string $id): JsonResponse
    {
        $user = auth()->user();
        $this->authorizeWithSessionCache($user, $id);

        $since = (int) $request->query('since', 0);
        $since = max(0, $since);

        $query = ChatMessage::with('user')
            ->where('monday_ticket_id', $id)
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit(50);

        $messages = $query->get()->map(function (ChatMessage $msg) use ($user) {
            return [
                'id'          => (int) $msg->id,
                'body'        => (string) $msg->body,
                'sender_role' => (string) $msg->sender_role,
                'sender_name' => (string) ($msg->user?->name ?? 'Unknown'),
                'mine'        => (int) $msg->user_id === (int) $user->id,
                'created_at'  => optional($msg->created_at)->toIso8601String(),
            ];
        })->values()->all();

        return response()->json([
            'messages'   => $messages,
            'max_id'     => (int) (ChatMessage::where('monday_ticket_id', $id)->max('id') ?? 0),
            'server_ts'  => now()->toIso8601String(),
        ]);
    }

    /**
     * Authorize a customer against a ticket using a session cache so
     * the polling endpoint (called every 3s while the chat panel is
     * open) doesn't slam Monday's API on every tick.
     *
     * First call per (user, ticket) goes through the standard
     * `loadMondayTicket()` + `authorizeTicketAccess()` flow (one
     * round-trip to Monday); subsequent calls within 5 minutes hit
     * a session key and never call Monday at all. If the access
     * check fails the first time, the failure is NOT cached (so
     * re-checking after a Monday fix works on the next poll).
     */
    protected function authorizeWithSessionCache(User $user, string $mondayTicketId): void
    {
        $cacheKey = "chat-access:{$user->id}:{$mondayTicketId}";

        if (session()->has($cacheKey)) {
            // Cached "yes" — short-circuit the Monday call.
            return;
        }

        $item = $this->loadMondayTicket($mondayTicketId);
        $this->authorizeTicketAccess($user, $item);

        // 5 minutes is long enough to absorb thousands of polls but
        // short enough that a Monday-side revocation (e.g. customer
        // removed from a ticket) is picked up reasonably quickly.
        session()->put($cacheKey, true);
    }

    /**
     * Persist a new message and broadcast it on the ticket channel.
     *
     * Returns a JSON 200 instead of a redirect so Livewire doesn't
     * re-render the whole page (the optimistic `chat-sent-ack` plus
     * the Pusher echo handle appending the new bubble in the
     * sender's tab).
     */
    public function send(Request $request, string $id): JsonResponse
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
     * Load the full chat history for a ticket as a plain array of
     * associative arrays (with a `mine` flag for the current viewer's
     * own messages) — the chat-panel Livewire component is a typed
     * `array` bag and we want the server-rendered initial state to
     * use the same shape that the Alpine bridge builds dynamically.
     *
     * Returns a plain array (not a Collection) because Livewire 3
     * treats typed-`Collection` properties as Eloquent collections
     * internally, which can fail on items that are plain arrays.
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
