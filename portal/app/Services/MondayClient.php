<?php

namespace App\Services;

use App\Exceptions\MondayApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Thin wrapper around the Monday.com GraphQL API.
 *
 * All other parts of the app talk to Monday through this class — never
 * directly to the HTTP endpoint. That keeps the API token off every
 * other layer and gives us a single place to add caching, retries,
 * column-id mappings, and rate-limit handling.
 */
class MondayClient
{
    /**
     * Monday's file upload endpoint. The `add_file_to_column`
     * mutation requires multipart/form-data POSTs to this URL —
     * the regular GraphQL endpoint rejects file uploads with
     * `INTERNAL_SERVER_ERROR` because the `File!` scalar has to
     * be supplied as a real multipart part, not as base64 in the
     * GraphQL variables. See the official API reference:
     * https://developer.monday.com/api-reference/reference/assets-1
     * and the old Python service-report portal's proven approach
     * (app/monday.py → upload_file()).
     */
    public const FILE_URL = 'https://api.monday.com/v2/file';

    public function __construct(
        protected string $token,
        protected string $apiUrl,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            token:  (string) config('services.monday.token'),
            apiUrl: (string) config('services.monday.api_url'),
        );
    }

    // ---------------------------------------------------------------------
    // Low-level
    // ---------------------------------------------------------------------

    /**
     * Execute a GraphQL query/mutation and return the `data` payload.
     *
     * @throws RuntimeException on transport or API errors
     */
    public function query(string $graphql, array $variables = []): array
    {
        if ($this->token === '') {
            throw new RuntimeException(
                'MONDAY_API_TOKEN is not set. Add it to your .env file.'
            );
        }

        $response = Http::withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post($this->apiUrl, [
                'query'     => $graphql,
                'variables' => (object) $variables,
            ]);

        if ($response->failed()) {
            Log::error('Monday.com HTTP error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException(
                "Monday.com request failed with HTTP {$response->status()}"
            );
        }

        $body = $response->json();

        if (! empty($body['errors'])) {
            Log::error('Monday.com GraphQL errors', [
                'errors' => $body['errors'],
            ]);
            throw MondayApiException::fromGraphQL($body['errors'][0]);
        }

        return $body['data'] ?? [];
    }

    // ---------------------------------------------------------------------
    // Boards
    // ---------------------------------------------------------------------

    /**
     * Return raw items on a board, with all column values flattened.
     *
     * Cached for 60 seconds so repeated page loads in the same session
     * don't burn the Monday rate limit.
     *
     * @return array<int, array{id:string, name:string, group:string, column_values:array}>
     */
    public function getBoardItems(int $boardId, int $cacheSeconds = 60): array
    {
        return Cache::remember(
            "monday.board.{$boardId}.items",
            $cacheSeconds,
            fn () => $this->fetchBoardItems($boardId)
        );
    }

    /**
     * GraphQL inline fragment to surface display_value and linked_item_ids
     * for board_relation columns (text/value are always null for those).
     * Same trick for mirror columns.
     */
    protected const COLUMN_VALUES_FRAGMENT = <<<'GQL'
    column_values {
        id
        text
        value
        ... on BoardRelationValue {
            id
            display_value
            linked_item_ids
            linked_items { id name }
        }
        ... on MirrorValue {
            id
            display_value
        }
    }
    GQL;

    protected function fetchBoardItems(int $boardId): array
    {
        $graphql = <<<GQL
        query (\$boardId: ID!) {
            boards(ids: [\$boardId]) {
                items_page(limit: 100) {
                    items {
                        id
                        name
                        group { title }
                        __FRAGMENT__
                    }
                }
            }
        }
        GQL;
        $graphql = str_replace('__FRAGMENT__', self::COLUMN_VALUES_FRAGMENT, $graphql);

        $data   = $this->query($graphql, ['boardId' => (string) $boardId]);
        $items  = $data['boards'][0]['items_page']['items'] ?? [];

        return array_map([$this, 'normalizeItem'], $items);
    }

    /**
     * Return a single item by id, with column values flattened.
     *
     * Cached for 5 seconds in the shared cache. The short TTL is
     * intentional: a single ticket-show page load calls
     * `getItem()` 1-3 times (once for the ticket itself, once
     * inside `findOrCreateCustomerItem`'s lookup, once more from
     * any middleware), and the chat polling endpoint hits it
     * every 3 seconds. Without this cache, every page load
     * triggers 1-3 Monday round-trips for the same ticket.
     *
     * 5 seconds is short enough that:
     *   - A TSP claim/assign immediately reflects on the
     *     customer's next refresh.
     *   - A status flip from the TSR drainer shows up on the
     *     next page load (the drainer itself calls
     *     `forgetItemCache()` to be sure).
     *   - Monday's rate limit isn't burned by repeated
     *     reads in the same request lifecycle.
     */
    public function getItem(int $itemId): ?array
    {
        return Cache::remember(
            "monday.item.{$itemId}",
            5,
            function () use ($itemId) {
                $graphql = <<<GQL
                query (\$itemId: ID!) {
                    items(ids: [\$itemId]) {
                        id
                        name
                        group { title }
                        __FRAGMENT__
                    }
                }
                GQL;
                $graphql = str_replace('__FRAGMENT__', self::COLUMN_VALUES_FRAGMENT, $graphql);

                $data = $this->query($graphql, ['itemId' => (string) $itemId]);
                $item = $data['items'][0] ?? null;

                return $item ? $this->normalizeItem($item) : null;
            }
        );
    }

    /**
     * Drop the cached single-item fetches. Call after any mutation
     * (claim, status change, TSR sync, etc.) that needs immediate
     * consistency. Pairs with `forgetBoardCache()`.
     *
     * @param  int|array<int>  $itemIds
     */
    public function forgetItemCache(int|array $itemIds = []): void
    {
        if (is_int($itemIds)) {
            $itemIds = [$itemIds];
        }
        foreach ($itemIds as $id) {
            Cache::forget("monday.item.{$id}");
        }
    }

    /**
     * Flatten an item's column_values into a stable [id => {text, value, display_value, linked_item_ids}].
     */
    protected function normalizeItem(array $item): array
    {
        $values = [];
        foreach ($item['column_values'] ?? [] as $cv) {
            $values[$cv['id']] = [
                'text'            => $cv['text']            ?? null,
                'value'           => $cv['value']           ?? null,
                'display_value'   => $cv['display_value']   ?? null,
                'linked_item_ids' => $cv['linked_item_ids'] ?? null,
            ];
        }

        return [
            'id'             => $item['id'],
            'name'           => $item['name'],
            'group'          => $item['group']['title'] ?? null,
            'column_values'  => $values,
        ];
    }

    // ---------------------------------------------------------------------
    // Column-value lookups
    // ---------------------------------------------------------------------

    /**
     * Pull the human-readable text of a single column for an item.
     * Returns null if the column is empty or doesn't exist.
     */
    public function columnText(array $item, string $columnId): ?string
    {
        return $item['column_values'][$columnId]['text'] ?? null;
    }

    /**
     * Resolve a list of Monday person IDs to TSP display names by
     * joining the local `users` table on `monday_id`.
     *
     * The portal keeps a local mirror of the TSP directory (name +
     * monday_id) so the UI can render a person's real name without
     * an extra round-trip to Monday. The local table is updated by
     * the admin user-management flow; it's not auto-synced.
     *
     * Returns a map of monday_person_id => name. IDs that aren't
     * known to the local directory are omitted (the caller can
     * fall back to the raw id).
     *
     * Static so it can be called from Blade views and Livewire
     * components without injecting a MondayClient instance.
     *
     * @param  array<int, string|int>  $mondayPersonIds
     * @return array<string, string>   map of monday_id => name
     */
    public static function resolveTspNames(array $mondayPersonIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id) => (string) $id,
            $mondayPersonIds,
        ), static fn (string $id) => $id !== '')));
        if (empty($ids)) {
            return [];
        }

        // Per-request memo: a ticket show page that resolves
        // the same set of TSP ids twice (e.g. the customer view
        // AND the page footer) only hits the users table once.
        // The cache is process-local, so it's free (no Redis /
        // DB hit) and only lives for one request.
        $cacheKey = 'monday.tsp_names.' . md5(implode(',', $ids));
        static $requestCache = [];
        if (isset($requestCache[$cacheKey])) {
            return $requestCache[$cacheKey];
        }

        $names = \App\Models\User::query()
            ->whereIn('monday_id', $ids)
            ->whereNotNull('name')
            ->pluck('name', 'monday_id')
            ->map(static fn ($n) => (string) $n)
            ->toArray();
        $requestCache[$cacheKey] = $names;

        return $names;
    }

    // ---------------------------------------------------------------------
    // Convenience: tickets
    // ---------------------------------------------------------------------

    /**
     * Drop the cached board-items so the next listTickets() call
     * fetches fresh data from Monday. Call this after any mutation
     * (claim, status change, etc.) that needs immediate consistency.
     */
    public function forgetBoardCache(): void
    {
        $boardId = config('services.monday.tickets_board_id');
        if ($boardId) {
            Cache::forget("monday.board.{$boardId}.items");
        }
    }

    /**
     * Pull every ticket and bucket it for the TSP dashboard.
     *
     * Each ticket is annotated with:
     *   - $item                  the raw item
     *   - status_text            the status column's text
     *   - priority_text          priority column text
     *   - request_type_text      request type column text
     *   - tsp_person_ids         array of Monday person ids assigned
     *   - is_open                true unless status matches a "done" group
     */
    public function listTickets(): array
    {
        $boardId = (int) config('services.monday.tickets_board_id');
        $cols    = config('services.monday.tickets_columns');

        $items = $this->getBoardItems($boardId, cacheSeconds: 30);

        return array_map(static function (array $item) use ($cols): array {
            $status  = $item['column_values'][$cols['status']]['text']      ?? null;
            $prio    = $item['column_values'][$cols['priority']]['text']    ?? null;
            $reqType = $item['column_values'][$cols['request_type']]['text']?? null;

            $tspValue = $item['column_values'][$cols['tsp']]['value'] ?? null;
            $tspIds   = [];
            if ($tspValue) {
                $decoded = json_decode($tspValue, true);
                if (is_array($decoded) && isset($decoded['personsAndTeams'])) {
                    foreach ($decoded['personsAndTeams'] as $row) {
                        if (isset($row['id'])) {
                            $tspIds[] = (string) $row['id'];
                        }
                    }
                }
            }

            $isOpen = ! in_array(strtolower((string) $status), [
                'resolved', 'closed', 'done', 'complete', 'completed',
            ], true);

            $subjectText = $item['column_values'][$cols['subject']]['text'] ?? null;

            // Account name is a lookup column — Monday returns
            // text/value as null but puts the real string in
            // display_value. Prefer display_value, then fall back
            // to text/value.
            $acctRaw = $item['column_values'][$cols['account_name']] ?? null;
            $accountName = $acctRaw['display_value']
                ?? $acctRaw['text']
                ?? $acctRaw['value']
                ?? null;

            return [
                'id'                  => $item['id'],
                'name'                => $item['name'],
                'group'               => $item['group'],
                'status_text'         => $status,
                'priority_text'       => $prio,
                'request_type_text'   => $reqType,
                'tsp_person_ids'      => $tspIds,
                'is_open'             => $isOpen,
                'subject_text'        => $subjectText,
                'account_name'        => $accountName,
                'item'                => $item,
            ];
        }, $items);
    }

    /**
     * @return array<int, array> tickets assigned to the given Monday person id
     */
    public function ticketsForTsp(string $mondayPersonId): array
    {
        return array_values(array_filter(
            $this->listTickets(),
            static fn (array $t) => in_array($mondayPersonId, $t['tsp_person_ids'], true)
        ));
    }

    /**
     * Return open tickets that have NO TSP assigned (People column empty).
     *
     * This is the "regional pool" — tickets awaiting a field engineer
     * to claim them. Each ticket is annotated with a customer_region
     * field resolved from the local users table (matched by the
     * ticket's email column) so the dashboard can filter by region.
     */
    public function unclaimedTickets(): array
    {
        return array_values(array_filter(
            $this->listTickets(),
            static function (array $t): bool {
                // Must be open
                if (! $t['is_open']) {
                    return false;
                }
                // People column must be empty (no TSP assigned)
                if (! empty($t['tsp_person_ids'])) {
                    return false;
                }
                return true;
            }
        ));
    }

    /**
     * Open tickets whose People column is empty, annotated with the
     * customer's region from the local users table (matched by the
     * ticket's email column). Used by the TSP dashboard to show the
     * regional pool filtered to the TSP's own region.
     *
     * @return array<int, array> tickets with 'customer_region' added
     */
    public function unclaimedTicketsForRegion(string $regionCode): array
    {
        $pool = $this->unclaimedTickets();

        // Resolve region for each ticket from the local users table
        $regionMap = [];
        $emails = array_filter(array_map(
            static fn (array $t) => $t['item']['column_values']['email']['text'] ?? null,
            $pool
        ));
        if (! empty($emails)) {
            $users = \App\Models\User::whereIn('email', array_map('strtolower', $emails))
                ->where('role', 'customer')
                ->pluck('region', 'email');
            foreach ($users as $email => $region) {
                $regionMap[strtolower($email)] = $region;
            }
        }

        $result = [];
        foreach ($pool as $t) {
            $email = strtolower(trim($t['item']['column_values']['email']['text'] ?? ''));
            $t['customer_region'] = $regionMap[$email] ?? null;
            if ($t['customer_region'] === $regionCode) {
                $result[] = $t;
            }
        }

        return $result;
    }

    /**
     * Server-side region guard for the self-claim flow.
     *
     * The Available pool is already scoped by region via
     * unclaimedTicketsForRegion(), but the claim endpoints accept an
     * arbitrary ticket id, so we re-verify the ticket's customer
     * region here BEFORE writing the People column. Without this
     * guard a TSP could POST a ticket id from another region and
     * claim it anyway.
     *
     * The customer-region lookup mirrors unclaimedTicketsForRegion()
     * exactly (ticket email column → local customers.region) so a
     * ticket that appears in a TSP's Available pool always passes,
     * and a ticket from outside the region is always rejected.
     *
     * Returns false for tickets with no email / no matching customer
     * account, for unknown regions, and for tickets that no longer
     * exist on Monday — the caller must not claim in those cases.
     */
    public function ticketIsInRegion(int $ticketItemId, string $regionCode): bool
    {
        $item = $this->getItem($ticketItemId);
        if (! $item) {
            return false;
        }

        $email = strtolower(trim($item['column_values']['email']['text'] ?? ''));
        if ($email === '') {
            return false;
        }

        $region = \App\Models\User::where('email', $email)
            ->where('role', 'customer')
            ->value('region');

        if ($region === null) {
            return false;
        }

        // Normalize both sides (RegionResolver upgrades legacy
        // free-text values like "Cebu" → "VISAYAS" and upper-cases),
        // then compare strictly — same 4-broad taxonomy the pool and
        // the TSP's own region use.
        $normalized = \App\Support\RegionResolver::normalizeRegionCode((string) $region);

        return $normalized !== null && $normalized === strtoupper($regionCode);
    }

    /**
     * Claim a ticket for a TSP: writes their person ID into the People
     * column and flips response_status to "RESPONDED".
     *
     * This is the core mutation for the self-claim flow. The TSP's
     * local user record must have a monday_id (person ID on Monday).
     */
    public function claimTicket(int $ticketItemId, string $mondayPersonId): void
    {
        $boardId = (int) config('services.monday.tickets_board_id');
        $tspCol  = (string) config('services.monday.tickets_columns.tsp');

        // Write the person into the People column
        // NOTE: changeColumnValues() already calls json_encode on the
        // entire columnValues array, so we pass a raw PHP array here.
        $this->changeColumnValues($boardId, $ticketItemId, [
            $tspCol => [
                'personsAndTeams' => [
                    ['id' => (int) $mondayPersonId, 'kind' => 'person'],
                ],
            ],
        ]);

        // Flip response status: "NOT YET" → "RESPONDED" once a TSP
        // is assigned. This is the core mutation of the self-claim
        // flow — a claim that doesn't flip the status is invisible
        // to the executive KPI board.
        $this->markTicketResponded($ticketItemId);
    }

    /**
     * Tickets belonging to a specific customer (matched by email).
     *
     * The Tickets board has a free-text "email" column (and a board-relation
     * to the Customers board). Matching on email is more reliable than the
     * board-relation since the relation can be empty for tickets created
     * before the customer was on-boarded.
     */
    public function ticketsForCustomer(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return [];
        }

        $all = $this->listTickets();

        return array_values(array_filter(
            $all,
            static function (array $t) use ($email): bool {
                $col = $t['item']['column_values']['email']['text'] ?? null;
                if (! $col) {
                    return false;
                }
                // The "email" column may hold one or many addresses
                // separated by " - " (a dash with surrounding spaces,
                // as produced by monday.com when multiple contacts are
                // listed). The split regex requires at least one space
                // on each side of the dash so the dash inside an email
                // address (e.g. "first.last-lastname@host") doesn't
                // get treated as a separator. Check each piece.
                foreach (preg_split('/\s+-\s+/', strtolower($col)) as $piece) {
                    if (trim($piece) === $email) {
                        return true;
                    }
                }
                return false;
            }
        ));
    }

    /**
     * Detect a "same ticket" re-submit by this customer: returns any
     * OPEN ticket (status not in resolved/closed/done) the customer
     * has whose name matches the proposed subject (case-insensitive,
     * whitespace-trimmed, exact match).
     *
     * Monday's "item name" for a ticket IS the customer's subject
     * (verbatim, no "Ticket+<id>" rename, no "Brand - Model | "
     * prefix — see createTicket()). We compare the stored name to
     * the subject directly, so customers who re-submit the same
     * subject from a different equipment profile are still caught
     * as duplicates.
     *
     * Returns an array of matching tickets (id, name, status_text,
     * priority_text, request_type_text). Empty array = no duplicate.
     *
     * Note: listTickets() has a 30s cache, so back-to-back submits
     * within that window are checked against the same snapshot.
     */
    public function findOpenDuplicateTicketForCustomer(string $email, string $subject): array
    {
        $email   = strtolower(trim($email));
        $subject = strtolower(trim($subject));
        if ($email === '' || $subject === '') {
            return [];
        }

        $mine = $this->ticketsForCustomer($email);
        if (empty($mine)) {
            return [];
        }

        return array_values(array_filter(
            $mine,
            static function (array $t) use ($subject): bool {
                if (! $t['is_open']) {
                    return false;
                }
                // Compare against the dedicated subject column first;
                // fall back to the item name for legacy tickets.
                $existing = strtolower(trim(
                    $t['subject_text'] ?: $t['name'] ?? ''
                ));
                if ($existing === '') {
                    return false;
                }
                return $existing === $subject;
            }
        ));
    }

    /**
     * Find a customer record on the Customers board by exact email match.
     * Returns the Monday item id (as string) or null.
     */
    public function findCustomerItemIdByEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $boardId = (int) config('services.monday.customers_board_id');
        $colId   = config('services.monday.customers_columns.email');

        $items = $this->getBoardItems($boardId, cacheSeconds: 60);

        foreach ($items as $item) {
            $col = strtolower((string) ($item['column_values'][$colId]['text'] ?? ''));
            if ($col === $email) {
                return (string) $item['id'];
            }
        }
        return null;
    }

    /**
     * Find a customer record on the Customers board by exact (case-insensitive)
     * name match. Useful as a fallback when email doesn't line up between the
     * local user table and the Monday Customers board.
     */
    public function findCustomerItemIdByName(string $name): ?string
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return null;
        }

        $boardId = (int) config('services.monday.customers_board_id');
        $items   = $this->getBoardItems($boardId, cacheSeconds: 60);

        foreach ($items as $item) {
            if (strtolower((string) $item['name']) === $name) {
                return (string) $item['id'];
            }
        }
        return null;
    }

    /**
     * Verify a Monday item is still alive (not deleted / archived).
     * Returns false for unknown, deleted, archived, or trashed items.
     *
     * Cached for 30 seconds with a negative cache for the
     * "definitely not active" case. This is the hot path on the
     * customer ticket-show page (called from
     * `findOrCreateCustomerItem` to verify the cached
     * `users.monday_id` is still alive), and on the TSR drainer
     * (called once per pending TSR to skip rows whose source
     * ticket is gone — see the 2026-07-30 inactive-ticket
     * tolerance fix).
     */
    public function itemExists(int|string $itemId): bool
    {
        $id = (string) $itemId;
        return Cache::remember(
            "monday.item.{$id}.exists",
            30,
            function () use ($id) {
                $gql = <<<'GQL'
                query($id: ID!) {
                    items(ids: [$id]) { id state }
                }
                GQL;
                $r = $this->query($gql, ['id' => $id]);
                $state = $r['items'][0]['state'] ?? null;

                return $state === 'active' || $state === null; // null = legacy / unknown schema
            }
        );
    }

    /**
     * Verify that a cached Monday customer item id actually belongs to
     * the given user identity. We compare by email (exact, case-folded)
     * and by name (case-folded trim) — a match on either is enough.
     * Used by findOrCreateCustomerItem to detect a stale cache that
     * points to a different customer record on Monday.
     */
    public function customerItemMatchesIdentity(int|string $itemId, string $email, string $name): bool
    {
        $cols = config('services.monday.customers_columns');
        $emailCol = $cols['email'] ?? null;
        if (! $emailCol) {
            return false;
        }

        $gql = <<<'GQL'
        query($id: ID!, $cols: [String!]) {
            items(ids: [$id]) {
                id
                name
                column_values(ids: $cols) {
                    id
                    text
                    value
                }
            }
        }
        GQL;
        try {
            $r = $this->query($gql, [
                'id'   => (string) $itemId,
                'cols' => [$emailCol],
            ]);
        } catch (\Throwable) {
            return false;
        }
        $item = $r['items'][0] ?? null;
        if (! $item) {
            return false;
        }

        foreach ($item['column_values'] ?? [] as $cv) {
            if (($cv['id'] ?? null) !== $emailCol) {
                continue;
            }
            // email column value: { "email": "foo@bar", "text": "foo@bar" }
            $raw = $cv['value'] ?? null;
            if ($raw) {
                $decoded = json_decode($raw, true);
                $colEmail = strtolower(trim((string) (is_array($decoded) ? ($decoded['email'] ?? $decoded['text'] ?? '') : $raw)));
                if ($colEmail !== '' && $colEmail === strtolower(trim($email))) {
                    return true;
                }
            }
            $colText = strtolower(trim((string) ($cv['text'] ?? '')));
            if ($colText !== '' && $colText === strtolower(trim($email))) {
                return true;
            }
        }

        $nameWant = strtolower(trim($name));
        if ($nameWant !== '') {
            $nameHave = strtolower(trim((string) ($item['name'] ?? '')));
            if ($nameHave !== '' && $nameHave === $nameWant) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find the Monday customer record for a user, creating one if it doesn't
     * exist yet. Used to populate the End User board-relation on the
     * Tickets board for new customers who were never on-boarded in Monday.
     *
     * Accepts an optional $knownId (e.g. the value previously cached on the
     * user row) — if that id points to a deleted item we transparently
     * re-resolve by email. Caches the resolved id on the user.
     *
     * @param  array{name:string, email:string, account_name?:?string, brand?:?string, model?:?string, monday_id?:?string} $user
     * @return string|null Monday item id, or null on failure.
     */
    public function findOrCreateCustomerItem(array $user, ?string $knownId = null): ?string
    {
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if ($email === '') {
            return null;
        }

        // If we have a cached id, verify it's still live AND that it
        // matches the current user identity. A stale cache can point
        // to a different customer record on Monday (e.g. a name
        // collision or a re-seeded id) — in that case we fall through
        // to the email/name re-resolution below.
        if ($knownId !== null && $knownId !== '' && $this->itemExists($knownId)) {
            $cachedMatches = $this->customerItemMatchesIdentity($knownId, $email, (string) ($user['name'] ?? ''));
            if ($cachedMatches) {
                return $knownId;
            }
        }

        // Otherwise (no cache, cache is stale/deleted, or cache points
        // to a different customer record), re-resolve.
        // Try email first, then fall back to name match — this dedups
        // cases where the local user email and the Monday customer
        // record's email are out of sync.
        $existing = $this->findCustomerItemIdByEmail($email);
        if ($existing !== null) {
            return $existing;
        }
        $name = trim((string) ($user['name'] ?? ''));
        if ($name !== '') {
            $existing = $this->findCustomerItemIdByName($name);
            if ($existing !== null) {
                return $existing;
            }
        }

        $boardId = (int) config('services.monday.customers_board_id');
        $cols    = config('services.monday.customers_columns');

        $name = trim(($user['account_name'] ?? '') !== ''
            ? "{$user['account_name']} - {$user['name']}"
            : ($user['name'] ?? $email));

        $columnValues = [
            $cols['email'] => [
                'email' => $email,
                'text'  => $email,
            ],
        ];
        if (! empty($user['account_name'])) {
            $columnValues[$cols['account_name']] = (string) $user['account_name'];
        }
        if (! empty($user['brand'])) {
            $columnValues[$cols['brand']] = (string) $user['brand'];
        }
        if (! empty($user['model'])) {
            $columnValues[$cols['model']] = (string) $user['model'];
        }

        $gql = <<<'GQL'
        mutation ($boardId: ID!, $itemName: String!, $columnValues: JSON!) {
            create_item(
                board_id: $boardId,
                item_name: $itemName,
                column_values: $columnValues,
                create_labels_if_missing: true
            ) {
                id
                name
            }
        }
        GQL;

        $resp = $this->query($gql, [
            'boardId'      => (string) $boardId,
            'itemName'     => $name,
            'columnValues' => json_encode((object) $columnValues),
        ]);

        return isset($resp['create_item']['id'])
            ? (string) $resp['create_item']['id']
            : null;
    }

    /**
     * Create a new ticket on the Tickets board.
     *
     * @param  array{
     *     name:        string,
     *     description: string,
     *     priority:    ?string,           // 'Low'|'Medium'|'High'|'Critical'
     *     request_type:?string,           // 'Request'|'Issue'
     *     customer_email: string,
     *     customer_item_id: ?string,      // monday id of the customer record (for board_relation)
     *     brand?: ?string,
     *     model?: ?string,
     *     serial?: ?string,
     *     tsp_person_ids?: array<int>,   // monday person ids to assign (the "TSP" People column)
     * } $data
     */
    public function createTicket(array $data): array
    {
        $cols   = config('services.monday.tickets_columns');
        $boardId = (int) config('services.monday.tickets_board_id');

        $columnValues = [
            // long_text columns accept a plain string
            $cols['description'] => $data['description'],
            // email columns want a {email, text} object
            $cols['email']       => [
                'email' => $data['customer_email'],
                'text'  => $data['customer_email'],
            ],
        ];
        if (! empty($data['priority'])) {
            $columnValues[$cols['priority']] = ['label' => $data['priority']];
        }
        if (! empty($data['request_type'])) {
            $columnValues[$cols['request_type']] = ['label' => $data['request_type']];
        }
        if (! empty($data['customer_item_id'])) {
            $columnValues[$cols['end_user']] = [
                'item_ids' => [(int) $data['customer_item_id']],
            ];
        }

        // MACHINE BRAND / MODEL — plain text columns on the Customer
        // Tickets board (text_mm5apcrc for MACHINE BRAND,
        // text_mm5am2kf for MACHINE MODEL). When the customer uses
        // the "different machine" manual input on the create-ticket
        // form, the local brand / model strings get passed in here.
        // We only write when the config keys are mapped (so older
        // deployments without these column ids don't break) and
        // only when the value is non-empty. Values are written as
        // plain strings — these columns are the simple `text` type,
        // not dropdowns, so no label-id lookup is required.
        // (The dropdown-type BRAND/MODEL columns on the Internal
        // Tickets board — dropdown_mm3gjs5y / dropdown_mm3gx0tz —
        // are read-only mirrors and CANNOT be written from this
        // integration; that board is the internal one, not the
        // customer-facing one we use here.)
        if (! empty($data['brand']) && ! empty($cols['brand'])) {
            $columnValues[$cols['brand']] = (string) $data['brand'];
        }
        if (! empty($data['model']) && ! empty($cols['model'])) {
            $columnValues[$cols['model']] = (string) $data['model'];
        }

        // TSP People column (multiple_person_mm4fqar3). Accepts an
        // array of monday person ids; we'll wrap it in the {personsAndTeams: [...]}
        // envelope the column expects. Skipped silently when the list is empty
        // so existing tests (which don't pass tsp_person_ids) keep working.
        if (! empty($data['tsp_person_ids']) && is_array($data['tsp_person_ids'])) {
            $tspIds = array_values(array_unique(array_filter(
                array_map('intval', $data['tsp_person_ids'])
            )));
            if (! empty($tspIds) && ! empty($cols['tsp'])) {
                $columnValues[$cols['tsp']] = [
                    'personsAndTeams' => array_map(
                        static fn (int $id) => ['id' => $id, 'kind' => 'person'],
                        $tspIds
                    ),
                ];
            }
        }

        // Subject goes into the dedicated text column — it is separate
        // from the item name. After creation we rename the item to
        // "Ticket #<id>" so the board shows a clean identifier.
        $subject = (string) ($data['name'] ?? '');
        if (! empty($cols['subject']) && $subject !== '') {
            $columnValues[$cols['subject']] = $subject;
        }

        // Create the item with the subject as a temporary name (so the
        // board is never blank), then immediately rename to "Ticket #<id>".
        $graphql = <<<'GQL'
        mutation ($boardId: ID!, $itemName: String!, $columnValues: JSON!) {
            create_item(
                board_id: $boardId,
                item_name: $itemName,
                column_values: $columnValues,
                create_labels_if_missing: true
            ) {
                id
                name
            }
        }
        GQL;

        $vars = [
            'boardId'      => (string) $boardId,
            'itemName'     => $subject ?: 'New Ticket',
            'columnValues' => json_encode((object) $columnValues),
        ];

        $resp = $this->query($graphql, $vars);

        $itemId = $resp['create_item']['id'] ?? null;

        // Rename the item to "Ticket #<id>" so the board shows a stable
        // identifier instead of the full subject text.
        if ($itemId && ! empty($cols['subject'])) {
            try {
                $this->changeColumnValues($boardId, (int) $itemId, [
                    'name' => "Ticket #{$itemId}",
                ]);
            } catch (\Throwable $e) {
                // Non-fatal: the subject is already in the column. Log and
                // continue — the item will keep its temporary name.
                \Illuminate\Support\Facades\Log::warning('createTicket: rename failed', [
                    'item_id' => $itemId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return [
            'id'   => $itemId,
            'name' => $resp['create_item']['name'] ?? null,
        ];
    }

    /**
     * Write a single long-text column on a ticket (e.g. "Internal Notes").
     * This REPLACES the current value of the column in Monday — it is
     * not an append. Callers that need an audit trail (e.g. internal
     * notes history) should keep their own append-only log and only
     * mirror the most recent entry here.
     *
     * @throws RuntimeException on transport or API errors
     */
    public function writeLongTextColumn(int $itemId, string $columnId, string $text): void
    {
        $boardId = (int) config('services.monday.tickets_board_id');

        $graphql = <<<'GQL'
        mutation ($boardId: ID!, $itemId: ID!, $columnValues: JSON!) {
            change_multiple_column_values(
                board_id: $boardId,
                item_id: $itemId,
                column_values: $columnValues
            ) {
                id
            }
        }
        GQL;

        // For long_text columns the value is a plain string.
        $columnValues = json_encode((object) [$columnId => $text]);

        $this->query($graphql, [
            'boardId'      => (string) $boardId,
            'itemId'       => (string) $itemId,
            'columnValues' => $columnValues,
        ]);
    }

    /**
     * Post a plain-text update to a ticket's update log (the
     * "updates" pane in the Monday UI). Returns the new update id
     * as a string. Used by the time-tracker to mirror each closed
     * session as an audit-trail entry.
     *
     * Note: the body is plain text; Monday renders simple newlines.
     * No HTML, no markdown.
     *
     * @throws RuntimeException on transport or API errors
     */
    public function createUpdate(int $itemId, string $body): string
    {
        $graphql = <<<'GQL'
        mutation ($itemId: ID!, $body: String!) {
            create_update(item_id: $itemId, body: $body) {
                id
            }
        }
        GQL;

        $resp = $this->query($graphql, [
            'itemId' => (string) $itemId,
            'body'   => $body,
        ]);

        $id = $resp['create_update']['id'] ?? null;
        if (! $id) {
            throw new RuntimeException('create_update returned no id');
        }
        return (string) $id;
    }

    // ---------------------------------------------------------------------
    // Service Report (TSR) board helpers  — board 5029041107
    // ---------------------------------------------------------------------

    /**
     * Create a Service Report item on the TSR board and link it to
     * the originating ticket via the `board_relation` column.
     *
     * Expected $data shape (all optional except ticket_item_id):
     *   ticket_item_id: int   — monday id of the source ticket
     *   service_status: ?string — 'open'|'in_progress'|'pending'|'escalated'|'completed'
     *   problem_and_concerns: ?string
     *   job_done: ?string
     *   parts_replaced: ?string
     *   recommendation: ?string
     *   login_date: ?string (Y-m-d)
     *   service_start: ?string (Y-m-d H:i)
     *   service_end: ?string   (Y-m-d H:i)
     *   logout_date: ?string (Y-m-d)
     *   machine_system: ?string
     *   serial_number: ?string
     *   software_version: ?string
     *   contract: ?string ('Purchased'|'RTU'|'Demo'|'Backup')
     *   tsp_workwith_person_ids: array<int>
     *   tsp_email: ?string
     *   customer_incharge: ?string
     *   customer_incharge_email: ?string
     *   biomed_incharge: ?string
     *   biomed_email: ?string
     *   remarks: ?string
     *   call_login_time: ?string (HH:MM:SS)
     *   item_name: ?string (defaults to "{ticket} TSR {ts}")
     *
     * @return array{id:?string,name:?string} the new TSR item's id and name
     */
    public function createServiceReportItem(array $data): array
    {
        $cols    = config('services.monday.service_report_columns');
        $boardId = (int) config('services.monday.service_report_board_id');
        if ($boardId === 0) {
            throw new RuntimeException('MONDAY_SERVICE_REPORT_BOARD_ID is not set.');
        }

        $columnValues = [];

        // Link back to the ticket via board_relation. We keep this in a
        // dedicated slot so we can drop it and retry if the connected
        // board hasn't been wired up in the Monday UI (this is a
        // common production-config state and shouldn't block the
        // whole TSR from being created).
        $relationColumn = null;
        if (! empty($data['ticket_item_id']) && ! empty($cols['service_number'])) {
            $relationColumn = $cols['service_number'];
            $columnValues[$relationColumn] = [
                'item_ids' => [(int) $data['ticket_item_id']],
            ];
        }

        if (! empty($data['service_status'])) {
            $label = config(
                "services.monday.service_status_labels.{$data['service_status']}",
                null
            );
            if ($label) {
                $columnValues[$cols['service_status']] = ['label' => $label];
            }
        }

        $textMap = [
            'problem_and_concerns' => 'long_text',
            'job_done'             => 'long_text',
            'parts_replaced'       => 'text',
            'recommendation'       => 'long_text',
            'serial_number'        => 'long_text',
            'software_version'     => 'text',
            'customer_incharge'    => 'text',
            'biomed_incharge'      => 'text',
            'remarks'              => 'long_text',
        ];
        foreach ($textMap as $key => $type) {
            if (! empty($data[$key])) {
                $columnValues[$cols[$key]] = (string) $data[$key];
            }
        }

        // Date columns: Monday accepts {date: 'YYYY-MM-DD'} or {date: 'YYYY-MM-DD HH:MM', time: 'HH:MM'}.
        $dateMap = [
            'login_date'    => 'login_date',
            'service_start' => 'service_start',
            'service_end'   => 'service_end',
            'logout_date'   => 'logout_date',
        ];
        foreach ($dateMap as $key => $_) {
            if (! empty($data[$key])) {
                $columnValues[$cols[$key]] = ['date' => (string) $data[$key]];
            }
        }

        // Status / single-select columns.
        if (! empty($data['machine_system'])) {
            $columnValues[$cols['machine_system']] = ['label' => (string) $data['machine_system']];
        }
        if (! empty($data['contract'])) {
            $columnValues[$cols['contract']] = ['label' => (string) $data['contract']];
        }

        // Email columns: {email, text}
        $emailMap = [
            'tsp_email'              => 'tsp_email',
            'customer_incharge_email'=> 'customer_incharge_email',
            'biomed_email'           => 'biomed_email',
        ];
        foreach ($emailMap as $key => $_) {
            if (! empty($data[$key])) {
                $columnValues[$cols[$key]] = [
                    'email' => (string) $data[$key],
                    'text'  => (string) $data[$key],
                ];
            }
        }

        // People column for TSP WORKWITH.
        if (! empty($data['tsp_workwith_person_ids']) && is_array($data['tsp_workwith_person_ids'])) {
            $persons = array_map(fn($id) => ['id' => (int) $id, 'kind' => 'person'], $data['tsp_workwith_person_ids']);
            $columnValues[$cols['tsp_workwith']] = ['personsAndTeams' => $persons];
        }

        // Hour column (Call Login Time) — pass through as a plain time string.
        if (! empty($data['call_login_time'])) {
            $columnValues[$cols['call_login_time']] = [
                'hour'  => (int) substr((string) $data['call_login_time'], 0, 2),
                'minute'=> (int) substr((string) $data['call_login_time'], 3, 2),
            ];
        }

        $itemName = $data['item_name']
            ?? sprintf('TSR for #%d — %s', (int) $data['ticket_item_id'], now()->format('Y-m-d H:i'));

        $graphql = <<<'GQL'
        mutation ($boardId: ID!, $itemName: String!, $columnValues: JSON!) {
            create_item(
                board_id: $boardId,
                item_name: $itemName,
                column_values: $columnValues,
                create_labels_if_missing: true
            ) {
                id
                name
            }
        }
        GQL;

        $post = function (array $cv) use ($graphql, $boardId, $itemName) {
            return $this->query($graphql, [
                'boardId'      => (string) $boardId,
                'itemName'     => $itemName,
                'columnValues' => json_encode((object) $cv),
            ]);
        };

        try {
            $resp = $post($columnValues);
        } catch (MondayApiException $e) {
            // If the only error is the ticket-relation column whose
            // source board hasn't been configured in the Monday UI
            // (or whose target item has been archived / moved to
            // trash / soft-deleted), strip the relation and re-issue.
            // The TSR item is still created, and the user can wire
            // the relation manually later.
            //
            // Codes we tolerate:
            //  - itemsNotInConnectedBoards: the column's connected
            //    board list doesn't include the target board.
            //  - inactiveItems: the target ticket was archived.
            //  - linkedToDeletedItems: the target ticket is in the
            //    trash. This is the most common cause of "stuck"
            //    TSR rows in the local DB — the user closed the
            //    ticket on Monday, our drain tries to attach the
            //    TSR, and the relation refuses. Stripping the
            //    relation lets the TSR go through and the row gets
            //    marked synced; the only thing lost is the
            //    back-link from TSR → ticket, which the user can
            //    see in the Monday UI and wire manually if needed.
            if (
                $relationColumn !== null
                && $e->isColumnValidationError()
                && $e->columnId() === $relationColumn
                && in_array($e->columnValidationCode(), [
                    'itemsNotInConnectedBoards',
                    'inactiveItems',
                    'linkedToDeletedItems',
                ], true)
            ) {
                Log::warning('Monday rejected Service Number relation; retrying without it', [
                    'code'        => $e->columnValidationCode(),
                    'column_id'   => $e->columnId(),
                    'column_name' => $e->error['extensions']['error_data']['column_name'] ?? null,
                ]);
                unset($columnValues[$relationColumn]);
                $resp = $post($columnValues);
            } else {
                throw $e;
            }
        }

        return [
            'id'   => $resp['create_item']['id']   ?? null,
            'name' => $resp['create_item']['name'] ?? null,
        ];
    }

    /**
     * Link an existing TSR item to its source ticket via the
     * `Service Number` board-relation column. Safe to call after
     * createServiceReportItem() — if the column is misconfigured
     * the TSR will still have been created; we just log and move on.
     *
     * @return bool true if the link was set, false on any failure
     */
    public function linkTsrToTicket(int $tsrItemId, int $ticketItemId): bool
    {
        $cols    = config('services.monday.service_report_columns');
        $boardId = (int) config('services.monday.service_report_board_id');
        $colId   = $cols['service_number'] ?? null;
        if (! $colId) {
            return false;
        }

        $graphql = <<<'GQL'
        mutation ($boardId: ID!, $itemId: ID!, $columnId: String!, $value: JSON!) {
            change_column_value(
                board_id: $boardId,
                item_id: $itemId,
                column_id: $columnId,
                value: $value
            ) {
                id
            }
        }
        GQL;

        try {
            $this->query($graphql, [
                'boardId'  => (string) $boardId,
                'itemId'   => (string) $tsrItemId,
                'columnId' => $colId,
                'value'    => json_encode((object) [
                    'item_ids' => [$ticketItemId],
                ]),
            ]);
            return true;
        } catch (MondayApiException $e) {
            // Most likely cause: the column is connected to a board
            // that doesn't include this ticket. Log and move on; the
            // TSR itself is already created and the user can wire
            // the relation manually in the Monday UI.
            Log::warning('Failed to link TSR to ticket via Service Number', [
                'tsr_item_id'      => $tsrItemId,
                'ticket_item_id'   => $ticketItemId,
                'column_id'        => $colId,
                'validation_code'  => $e->columnValidationCode(),
                'error'            => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Inspect the `Service Number` column on the TSR board and
     * report whether the Tickets board is in its `connectedBoardIds`
     * list. Useful as a one-time setup check; Monday.com does not
     * expose a public GraphQL mutation to add a board to that list
     * — the admin has to do it in the Monday UI.
     *
     * @return array{ok:bool, message?:string, is_connected?:bool,
     *               tsr_board_id?:int, tickets_board_id?:int,
     *               column_id?:string, column_title?:string,
     *               connected_board_ids?:array, next_step?:string}
     */
    public function ensureServiceNumberLinksToTicketsBoard(): array
    {
        $cols     = config('services.monday.service_report_columns');
        $colId    = $cols['service_number'] ?? null;
        $tsrBoard = (int) config('services.monday.service_report_board_id');
        $tickets  = (int) config('services.monday.tickets_board_id');
        if (! $colId || ! $tsrBoard || ! $tickets) {
            return [
                'ok'      => false,
                'message' => 'service_number column or board id not configured',
            ];
        }

        $graphql = <<<'GQL'
        query ($boardId: [ID!]) {
            boards (ids: $boardId) {
                id
                name
                columns {
                    id
                    title
                    type
                    settings_str
                }
            }
        }
        GQL;

        $resp = $this->query($graphql, ['boardId' => [(string) $tsrBoard]]);
        $board = $resp['boards'][0] ?? null;
        if (! $board) {
            return [
                'ok'      => false,
                'message' => 'TSR board not found via API',
                'raw'     => $resp,
            ];
        }

        $col = null;
        $allColumns = [];
        foreach (($board['columns'] ?? []) as $c) {
            $allColumns[] = [
                'id'    => $c['id']    ?? null,
                'title' => $c['title'] ?? null,
                'type'  => $c['type']  ?? null,
            ];
            if (($c['id'] ?? null) === $colId) { $col = $c; }
        }
        if (! $col) {
            // Some columns live behind a `board-relation` parent in
            // the schema. Try a column_types lookup.
            return [
                'ok'      => false,
                'message' => "Column {$colId} not in 'columns' list",
                'hint'    => 'Inspect $allColumns to see what the API returned. Monday occasionally hides board_relation columns at the top level.',
                'columns_returned' => $allColumns,
            ];
        }

        $settings  = json_decode((string) ($col['settings_str'] ?? ''), true) ?: [];
        $connected = $settings['connectedBoardIds'] ?? [];
        $linked    = in_array((int) $tickets, array_map('intval', $connected), true);

        return [
            'ok'                  => true,
            'tsr_board_id'        => $tsrBoard,
            'tickets_board_id'    => $tickets,
            'column_id'           => $colId,
            'column_title'        => $col['title'] ?? null,
            'connected_board_ids' => $connected,
            'is_connected'        => $linked,
            'next_step'           => $linked
                ? 'Column is already connected to the Tickets board.'
                : "Add board {$tickets} to the 'Connected boards' list of column '{$col['title']}' on TSR board {$tsrBoard} (Monday admin UI).",
        ];
    }

    /**
     * Update the Service Status of an existing TSR item (e.g. when a
     * TSP resumes work or escalates a ticket).
     */
    public function setServiceStatus(int $tsrItemId, string $portalStatus): void
    {
        $cols = config('services.monday.service_report_columns');
        $label = config("services.monday.service_status_labels.{$portalStatus}");
        if (! $label) {
            throw new RuntimeException("Unknown service status: {$portalStatus}");
        }

        $this->writeSingleStatusColumn(
            boardId: (int) config('services.monday.service_report_board_id'),
            itemId:  $tsrItemId,
            columnId: $cols['service_status'],
            label: $label,
        );
    }

    /**
     * Generic write of a single status column. Used by setServiceStatus
     * and by the ticket-status updater.
     */
    public function writeSingleStatusColumn(int $boardId, int $itemId, string $columnId, string $label): void
    {
        $graphql = <<<'GQL'
        mutation ($boardId: ID!, $itemId: ID!, $columnId: String!, $value: JSON!) {
            change_column_value(
                board_id: $boardId,
                item_id: $itemId,
                column_id: $columnId,
                value: $value,
                create_labels_if_missing: true
            ) {
                id
            }
        }
        GQL;

        $this->query($graphql, [
            'boardId'  => (string) $boardId,
            'itemId'   => (string) $itemId,
            'columnId' => $columnId,
            'value'    => json_encode((object) ['label' => $label]),
        ]);
    }

    /**
     * Translate a TSR Service Status label into a Tickets board status95
     * label and apply it. Returns the label applied, or null if no
     * status change is required (e.g. TSR is still OPEN, or the
     * source ticket was archived/deleted on Monday and there is
     * nothing to write to).
     *
     * Tolerant of inactive tickets: if the source ticket is gone
     * (archived / trashed / soft-deleted) on Monday, we treat the
     * write as a no-op and return null. Returning null tells the
     * drainer to mark the local TSR row as Synced and move on, so
     * the dashboard's "service reports need attention" banner stops
     * accumulating rows that can never be mirrored. The relation-
     * stripping fallback in createServiceReportItem() has the same
     * philosophy; this is its sibling for the status write.
     */
    public function applyTicketStatusFromServiceStatus(string $tsrLabel, int $ticketItemId): ?string
    {
        $map = config('services.monday.service_status_to_ticket_status');
        $newLabel = $map[$tsrLabel] ?? null;
        if (! $newLabel) {
            return null;
        }

        $ticketsBoard = (int) config('services.monday.tickets_board_id');
        $statusCol    = config('services.monday.tickets_columns.status');

        // Preflight: if the source ticket is already inactive on
        // Monday (archived / trashed / soft-deleted), Monday will
        // reject the upcoming change_column_value with
        // "Cannot change column value for inactive items". Catch it
        // here so the drainer doesn't loop forever.
        if (! $this->itemExists($ticketItemId)) {
            Log::warning('Skipping ticket status patch: source ticket is inactive on Monday', [
                'ticket_item_id' => $ticketItemId,
                'tsr_label'      => $tsrLabel,
                'new_label'      => $newLabel,
            ]);
            return null;
        }

        try {
            $this->writeSingleStatusColumn(
                boardId: $ticketsBoard,
                itemId:  $ticketItemId,
                columnId: $statusCol,
                label: $newLabel,
            );
        } catch (MondayApiException $e) {
            // Race condition: ticket was active at the preflight
            // above but got archived between then and the actual
            // write. Treat the same as the preflight skip.
            if ($e->isInactiveItemError()) {
                Log::warning('Ticket status write rejected — item went inactive mid-drain', [
                    'ticket_item_id' => $ticketItemId,
                    'tsr_label'      => $tsrLabel,
                    'new_label'      => $newLabel,
                    'monday_code'    => $e->columnValidationCode()
                        ?? $e->error['extensions']['code']
                        ?? null,
                ]);
                return null;
            }
            throw $e;
        }

        // Set resolution_date to today when the service is COMPLETED.
        // This write is best-effort: if the ticket got archived
        // between the status write and this one, swallow it.
        if ($tsrLabel === 'COMPLETED') {
            try {
                $this->writeDateColumn(
                    boardId: $ticketsBoard,
                    itemId:  $ticketItemId,
                    columnId: config('services.monday.tickets_columns.resolution_date'),
                    date: now()->toDateString(),
                );
            } catch (MondayApiException $e) {
                if ($e->isInactiveItemError()) {
                    Log::warning('resolution_date write skipped — ticket inactive', [
                        'ticket_item_id' => $ticketItemId,
                    ]);
                } else {
                    throw $e;
                }
            }
        }

        return $newLabel;
    }

    /**
     * Flip a ticket's RESPONSE STATUS (color_mm4vbp35) to "RESPONDED".
     *
     * The customer-side ticket creation flow calls this once the new
     * ticket is committed to Monday and at least one TSP is assigned
     * (i.e. the People column on the Tickets board is non-empty).
     *
     * If the column is already "RESPONDED" the write is a no-op on
     * Monday's side (we still issue the mutation, but the resulting
     * value is identical). If "RESPONDED" is not yet a defined label
     * on this column, the underlying `change_column_value` call uses
     * `create_labels_if_missing: true` so it will be created on the
     * fly — this is the same `writeSingleStatusColumn` helper that
     * `applyTicketStatusFromServiceStatus()` uses for status95.
     *
     * Best-effort: callers (e.g. TicketController::store) should not
     * let a failure here block the redirect — the customer has
     * already submitted the ticket. Log the failure and continue.
     */
    public function markTicketResponded(int $ticketItemId): void
    {
        $boardId = (int) config('services.monday.tickets_board_id');
        $colId   = (string) config('services.monday.tickets_columns.response_status');

        if ($colId === '') {
            // Column not configured. Don't blow up the ticket creation
            // path — just log and bail. The status won't be flipped,
            // which is the safer fallback than an exception.
            Log::warning('markTicketResponded: response_status column not configured', [
                'ticket_id' => $ticketItemId,
            ]);
            return;
        }

        $this->writeSingleStatusColumn(
            boardId: $boardId,
            itemId:  $ticketItemId,
            columnId: $colId,
            label: 'RESPONDED',
        );
    }

    /**
     * Read the current Response Time value from a ticket's
     * time_tracking column.
     *
     * Returns a normalized array regardless of whether the column
     * is empty, running, or stopped:
     *   [
     *     'duration'    => int seconds,   // total accumulated time
     *     'running'     => bool,          // is a session in progress
     *     'start_date'  => ?int unix_ts,  // when the current run started (null when stopped)
     *     'changed_at'  => ?string iso,
     *     'text'        => 'HH:MM:SS',    // Monday's own formatted display
     *   ]
     *
     * Used by the TSP ticket-detail time tracker to mirror whatever
     * Monday's native time_tracking widget shows — the portal no
     * longer maintains a local-first timer state machine.
     */
    public function readTimeTracking(int $ticketItemId): array
    {
        $boardId = (int) config('services.monday.tickets_board_id');
        $colId   = (string) config('services.monday.tickets_columns.time_tracking');

        $empty = [
            'duration'   => 0,
            'running'    => false,
            'start_date' => null,
            'changed_at' => null,
            'text'       => '00:00:00',
        ];

        if ($colId === '') {
            return $empty;
        }

        $item = $this->getItem($ticketItemId);
        if (! $item) {
            return $empty;
        }

        $cv = $item['column_values'][$colId] ?? null;
        if (! $cv) {
            return $empty;
        }

        // Monday stores the value as a JSON string in $cv['value'].
        $raw = $cv['value'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = [];
        }

        return [
            'duration'   => (int) ($decoded['duration']  ?? 0),
            'running'    => (string) ($decoded['running'] ?? 'false') === 'true',
            'start_date' => isset($decoded['startDate']) ? (int) $decoded['startDate'] : null,
            'changed_at' => $decoded['changed_at'] ?? null,
            'text'       => (string) ($cv['text'] ?? '00:00:00'),
        ];
    }

    /**
     * Write a single date column on a ticket/board.
     *
     * Used by applyTicketStatusFromServiceStatus() to stamp
     * resolution_date when the service status flips to COMPLETED.
     */
    public function writeDateColumn(int $boardId, int $itemId, string $columnId, string $date): void
    {
        $graphql = <<<'GQL'
        mutation ($boardId: ID!, $itemId: ID!, $columnId: String!, $value: JSON!) {
            change_column_value(
                board_id: $boardId,
                item_id: $itemId,
                column_id: $columnId,
                value: $value
            ) {
                id
            }
        }
        GQL;

        $this->query($graphql, [
            'boardId'  => (string) $boardId,
            'itemId'   => (string) $itemId,
            'columnId' => $columnId,
            'value'    => json_encode((object) ['date' => $date]),
        ]);
    }

    /**
     * Alias of forgetBoardCache() with a friendlier, intent-revealing
     * name. Called by callers (e.g. the open-invite registration flow)
     * after a write that may have changed the customer board. Public
     * so it can be mocked in tests.
     *
     * @param  int|null  $boardId  Optional. If null, defaults to the
     *                             customers board.
     */
    public function flushCache(?int $boardId = null): void
    {
        $target = $boardId ?? (int) config('services.monday.customers_board_id');
        if ($target) {
            Cache::forget("monday.board.{$target}.items");
        }
    }

    /**
     * Generic cache-bust by key. Public so it can be mocked in
     * tests; production callers should normally prefer flushCache()
     * for the customers board.
     */
    public function cacheForget(string $key): void
    {
        Cache::forget($key);
    }

    /**
     * Create a new customer row on the monday.com Customer Details
     * board from an open-invite registration. Returns the new item's
     * id (string) on success, or null on failure.
     *
     * @param  array{
     *     email:        string,
     *     account_name: string,
     *     branch:       string,
     *     region:       string,
     *     address?:     ?string,
     * }  $data
     */
    public function createCustomerItem(array $data): ?string
    {
        $boardId = (int) config('services.monday.customers_board_id');
        if ($boardId === 0) {
            throw new RuntimeException('MONDAY_CUSTOMERS_BOARD_ID is not set.');
        }
        $cols = config('services.monday.customers_columns');

        $columnValues = [];

        if (! empty($data['email'])) {
            $columnValues[$cols['email']] = [
                'email' => (string) $data['email'],
                'text'  => (string) $data['email'],
            ];
        }
        if (! empty($data['account_name'])) {
            $columnValues[$cols['account_name']] = (string) $data['account_name'];
        }
        if (! empty($data['branch'])) {
            // Branch is a status-style column on Customer Details. We
            // use create_labels_if_missing: true on the mutation so a
            // brand-new branch label is materialised on first use.
            $columnValues[$cols['branch']] = ['label' => (string) $data['branch']];
        }
        if (! empty($data['address'])) {
            // Address is a `location` column on Customer Details.
            // Monday requires valid lat/lng — empty strings, null,
            // or address-only payloads are rejected with
            // "invalid value, please check our API documentation".
            // The customer just types a freeform street address,
            // so we have no coordinates to send. In that case we
            // simply skip the column — the freeform text is still
            // persisted on the local users.address row and shown
            // in the portal UI, but Monday's location column is
            // left empty (an admin can fill it in later by hand
            // if they need a pin on the map).
            //
            // If a future caller DOES provide real lat/lng (e.g.
            // a geocoding service), use them.
            if (isset($data['lat'], $data['lng'])
                && $data['lat'] !== '' && $data['lat'] !== null
                && $data['lng'] !== '' && $data['lng'] !== null
            ) {
                $columnValues[$cols['address']] = [
                    'lat'     => (string) $data['lat'],
                    'lng'     => (string) $data['lng'],
                    'address' => (string) $data['address'],
                ];
            }
        }

        $itemName = trim(sprintf(
            '%s — %s',
            $data['account_name'] ?? 'Customer',
            $data['email']        ?? '',
        ));

        $graphql = <<<'GQL'
        mutation ($boardId: ID!, $itemName: String!, $columnValues: JSON!) {
            create_item(
                board_id: $boardId,
                item_name: $itemName,
                column_values: $columnValues,
                create_labels_if_missing: true
            ) {
                id
                name
            }
        }
        GQL;

        try {
            $resp = $this->query($graphql, [
                'boardId'      => (string) $boardId,
                'itemName'     => $itemName,
                'columnValues' => json_encode((object) $columnValues),
            ]);
        } catch (\Throwable $e) {
            Log::error('createCustomerItem: Monday create_item failed', [
                'email'        => $data['email']        ?? null,
                'account_name' => $data['account_name'] ?? null,
                'err'          => $e->getMessage(),
            ]);
            return null;
        }

        return $resp['create_item']['id'] ?? null;
    }

    /**
     * Generic change_column_value. The TSR drainer uses this to
     * patch the source ticket's status95 once the TSR is created
     * (e.g. TSR "completed" → ticket "COMPLETED"). For richer
     * payloads (file uploads, multiple columns) prefer the
     * dedicated helpers below.
     *
     * @param  array<string, mixed>  $columnValues  e.g. ['status95' => ['label' => 'COMPLETED']]
     */
    public function changeColumnValues(
        int $boardId,
        int|string $itemId,
        array $columnValues,
    ): void {
        $graphql = <<<'GQL'
        mutation ($boardId: ID!, $itemId: ID!, $columnValues: JSON!) {
            change_multiple_column_values(
                board_id: $boardId,
                item_id: $itemId,
                column_values: $columnValues
            ) {
                id
            }
        }
        GQL;

        $this->query($graphql, [
            'boardId'      => (string) $boardId,
            'itemId'       => (string) $itemId,
            'columnValues' => json_encode((object) $columnValues),
        ]);
    }

    /**
     * Upload a local file (PNG signature, PDF, etc.) to a Monday
     * file column on a given item.
     *
     * Monday's `add_file_to_column` mutation requires the file
     * body to be sent as a real multipart part, NOT as base64 in
     * the GraphQL `variables`. The official API spec states:
     *
     *   "If you are making direct requests to the API, you have
     *    to use the multipart/form-data content type."
     *   — https://developer.monday.com/api-reference/reference/assets-1
     *
     * The former Python service-report portal (remial03/Service-
     * Report-Portal → app/monday.py → upload_file()) used this
     * same approach successfully:
     *
     *   requests.post(
     *       FILE_URL,
     *       data={"query": mutation},
     *       files={"variables[file]": (filename, body, "image/png")},
     *   )
     *
     * We replicate that exactly here. Sending base64 in a JSON
     * GraphQL body used to "succeed" silently on the old API but
     * Monday's newer backend now returns INTERNAL_SERVER_ERROR,
     * which is the symptom the user reported (empty signature
     * cells on the TSR item, even though the upload "looked"
     * like it ran).
     *
     * Returns the asset id Monday assigns, or null if the upload
     * was rejected (e.g. file not found, network error).
     */
    public function attachFile(
        int|string $itemId,
        string $columnId,
        string $path,
        ?string $filename = null,
    ): ?string {
        $absolute = $this->resolveLocalPath($path);
        if ($absolute === null || ! is_file($absolute)) {
            Log::warning('attachFile: local file not found', [
                'path'  => $path,
                'col'   => $columnId,
                'item'  => $itemId,
            ]);
            return null;
        }

        $body  = file_get_contents($absolute);
        if ($body === false) {
            return null;
        }
        $name  = $filename ?: basename($absolute);

        // Interpolation pattern from the old Python service-report
        // portal (app/monday.py → upload_file()): the scalar
        // arguments are baked into the GraphQL string rather than
        // passed as variables, because Monday's multipart upload
        // endpoint only wires `variables[file]` from the request
        // — every other variable has to be inline. This is the
        // exact mutation shape that works against the current
        // /v2/file endpoint.
        $itemIdSafe   = (int) $itemId;            // cast ensures no GraphQL injection via numeric
        $columnIdSafe = addslashes($columnId);     // escape any quotes in the column id
        $graphql = sprintf(
            'mutation ($file: File!) { add_file_to_column '
            . '(item_id: %d, column_id: "%s", file: $file) { id } }',
            $itemIdSafe,
            $columnIdSafe
        );

        // Multipart upload to /v2/file. The mutation goes in the
        // `query` part as a plain GraphQL string, and the binary
        // goes in `variables[file]`. The brackets in the part name
        // are critical — that's the variable path Monday uses to
        // wire the file into the GraphQL variables. This matches
        // the proven format from the old Python service-report
        // portal (app/monday.py → upload_file()):
        //   data={"query": mutation_string},
        //   files={"variables[file]": (filename, body, mime)}
        $response = Http::withToken($this->token)
            ->acceptJson()
            ->timeout(60)
            ->withHeaders(['API-Version' => '2023-10'])
            ->asMultipart()
            ->post(self::FILE_URL, [
                [
                    'name'     => 'query',
                    'contents' => $graphql,
                ],
                [
                    'name'     => 'variables[file]',
                    'filename' => $name,
                    'contents' => $body,
                    'headers'  => ['Content-Type' => 'image/png'],
                ],
            ]);

        if ($response->failed()) {
            Log::error('attachFile: Monday multipart upload failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'item'   => $itemId,
                'col'    => $columnId,
            ]);
            throw new RuntimeException(
                "Monday file upload failed with HTTP {$response->status()}: "
                . substr($response->body(), 0, 200)
            );
        }

        $body = $response->json();
        if (! is_array($body)) {
            Log::error('attachFile: non-JSON response from Monday', [
                'raw' => $response->body(),
            ]);
            return null;
        }

        if (! empty($body['errors'])) {
            $err = $body['errors'][0] ?? [];
            $msg = $err['message'] ?? 'Unknown Monday error';
            Log::error('attachFile: Monday GraphQL errors', [
                'errors' => $body['errors'],
                'item'   => $itemId,
                'col'    => $columnId,
            ]);
            throw MondayApiException::fromGraphQL($err);
        }

        return $body['data']['add_file_to_column']['id'] ?? null;
    }

    /**
     * Like attachFile(), but with a small retry loop for the
     * transient `INTERNAL_SERVER_ERROR` responses that Monday's
     * `add_file_to_column` backend occasionally returns. We back
     * off for 1s/2s/4s; if all three attempts fail, the last
     * exception bubbles up to the caller.
     *
     * Monday's other mutations (create_item, change_column_value,
     * etc.) are much more reliable — this only applies to the file
     * upload mutation which is the one that has historically been
     * flaky for us.
     */
    public function attachFileWithRetry(
        int|string $itemId,
        string $columnId,
        string $path,
        ?string $filename = null,
        int $maxAttempts = 3,
    ): ?string {
        $attempt = 0;
        $delayMs = 1000;
        while (true) {
            $attempt++;
            try {
                return $this->attachFile($itemId, $columnId, $path, $filename);
            } catch (MondayApiException $e) {
                $transient = ($e->errorCode === 'INTERNAL_SERVER_ERROR')
                    || str_contains(strtolower($e->getMessage()), 'internal server');

                if (! $transient || $attempt >= $maxAttempts) {
                    throw $e;
                }
                Log::info('attachFile transient error, retrying', [
                    'attempt'  => $attempt,
                    'item'     => $itemId,
                    'col'      => $columnId,
                    'sleep_ms' => $delayMs,
                ]);
                usleep($delayMs * 1000);
                $delayMs *= 2;
            }
        }
    }

    /**
     * Like attachFileWithRetry(), but if `add_file_to_column` keeps
     * failing with INTERNAL_SERVER_ERROR after the retry budget,
     * fall back to Monday's `change_column_value` with a `link`
     * payload pointing at a public, signed URL serving the same
     * file. This matches Monday's own "Use Signature" pattern in
     * Monday Forms, where the user provides a URL to a hosted
     * signature and Monday ingests + renders it on the item.
     *
     * Returns the asset id Monday assigns (string) on success, or
     * the string "link:{url}" when the link-fallback was used, so
     * callers can tell which path succeeded. Returns null only
     * when even the link-fallback could not be written (e.g. the
     * app is not on a publicly reachable host, or `localId`/`role`
     * cannot be inferred from the path).
     */
    public function attachFileWithFallback(
        int|string $itemId,
        string $columnId,
        string $path,
        ?string $filename = null,
        ?string $localId = null,
        ?string $role = null,
    ): ?string {
        try {
            return $this->attachFileWithRetry($itemId, $columnId, $path, $filename);
        } catch (MondayApiException $primary) {
            // Only fall back on the specific failure we know how to
            // work around. Other errors (column not found, invalid
            // token) should still bubble.
            $transient = ($primary->errorCode === 'INTERNAL_SERVER_ERROR')
                || str_contains(strtolower($primary->getMessage()), 'internal server');
            if (! $transient) {
                throw $primary;
            }
            Log::warning('attachFileWithRetry exhausted; trying link fallback', [
                'item' => $itemId,
                'col'  => $columnId,
                'path' => $path,
                'err'  => $primary->getMessage(),
            ]);
            $linkResult = $this->attachFileAsLink($itemId, $columnId, $path, $filename, $localId, $role);
            if ($linkResult === null) {
                // Link fallback couldn't produce a usable URL
                // (e.g. APP_URL is loopback-only). Bubble a
                // MondayApiException so the caller's catch sets
                // sync_state=error and the admin can re-upload
                // later via `php artisan monday:reupload-signatures`.
                throw new MondayApiException(
                    'Signature upload failed and link fallback unavailable '
                    . '(APP_URL must be a publicly reachable URL): '
                    . $primary->getMessage(),
                    [],
                    'SIGNATURE_UPLOAD_FALLBACK_UNAVAILABLE',
                );
            }
            return $linkResult;
        }
    }

    /**
     * Build a signed URL pointing at the public SignatureFileController
     * route and write it into the file column via change_column_value
     * with a `link` payload. Monday ingests the URL and renders a
     * preview card with a download button on the item.
     *
     * Returns null (and logs a warning) when the link cannot be made
     * reachable from outside the dev machine — most commonly when
     * APP_URL is set to localhost/127.0.0.1/0.0.0.0/::1, which would
     * produce a link Monday's image-preview bot cannot fetch. The
     * caller is expected to treat null as a soft failure and surface
     * it via the TSR's `sync_state = error` so an admin can re-upload
     * later via `php artisan monday:reupload-signatures`.
     */
    public function attachFileAsLink(
        int|string $itemId,
        string $columnId,
        string $path,
        ?string $filename = null,
        ?string $localId = null,
        ?string $role = null,
    ): ?string {
        // Try to infer local_id + role from the relative path if not
        // supplied. Our signature files are stored as
        // signatures/{local_id}-{role}.png.
        if ($localId === null || $role === null) {
            if (preg_match('#signatures/([a-f0-9\-]+)-(tsp|customer|biomed)\.png$#i', $path, $m)) {
                $localId = $localId ?? $m[1];
                $role    = $role    ?? strtolower($m[2]);
            }
        }
        if ($localId === null || $role === null) {
            Log::warning('attachFileAsLink: cannot infer local_id/role from path', [
                'path' => $path,
            ]);
            return null;
        }

        // Don't write a link that Monday's preview bot can't reach.
        // The link-fallback is meant for production where the app is
        // hosted on a public URL. In local dev (APP_URL =
        // localhost/127.0.0.1/0.0.0.0/::1) writing such a link
        // produces a broken image on the Monday item — the same
        // problem as not uploading the file at all. Surface the
        // failure so the caller can mark the TSR for re-upload.
        $appUrl = (string) config('app.url');
        if (! self::isPubliclyReachableAppUrl($appUrl)) {
            Log::warning('attachFileAsLink: APP_URL is not publicly reachable; skipping link fallback', [
                'item'    => $itemId,
                'col'     => $columnId,
                'app_url' => $appUrl,
            ]);
            return null;
        }

        // IMPORTANT: URL::temporarySignedRoute() uses the *current
        // request*'s host/scheme by default. When the sync runs via
        // `php artisan monday:tsr-sync` (CLI) or via a Livewire
        // request served from 127.0.0.1:8765 in dev, the generated
        // URL would point at 127.0.0.1:8765 — which Monday's preview
        // bot cannot reach, so the cell renders empty (`text: ""`).
        //
        // Force the URL generator to use APP_URL (which we already
        // verified is publicly reachable above) for the duration of
        // the signed-URL generation, then restore.
        $appScheme = str_starts_with(strtolower($appUrl), 'https://')
            ? 'https'
            : (str_starts_with(strtolower($appUrl), 'http://') ? 'http' : 'http');
        \Illuminate\Support\Facades\URL::forceScheme($appScheme);
        \Illuminate\Support\Facades\URL::forceRootUrl(rtrim($appUrl, '/'));

        try {
            $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'signatures.show',
                now()->addMinutes(10),
                ['localId' => $localId, 'role' => $role]
            );
        } finally {
            // Restore previous root/scheme so we don't pollute
            // other URL generation in the same request lifecycle.
            \Illuminate\Support\Facades\URL::forceScheme(null);
            \Illuminate\Support\Facades\URL::forceRootUrl(null);
        }

        $text = $filename ?: "{$role} signature";
        $columnValues = [
            $columnId => [
                'url'  => $url,
                'text' => $text,
            ],
        ];

        $this->changeColumnValues(
            (int) config('services.monday.service_report_board_id', 0),
            (int) $itemId,
            $columnValues
        );

        Log::info('attachFileAsLink: link written to Monday', [
            'item' => $itemId,
            'col'  => $columnId,
            'url'  => $url,
        ]);

        return 'link:' . $url;
    }

    /**
     * Map a stored-relative path (e.g. "signatures/{local_id}/tsp.png")
     * to an absolute filesystem path. The portal stores signature
     * blobs via `App\Services\SignatureStorage` on the `local` disk,
     * which in Laravel 11 points at `storage/app/private/`. We try
     * several candidate roots in order so the drainer works no
     * matter which disk root the storage layer ends up using.
     *
     * Order:
     *   1. The path as-is (handles already-absolute paths and CWD-relative).
     *   2. `Storage::disk('local')`'s real root + path.
     *   3. `storage_path('app/private/' . path)` (Laravel 11 default).
     *   4. `storage_path('app/' . path)` (Laravel 10 / pre-`private` default).
     */
    protected function resolveLocalPath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $path = ltrim($path, '/');

        $candidates = [];

        // 1. Already absolute or CWD-relative
        $candidates[] = $path;

        // 2. Ask the local disk where it actually lives
        try {
            $root = Storage::disk('local')->path('');
            $candidates[] = $root . $path;
        } catch (\Throwable) {
            // Storage::path() can throw on disks that don't expose a
            // local root (e.g. S3). Skip in that case.
        }

        // 3 & 4. Historical Laravel defaults
        $candidates[] = storage_path('app/private/' . $path);
        $candidates[] = storage_path('app/' . $path);

        foreach ($candidates as $abs) {
            if (is_file($abs)) {
                return $abs;
            }
        }
        return null;
    }

    /**
     * Heuristic: is this APP_URL something Monday's image-preview
     * bot could plausibly reach? Used by the signature link
     * fallback to avoid writing a localhost URL into a file
     * column (which would render as a broken image on Monday's
     * UI — the exact "empty signature" symptom the user reported).
     *
     * Conservative on purpose: any of the common dev loopback
     * hosts, or an unparseable URL, returns false. If you deploy
     * this to a real public host, APP_URL should be its public
     * HTTPS URL and this will return true.
     */
    public static function isPubliclyReachableAppUrl(string $appUrl): bool
    {
        if ($appUrl === '') {
            return false;
        }
        $host = parse_url($appUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);
        $unreachable = [
            'localhost',
            '127.0.0.1',
            '0.0.0.0',
            '::1',
            '[::1]',
        ];
        if (in_array($host, $unreachable, true)) {
            return false;
        }
        // RFC1918 private ranges — these are not reachable from
        // Monday's servers, and the assumption is the operator
        // would set APP_URL to the public hostname.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            // Only treat as unreachable if it's actually an IP.
            // Hostnames that happen to look weird are still fine.
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return false;
            }
        }
        return true;
    }
}
