<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Actions\SubmitServiceReport;
use App\Actions\SyncPendingTsrReports;
use App\Enums\SyncState;
use App\Livewire\Tsp\Tickets\CreateServiceReport;
use App\Livewire\Tsp\Tickets\PendingSyncBadge;
use App\Models\ServiceReport;
use App\Models\User;
use Livewire\Livewire;

$pass = 0; $fail = 0;
function check(string $label, bool $cond, int &$pass, int &$fail): void
{
    if ($cond) { echo "  ✓ {$label}\n"; $pass++; }
    else       { echo "  ✗ {$label}\n"; $fail++; }
}

/**
 * Build a small but valid 8x1 PNG that exceeds the 200-byte minimum
 * SignatureStorage requires. Pure bytes - no GD required.
 *
 * Layout: 8x1 RGB, all black. Produced by hand using PNG spec:
 *   signature + IHDR + IDAT (zlib of 8x1 raw scanline) + IEND
 */
function testSignaturePng(): string
{
    // 500x140 RGB noisy PNG. Random pixel data so the deflate stream
    // doesn't compress below SignatureStorage's 200-byte floor.
    $w = 500; $h = 140;
    $ihdrBody = pack('N', $w) . pack('N', $h) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);
    $ihdrChunk = pngChunk('IHDR', $ihdrBody);
    mt_srand(1);
    $scanlines = '';
    for ($y = 0; $y < $h; $y++) {
        $scanlines .= chr(0);
        for ($x = 0; $x < $w; $x++) {
            $scanlines .= chr(mt_rand(0, 255)) . chr(mt_rand(0, 255)) . chr(mt_rand(0, 255));
        }
    }
    $idatBody = zlib_encode($scanlines, ZLIB_ENCODING_DEFLATE);
    if ($idatBody === false) {
        throw new RuntimeException('zlib_encode failed');
    }
    $idatChunk = pngChunk('IDAT', $idatBody);

    // IEND
    $iendChunk = pngChunk('IEND', '');

    $png = "\x89PNG\r\n\x1a\n" . $ihdrChunk . $idatChunk . $iendChunk;
    return 'data:image/png;base64,' . base64_encode($png);
}

function pngChunk(string $type, string $data): string
{
    $length = pack('N', strlen($data));
    $typeBytes = $type;
    $crc = pack('N', crc32($typeBytes . $data));
    return $length . $typeBytes . $data . $crc;
}

// ── 1. Routes ──────────────────────────────────────────────────────────────
echo "── 1. Routes ──\n";
$routes = collect(app('router')->getRoutes()->getRoutes())
    ->map(fn($r) => implode('|', $r->methods()) . ' ' . $r->uri());
$hasShow = $routes->contains(fn($r) => str_starts_with($r, 'GET|') && str_contains($r, 'tsp/tickets/'));
$hasSync = $routes->contains(fn($r) => str_contains($r, 'POST') && str_contains($r, '/tsr/sync'));
if (! $hasShow) {
    echo "  (sample: " . $routes->first(fn($r) => str_contains($r, 'tsp/tickets')) . ")\n";
}
check('GET /tsp/tickets/{id} is registered',          $hasShow, $pass, $fail, $routes->first());
check('POST /tsp/tickets/{id}/tsr/sync is registered', $hasSync, $pass, $fail);

// ── 2. Livewire component renders the right pieces ─────────────────────────
$tsp = User::where('email', 'remial.busa@mcbtsi.com')->first();
if (! $tsp) { echo "FAIL: TSP user not found in DB\n"; exit(1); }
auth()->login($tsp);

echo "\n── 2. Form render (mount with ticket number) ──\n";
$test = Livewire::test(CreateServiceReport::class, ['ticketNumber' => '2749008227'])
    ->assertSet('ticketNumber', '2749008227')
    ->assertSet('email', 'remial.busa@mcbtsi.com')
    ->assertSet('serviceStatus', 'in_progress');
$html = $test->html();
check('Ticket # label visible',         str_contains($html, 'Ticket #'),                                                $pass, $fail);
check('Ticket number value visible',    str_contains($html, '2749008227'),                                             $pass, $fail);
check('Local ID is a uuid',             (bool) preg_match('/localId&quot;:&quot;[0-9a-f-]{36}/', $html),             $pass, $fail);
check('Status select rendered',         str_contains($html, 'serviceStatus'),                                          $pass, $fail);
check('TSP signature pad rendered',     str_contains($html, 'x-data="signaturePad(\'tspSignatureDataUrl\''),          $pass, $fail);
check('Customer signature pad',         str_contains($html, 'x-data="signaturePad(\'customerSignatureDataUrl\''),     $pass, $fail);
check('BIOMED signature pad',           str_contains($html, 'x-data="signaturePad(\'biomedSignatureDataUrl\''),      $pass, $fail);
check('All three <canvas> elements',    substr_count($html, '<canvas') === 3,                                          $pass, $fail);
check('Submit button rendered',         str_contains($html, 'Submit report'),                                           $pass, $fail);

// ── 3. Validation: signatures are optional as of 2026-07-30 ────────────────
echo "\n── 3. Validation ──\n";
// Submit a minimally-valid TSR (email auto-fills, status defaults to
// in_progress) with all three signatures empty. Should succeed without
// any "signature" validation error and without writing any signature
// files to disk.
$minTest = Livewire::test(CreateServiceReport::class, ['ticketNumber' => '2749008227'])
    ->set('problemAndConcerns', 'Validation test: signatures intentionally omitted.')
    ->set('jobDone',           'n/a')
    ->set('machineSystemSerialNumber', 'TEST-001');
$localIdForMin = $minTest->get('localId');
$minTest->call('submit', app(SubmitServiceReport::class));
$err = $minTest->instance()->lastError;
if ($err !== null) {
    echo "  (err was: " . substr((string) $err, 0, 200) . ")\n";
}
check('Minimal TSR (no signatures) submits cleanly', $err === null, $pass, $fail);

// Now check the row in the DB - signature paths should be NULL.
$minRow = ServiceReport::where('local_id', $localIdForMin)->first();
check('Row was persisted',                          $minRow !== null, $pass, $fail);
check('tsp_signature_path is null',                 $minRow?->tsp_signature_path === null,      $pass, $fail);
check('customer_signature_path is null',            $minRow?->customer_signature_path === null, $pass, $fail);
check('biomed_signature_path is null',              $minRow?->biomed_signature_path === null,   $pass, $fail);
if ($minRow) {
    ServiceReport::where('local_id', $localIdForMin)->delete();
}

// ── 4. Happy path: submit a fully-filled TSR, expect a row in pending state ─
echo "\n── 4. Happy path submission ──\n";
$valid = Livewire::test(CreateServiceReport::class, ['ticketNumber' => '2749008227']);
$localId = $valid->get('localId');
check('localId is a uuid', (bool) preg_match('/^[0-9a-f-]{36}$/', $localId), $pass, $fail);

$valid->set('email', 'remial.busa@mcbtsi.com')
      ->set('problemAndConcerns', 'Customer reports intermittent BSOD during imaging sequences.')
      ->set('jobDone', 'Replaced faulty RAM module, ran 3-hour burn-in test, all clear.')
      ->set('partsReplaced', '16GB DDR4 ECC module (PN 5542-A)')
      ->set('recommendation', 'Schedule replacement of remaining 3 modules within 6 months.')
      ->set('remarks', 'Customer very satisfied.')
      ->set('logInDate', '2026-06-21T08:00')
      ->set('serviceStartDateTime', '2026-06-21T08:30')
      ->set('serviceEndDateTime', '2026-06-21T11:45')
      ->set('logOutDate', '2026-06-21T12:00')
      ->set('machineSystemSerialNumber', 'SN-MC-2025-0001')
      ->set('softwareVersionNo', 'v4.2.1')
      ->set('tspSignatureName', 'Remial Busa')
      ->set('tspSignatureDataUrl', testSignaturePng())
      ->set('customerName', 'Dr. Cruz')
      ->set('customerEmail', 'cruz@hospital.test')
      ->set('customerSignatureDataUrl', testSignaturePng())
      ->set('biomedName', 'Eng. Tan')
      ->set('biomedEmail', 'tan@hospital.test')
      ->set('biomedSignatureDataUrl', testSignaturePng())
      ->set('tspWorkWithCsv', '77787515, 77787561')
      // Disable the immediate-drain-to-Monday that the action
      // would do in production — the test ticket 2749008227 is a
      // fake number, so the drainer would either fail (marking
      // the row Error) or talk to real Monday. Either way it
      // would change sync_state away from 'pending' and break
      // the assertions below. The offline drainer pipeline is
      // covered separately by tests 5–7.
      ->call('submit', tap(app(SubmitServiceReport::class), fn ($a) => $a->syncAfterCommit = false));

$row = ServiceReport::where('local_id', $localId)->first();
check('Row created in DB', $row !== null, $pass, $fail);
if ($row) {
    check('monday_ticket_id stored',                (string) $row->monday_ticket_id === '2749008227', $pass, $fail);
    check('user_id matches logged-in TSP',          (int) $row->user_id === (int) $tsp->id,            $pass, $fail);
    check('Sync state is pending',                  $row->sync_state === SyncState::Pending,           $pass, $fail);
    check('TSP signature file saved',               ! empty($row->tsp_signature_path),                 $pass, $fail);
    check('Customer signature file',                ! empty($row->customer_signature_path),            $pass, $fail);
    check('BIOMED signature file',                  ! empty($row->biomed_signature_path),              $pass, $fail);
    check('Signature file exists on disk',          $row->tsp_signature_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($row->tsp_signature_path), $pass, $fail);
    check('Problem text stored',                    str_contains($row->problem_and_concerns ?? '', 'BSOD'), $pass, $fail);
    check('Work-with array stored (2 co-TSPs)',     is_array($row->tsp_workwith_person_ids) && count($row->tsp_workwith_person_ids) === 2, $pass, $fail);
    check('Total minutes computed (3h 15m = 195)',  (int) $row->total_minutes === 195,                $pass, $fail);
    check('customer_incharge_email set',            $row->customer_incharge_email === 'cruz@hospital.test', $pass, $fail);
    check('biomed_email set',                       $row->biomed_email === 'tan@hospital.test',       $pass, $fail);
}

// ── 5. Idempotency: a second submit with the same local_id updates, not duplicates
echo "\n── 5. Idempotency ──\n";
$valid2 = Livewire::test(CreateServiceReport::class, ['ticketNumber' => '2749008227']);
$valid2->set('localId', $localId)
       ->set('email', 'remial.busa@mcbtsi.com')
       ->set('problemAndConcerns', 'Customer reports intermittent BSOD during imaging sequences.')
       ->set('jobDone', 'Updated narrative after second visit: re-tested, customer happy.')
       ->set('partsReplaced', '16GB DDR4 ECC module (PN 5542-A)')
       ->set('recommendation', 'Schedule replacement of remaining 3 modules within 6 months.')
       ->set('remarks', 'Customer very satisfied.')
       ->set('logInDate', '2026-06-21T08:00')
       ->set('serviceStartDateTime', '2026-06-21T08:30')
       ->set('serviceEndDateTime', '2026-06-21T11:45')
       ->set('logOutDate', '2026-06-21T12:00')
       ->set('machineSystemSerialNumber', 'SN-MC-2025-0001')
       ->set('softwareVersionNo', 'v4.2.1')
       ->set('tspSignatureName', 'Remial Busa')
       ->set('tspSignatureDataUrl', testSignaturePng())
       ->set('customerName', 'Dr. Cruz')
       ->set('customerEmail', 'cruz@hospital.test')
       ->set('customerSignatureDataUrl', testSignaturePng())
       ->set('biomedName', 'Eng. Tan')
       ->set('biomedEmail', 'tan@hospital.test')
       ->set('biomedSignatureDataUrl', testSignaturePng())
       // Disable immediate-drain for the same reason as section 4:
       // the test ticket is fake, and section 6's badge assertion
       // depends on the row staying in 'pending' state.
       ->call('submit', tap(app(SubmitServiceReport::class), fn ($a) => $a->syncAfterCommit = false));

$count = ServiceReport::where('local_id', $localId)->count();
check('Same local_id does not create a duplicate row', $count === 1, $pass, $fail);
$row2 = ServiceReport::where('local_id', $localId)->first();
check('Job done updated in place', str_contains($row2->job_done ?? '', 'second visit'), $pass, $fail);

// ── 6. PendingSyncBadge component ─────────────────────────────────────────
echo "\n── 6. PendingSyncBadge component ──\n";
try {
    $badgeTest = Livewire::test(PendingSyncBadge::class, ['ticketNumber' => '2749008227']);
    $badgeHtml = $badgeTest->html();
    check('PendingSyncBadge renders without error', true, $pass, $fail);
    check('Badge shows data-pending attr',   str_contains($badgeHtml, 'data-pending'),   $pass, $fail);
    check('Badge shows data-errored attr',   str_contains($badgeHtml, 'data-errored'),   $pass, $fail);
    check('Badge counts match (1 pending)',  str_contains($badgeHtml, 'data-pending="1"'),$pass, $fail);
} catch (\Throwable $e) {
    check('PendingSyncBadge renders without error', false, $pass, $fail);
    echo "    error: " . $e->getMessage() . "\n";
}

// ── 7. Drainer runs but does nothing if MondayClient has no createItem yet ─
echo "\n── 7. Drainer dry-run ──\n";
try {
    $drainResult = app(SyncPendingTsrReports::class)->execute(5);
    check('Drainer ran without throwing', is_array($drainResult), $pass, $fail);
    check('Drainer returned processed key', is_array($drainResult) && array_key_exists('processed', $drainResult), $pass, $fail);
} catch (\Throwable $e) {
    check('Drainer ran without throwing', false, $pass, $fail);
    echo "    drainer error: " . $e->getMessage() . "\n";
}

// ── 8. Cleanup test rows (so the test is rerunnable) ──────────────────────
echo "\n── 8. Cleanup ──\n";
$deleted = ServiceReport::where('local_id', $localId)->delete();
check("Cleaned up test row (local_id={$localId})", $deleted >= 1, $pass, $fail);

// ── Summary ────────────────────────────────────────────────────────────────
echo "\n══════════════════════════════════════════════════════════════════\n";
echo "RESULTS  (passed: {$pass}, failed: {$fail})\n";
echo "══════════════════════════════════════════════════════════════════\n\n";
exit($fail === 0 ? 0 : 1);
