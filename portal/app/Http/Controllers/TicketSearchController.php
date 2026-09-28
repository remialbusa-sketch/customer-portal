<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssertsTicketAccess;
use App\Services\MondayClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Nav search quick-jump (Figma top nav "Search for tickets" box).
 *
 *   GET /tickets/go?q=…
 *
 * Behavior:
 *   - numeric q: load the ticket, authorize (existing trait), and go
 *     straight to the role-appropriate detail page;
 *   - text q: match accessible tickets by name/subject — exactly one
 *     match jumps, several redirect to the dashboard with ?q=
 *     prefilled, none bounces back with a "not found" error.
 *
 * Never 500s: unknown ids, unauthorized tickets, and Monday
 * outages all bounce back with a flash message.
 */
class TicketSearchController extends Controller
{
    use AssertsTicketAccess;

    public function go(Request $request, MondayClient $monday): RedirectResponse
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return back();
        }

        // Superadmins have no ticket detail page — search is a
        // customer/TSP tool.
        if ($user->role === 'superadmin') {
            return back()->withErrors([
                'search' => 'Ticket search is available on customer and TSP dashboards.',
            ]);
        }

        // Numeric: direct lookup + authorization. Any failure
        // (unknown id, no access, Monday outage) bounces back with
        // a flash message — never a 404/500 page. Note: abort(404)
        // inside loadMondayTicket throws an HttpException, not an
        // HttpResponseException, hence the broad catch.
        if (ctype_digit($q)) {
            try {
                $item = $this->loadMondayTicket($q);
                $this->authorizeTicketAccess($user, $item);
            } catch (\Throwable) {
                return back()->withErrors([
                    'search' => "No ticket found for '{$q}'.",
                ]);
            }

            return redirect()->route($this->showRoute($user->role), ['id' => $q]);
        }

        // Text: match within the tickets this role can access.
        try {
            $matches = $this->searchAccessible($user, $monday, $q);
        } catch (\Throwable) {
            return back()->withErrors([
                'search' => 'Ticket search is unavailable right now — please try again shortly.',
            ]);
        }

        if (count($matches) === 1) {
            return redirect()->route($this->showRoute($user->role), ['id' => $matches[0]['id']]);
        }

        if (count($matches) > 1) {
            return redirect()->route($this->dashboardRoute($user->role), ['q' => $q])
                ->with('status', count($matches) . " tickets match '{$q}' — filtered below.");
        }

        return back()->withErrors([
            'search' => "No ticket found for '{$q}'.",
        ]);
    }

    /**
     * @return array<int, array{id: string}>
     */
    protected function searchAccessible($user, MondayClient $monday, string $q): array
    {
        $needle = strtolower($q);
        $hit = static fn (array $t): bool => str_contains(strtolower((string) ($t['name'] ?? '')), $needle)
            || str_contains(strtolower((string) ($t['subject_text'] ?? '')), $needle)
            || str_contains((string) ($t['id'] ?? ''), $needle);

        if ($user->role === 'customer') {
            return array_values(array_filter(
                $monday->ticketsForCustomer($user->email),
                $hit,
            ));
        }

        // TSP roles: my queue + regional pool (same sources as the
        // dashboard, so search never reveals tickets the pool hides).
        $mine = [];
        if (! empty($user->monday_id)) {
            $mine = $monday->ticketsForTsp((string) $user->monday_id);
        }
        $region = \App\Support\RegionResolver::normalizeRegionCode($user->region)
            ?? \App\Support\RegionResolver::resolveForCustomer($user);
        $pool = $region ? $monday->unclaimedTicketsForRegion($region) : [];

        $seen = [];
        $out = [];
        foreach (array_merge($mine, $pool) as $t) {
            $id = (string) ($t['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ($hit($t)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    protected function showRoute(string $role): string
    {
        return $role === 'customer' ? 'tickets.show' : 'tsp.tickets.show';
    }

    protected function dashboardRoute(string $role): string
    {
        return $role === 'customer' ? 'dashboard' : 'tsp.dashboard';
    }
}
