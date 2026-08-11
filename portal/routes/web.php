<?php

use App\Http\Controllers\Admin\KpiController;
use App\Http\Controllers\Tsp\TspDashboardController;
use App\Http\Controllers\Customer\CustomerDashboardController;
use App\Http\Controllers\Customer\TicketController;
use App\Http\Controllers\Customer\ChatController as CustomerChatController;
use App\Http\Controllers\Tsp\ChatController as TspChatController;
use App\Http\Controllers\Tsp\InternalNoteController as TspInternalNoteController;
use App\Http\Controllers\Tsp\TimeEntryController as TspTimeEntryController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

// Customer-facing routes
Route::middleware(['auth', 'role:customer'])->prefix('')->name('')->group(function () {
    Route::get('/dashboard', [CustomerDashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('/tickets/new', [TicketController::class, 'create'])
        ->name('tickets.create');
    Route::post('/tickets', [TicketController::class, 'store'])
        ->name('tickets.store');

    // Ticket detail + chat (Phase 3)
    Route::get('/tickets/{id}', [CustomerChatController::class, 'show'])
        ->name('tickets.show');
    Route::post('/tickets/{id}/chat', [CustomerChatController::class, 'send'])
        ->name('tickets.chat.send');
    // Polling endpoint for real-time chat on shared hosting
    // (no Pusher). See Customer\ChatController::poll.
    Route::get('/tickets/{id}/chat/messages', [CustomerChatController::class, 'poll'])
        ->name('tickets.chat.poll');
});

// TSP-facing routes (FSE, ITS, Manager, or Admin)
Route::middleware(['auth', 'role:fse,its,manager,admin'])->prefix('tsp')->name('tsp.')->group(function () {
    Route::get('/dashboard', [TspDashboardController::class, 'index'])
        ->name('dashboard');

    // Claim an unclaimed ticket from the regional pool. The TSP's
    // person ID is written into the People column on Monday and
    // the response status is flipped to "RESPONDED".
    Route::post('/tickets/{id}/claim', [TspDashboardController::class, 'claim'])
        ->name('tickets.claim');

    // Ticket detail + chat + internal notes (Phase 4).
    // Phase 3's TspChatController@show has been replaced by
    // TspInternalNoteController@show which renders both surfaces.
    Route::get('/tickets/{id}', [TspInternalNoteController::class, 'show'])
        ->name('tickets.show');

    // Customer-facing chat send (the chat panel on the TSP ticket
    // detail page posts here so the message goes through the same
    // controller pipeline as a customer-originated send).
    Route::post('/tickets/{id}/chat', [TspChatController::class, 'send'])
        ->name('tickets.chat.send');

    // Polling endpoint for real-time chat on shared hosting.
    // See Tsp\ChatController::poll.
    Route::get('/tickets/{id}/chat/messages', [TspChatController::class, 'poll'])
        ->name('tickets.chat.poll');

    // Internal notes (Phase 4): TSP-only, mirrored to a dedicated
    // Monday long-text column.
    Route::post('/tickets/{id}/notes', [TspInternalNoteController::class, 'store'])
        ->name('tickets.notes.store');

    // Time tracker (Phase 5): start/pause/resume/stop on a ticket.
    // (Kept for backward-compat; the new UI is a read-only reflection
    // of Monday's `duration_mm4hesrz` and doesn't call these.)
    Route::post('/tickets/{id}/time/start',  [TspTimeEntryController::class, 'start'])
        ->name('tickets.time.start');
    Route::post('/tickets/{id}/time/pause',  [TspTimeEntryController::class, 'pause'])
        ->name('tickets.time.pause');
    Route::post('/tickets/{id}/time/resume', [TspTimeEntryController::class, 'resume'])
        ->name('tickets.time.resume');
    Route::post('/tickets/{id}/time/stop',   [TspTimeEntryController::class, 'stop'])
        ->name('tickets.time.stop');

    // Read-only reflection of Monday's `duration_mm4hesrz` time_tracking
    // column. Polled by the Livewire time-tracker component every 30s
    // (or on demand) so the UI is always in sync with Monday, which is
    // the new source of truth.
    Route::get('/tickets/{id}/time-tracking', [TspTimeEntryController::class, 'state'])
        ->name('tickets.time.state');

    // Service report (Phase 6): TSP writes the post-service report
    // and the ticket's customer-facing status flips automatically.
    Route::get('/service-reports/{id}', [\App\Http\Controllers\Tsp\ServiceReportController::class, 'show'])
        ->name('service-reports.show');
    Route::get('/tickets/{id}/tsr/create', [\App\Http\Controllers\Tsp\ServiceReportController::class, 'create'])
        ->name('tickets.tsr.create');
    Route::post('/tickets/{id}/service-report', [\App\Http\Controllers\Tsp\ServiceReportController::class, 'store'])
        ->name('tickets.service-report.store');

    // The offline-JS drainer endpoint. Hits the SyncPendingTsrReports
    // action. Same auth as the form. The form's "Sync to Monday"
    // button POSTs here, and the browser also fires it on the
    // `online` event.
    Route::post('/tickets/{id}/tsr/sync', [\App\Http\Controllers\Tsp\ServiceReportController::class, 'sync'])
        ->name('tickets.tsr.sync');

    // Read-only per-ticket sync status (used by the form's sticky
    // bar to display "Queued / Syncing / Synced / Error" pills).
    Route::get('/tickets/{id}/tsr/status', [\App\Http\Controllers\Tsp\ServiceReportController::class, 'status'])
        ->name('tickets.tsr.status');

    // Lightweight JSON snapshot of a ticket's status, subject,
    // and TSR presence. Polled by the ticket-show page's Alpine
    // poller every 15s so a status change made by another tab
    // (manager, customer, or a co-TSP) shows up without a hard
    // reload. The controller authorizes access the same way
    // tickets.show does.
    Route::get('/tickets/{id}/status.json', [TspInternalNoteController::class, 'statusJson'])
        ->name('tickets.status');

    // POST /tsp/tickets/{id}/acknowledge — record an acknowledgement
    // for the signed-in TSP. The corresponding GET is signed + public
    // (see the public block below) so the email-from-phone flow works
    // even before login.
    Route::post('/tickets/{id}/acknowledge', [\App\Http\Controllers\Tsp\TspTicketAcknowledgementController::class, 'acknowledge'])
        ->name('tickets.acknowledge');
});

// Admin / executive routes. The outer group accepts either `admin`
// or `superadmin` so the superadmin can reach the dashboard and
// the invites section with one role grant.
Route::middleware(['auth', 'role:superadmin,admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/kpi', [KpiController::class, 'index'])->name('kpi');

    // Invites UI — both admins and superadmins can use it. The nav
    // link is shown only to superadmins so admins aren't surprised
    // by an extra menu item.
    Route::get('/invites', [\App\Http\Controllers\Admin\InviteController::class, 'index'])
        ->name('invites');
    Route::post('/invites', [\App\Http\Controllers\Admin\InviteController::class, 'store'])
        ->name('invites.store');
});

// Account-deletion request inbox. Superadmin-only — non-superadmin
// admins can see customer invites but NOT the delete-account queue.
// We use `role:superadmin` (not the outer `superadmin,admin` group)
// to enforce that.
Route::middleware(['auth', 'role:superadmin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/deletion-requests', [\App\Http\Controllers\Admin\AccountDeletionRequestController::class, 'index'])
        ->name('deletion-requests');
    Route::post('/deletion-requests/{deletionRequest}/approve', [\App\Http\Controllers\Admin\AccountDeletionRequestController::class, 'approve'])
        ->name('deletion-requests.approve');
    Route::post('/deletion-requests/{deletionRequest}/reject', [\App\Http\Controllers\Admin\AccountDeletionRequestController::class, 'reject'])
        ->name('deletion-requests.reject');
});

// Customer/TSP self-service: file a request to delete their own
// account. Superadmins use Breeze's self-delete component instead.
Route::middleware(['auth'])->group(function () {
    Route::post('/profile/deletion-request', [\App\Http\Controllers\ProfileDeletionRequestController::class, 'store'])
        ->name('profile.deletion-request.store');
    Route::post('/profile/deletion-request/cancel', [\App\Http\Controllers\ProfileDeletionRequestController::class, 'cancel'])
        ->name('profile.deletion-request.cancel');
});

// Public, time-limited signed route to serve a stored signature file
// to Monday (which needs a reachable URL to ingest the image). Monday
// cannot authenticate to our portal, so the route relies on a signed
// URL with a 10-minute expiry and a path that includes the local_id
// + signature role, so the URL itself is unguessable.
Route::get('signatures/{localId}/{role}.png', [\App\Http\Controllers\SignatureFileController::class, 'show'])
    ->middleware('signed')
    ->where('role', 'tsp|customer|biomed')
    ->name('signatures.show');

// Public, time-limited signed confirmation page for TSP ticket
// acknowledgement. The email link points here; the page renders a
// human-in-the-loop "Confirm acknowledgement" form that posts to
// the authed route above. We can't put the POST behind the signed
// middleware because the auth middleware runs first and would
// bounce an un-signed-in TSP to /login before we even check the
// signature.
Route::get('tsp/tickets/{id}/acknowledge', [\App\Http\Controllers\Tsp\TspTicketAcknowledgementController::class, 'show'])
    ->middleware('signed')
    ->name('tsp.tickets.acknowledge.show');

require __DIR__.'/auth.php';
