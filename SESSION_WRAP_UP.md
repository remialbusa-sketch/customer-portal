# Customer Portal — Session Wrap-Up

Date: 2026-08-13 · Repo: `github.com/remialbusa/customer-portal` · Branch: `main`

---

## 1. What shipped this session

### Ticket transfer workflow (in-browser, TSP ↔ TSP)
- Root cause fixed: Livewire morph dropped top-level siblings — the transfer modal now lives inside the root `<div>`.
- **Cross-branch transfers:** the transfer modal gained a scope toggle (Same branch / All branches) via `Dashboard.php` (`transferScope`, `setTransferScope`, `loadTransferTargets`). Users outside your branch show a "Cross-branch" badge.
- **Full roster always visible:** all 41 TSP accounts are listed in the modal. Anyone without a Monday ID is rendered disabled with a "No Monday account linked" tooltip (fixed silently-missing candidates).
- Pending-transfer pill (My tickets card) uses `$pendingTransferByTicket`; scope-aware empty states; `wire:loading.attr` scoped so only the target column spins.

### Monday account linking (all 41 TSPs now assignable)
- Found 14 TSPs with `monday_id = NULL` even though they exist on Monday (never synced). Linked 13 directly via tinker using their real Monday user IDs.
- **John Erick Hernandez (user id 50):** local email was `john.hernandez@mcbtsi.com` (wrong); corrected to `johnerick.hernandez@mcbtsi.com` + `monday_id = 77787547`. **His login email changed.**
- All 41 TSP accounts share the default bcrypt password `Password!123` (users are expected to change it).

### Status transitions & naming
- **AWAITING → IN-PROGRESS** automatically when a TSP sends the first response: `MondayClient::markTicketResponded()` reads the current `status95` (cache-busted via `forgetItemCache`) before writing `RESPONDED`, writes `IN-PROGRESS` when it was `AWAITING`, and dispatches `TicketStatusChanged` (the JS banner listeners already existed — this is the first time the event is actually fired). Wrapped in try/catch (best-effort).
- **Ticket naming = Monday item name** (e.g. `TICKET-00079`) across the UI: dashboard chips, claim modal, transfer modal header (`transferTicketName` prop), ticket detail headings, chat panel headers (new `ticketName` prop), and the TSR form heading. Left as numeric ID on purpose: TSR sync-error rows, the offline TSR `ticket_number` field, alert emails.
- **Branding:** favicon `favicon-mcbio.png` + login/welcome `icon-mcbio.png` under `public/images/brand/`; title now **"MCBIO SERVICE PORTAL"** (`.env` `APP_NAME` + layouts).

### TSP alert email (temporarily disabled)
- Verified the flow works: `TicketCreated` → `SendTspAlertForNewTicket` emailed all 14 active NCR TSPs (signed acknowledge links) when TICKET-00079 was created — confirmed in `storage/logs/laravel.log`.
- **Disabled for now** (user's choice — evaluating a Monday.com automation instead): `Event::listen(TicketCreated::class, SendTspAlertForNewTicket::class)` is commented out in `app/Providers/AppServiceProvider.php`. Re-enable by uncommenting. Note `MAIL_MAILER=log` (dev) and `ticket_acknowledgements` still has 0 rows.

### Escalation (already built-in, no code needed)
- TSR form offers **Escalated** status → `TsrStatusMapper` maps it to ticket `status95 = ESCALATED` via the drainer. Gap: nobody is notified when the ticket flips to ESCALATED. **Not implemented** — offered to the user as a realtime toast; user hasn't confirmed yet.

### Tests & tooling
- `php artisan test`: **61 tests, 57 passed, 4 skipped** — green after every change.
- New: `TicketTransferTest` (6 tests), `ChatRespondedTest`, `CustomerAutoProvisionTest`, `must_change_password` + `ticket_transfers` migrations, change-password flow, `TicketTransferRequested`/`TicketTransferred` events + model.
- `npm audit fix` → 0 vulnerabilities; `npm run build` clean (Vite 8, `public/build` gitignored).

### Git
- Committed & pushed: **`25998a1`** — 56 files changed (+2511/−2571); includes the new events/model/migrations/tests, favicon assets, and removal of invite/register auth files.
- **Uncommitted (pending):** the cross-database migration fix below + `portal/package-lock.json` and root `package-lock.json` (npm). Nothing else is dirty.

---

## 2. Deployment (production = cPanel shared hosting)

**Live URL: `https://srf.mcbtsi.com`** (doc root: `/home4/mcbtsjq1/customer-portal.mcbtsi.com/portal/public`)

Why `srf.mcbtsi.com`: the `customer-portal` subdomain was created in cPanel but **its DNS never existed** — `mcbtsi.com` uses external nameservers (`sanverhost.*.orderbox-dns.com`), so cPanel cannot add records. `srf.mcbtsi.com` already had an A record (162.215.240.40) + a valid SSL cert, so it was repurposed as the doc root. Any new subdomain must get its A record added at the OrderBox/reseller DNS panel, not cPanel.

### What was deployed
- `composer install --no-dev --optimize-autoloader` on the server (warnings about missing package dirs during dev-package removal are cosmetic).
- Production `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://srf.mcbtsi.com`, MySQL (`mcbtsjq1_portal_db`), `MAIL_MAILER=log`, `BROADCAST_CONNECTION=null`, and the five `MONDAY_*` keys (**`.env.example` is missing them — copy from `.env`**).
- `php artisan migrate --force` succeeded on the server.
- **Production data was NOT seeded** — the seeders are incomplete (TspUsersSeeder only has 29 of the 44 TSP accounts) and would create weak-password demo accounts. Instead: `portal-data.sql` (generated from the local sqlite DB) is imported via phpMyAdmin — all 50 users (44 TSPs with correct Monday IDs, 4 customers, admins), 2 machines, 15 chat messages, 1 ticket transfer. **Do not run `db:seed` in production.**
- `storage/` is gitignored and excluded from deploys — recreate the skeleton on the server (app/public, framework/views, framework/cache/data, framework/sessions, logs) with 775 perms.

### Migration fix (uncommitted)
`2026_07_20_120000_add_discarded_state_to_service_reports.php` was SQLite-only (`sqlite_master` rebuild) and crashed MySQL with a 1064. Rewritten to branch on driver:
- MySQL → native `ENUM('pending','syncing','synced','error','discarded')` via `Schema::table()->change()`
- SQLite → the original table-rebuild path (local dev unchanged)

**This file must be committed** so the next deploy carries it. It was already applied on the server.

### Deploy artifacts
- `portal-deploy.zip` (3 MB, no vendor) at the repo root — reference only; prefer rsync/CI from now on.
- `portal-data.sql` (17 KB) at the repo root — the production data import.

---

## 3. Known gotchas / decisions
- `public/build` is gitignored → **any CI/CD must run `npm run build` and ship the assets**, or the site loads without CSS/JS.
- No queue worker and no cron are needed (alert listener runs synchronously; `schedule:list` is empty). `QUEUE_CONNECTION=database` is fine without a worker.
- Dev environment: `MAIL_MAILER=log`, `BROADCAST_CONNECTION=null`, SQLite. Realtime chat relies on the 3s polling endpoint; Pusher is configured-but-optional (see comments in `.env`).
- Live test ticket **2829924331 (TICKET-00079, AWAITING)** is assigned to Kenneth Amor (user id 26) — do not revert.
- TSP reminder: passwords are temporary defaults; the `must_change_password` flow exists for forcing resets.

---

## 4. Next steps (not yet done)
- Commit the migration fix (+ lockfiles), then optionally commit this file.
- **CI/CD:** a deploy workflow (`deploy.yml`, GitHub Actions → rsync + ssh composer/migrate/caches) was designed but **not added to the repo** — user hasn't confirmed. See the conversation for the full YAML.
- Optional: realtime toast/banner when a TSR flips a ticket to ESCALATED (offered, not confirmed).
- Optional: `DEPLOYMENT.md` in the repo; escalation cron command (deferred).
- If a new subdomain is ever needed: add the A record at the OrderBox DNS panel *before* expecting it to resolve.