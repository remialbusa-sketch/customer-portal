<?php
/**
 * Manual end-to-end test of the open-invite registration flow.
 *
 * This exercises the same Livewire/Volt component the browser does,
 * but without needing a browser or Playwright. It will:
 *   1. Verify an open invite exists (or create one if not).
 *   2. Render the component with the invite token.
 *   3. Fill in the form and submit.
 *   4. Hit the REAL MondayClient::createCustomerItem() — yes, this
 *      will create a row on the live Monday.com Customer board.
 *   5. Verify the user + machine were created locally.
 *   6. Print a full report.
 *
 * This intentionally does NOT use RefreshDatabase — it operates on
 * the same SQLite/MySQL DB the dev server is using. Pass a different
 * invite email on the command line to avoid collisions.
 */

$root = __DIR__;
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();


use App\Models\CustomerInvite;
use App\Models\Machine;
use App\Models\User;
use App\Services\MondayClient;
use App\Services\MondayCustomerDirectory;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;

$email = $argv[1] ?? 'ramenizing@mcbtsi.com';
$name  = $argv[2] ?? 'Ram Enizing';
$token = $argv[3] ?? null;

echo "================================================\n";
echo " MANUAL E2E TEST: open-invite registration flow\n";
echo "================================================\n";
echo "Email: $email\n";
echo "Name:  $name\n";
echo "Time:  " . now() . "\n\n";

// Sanity check: can we even talk to Monday?
echo ">> Step 0: Ping Monday.com API (read customers board count)...\n";
try {
    $monday = $app->make(MondayClient::class);
    $data   = $monday->query('query { me { id name email } }');
    $me     = $data['me'] ?? null;
    if (! $me) {
        echo "   FAILED — no `me` returned. Aborting.\n";
        exit(1);
    }
    echo "   OK — connected as Monday user: {$me['name']} (id={$me['id']})\n\n";
} catch (\Throwable $e) {
    echo "   FAILED — " . $e->getMessage() . "\n";
    echo "   Aborting before we wreck anything.\n";
    exit(1);
}

// Find or create the invite
echo ">> Step 1: Find/create open invite for $email\n";
$invite = $token
    ? CustomerInvite::where('token', $token)->first()
    : CustomerInvite::where('email', $email)->whereNull('used_at')->latest()->first();

if (! $invite) {
    echo "   No invite found — creating one (issued by remial.busa).\n";
    $invite = CustomerInvite::create([
        'email'        => $email,
        'token'        => 'manual-' . bin2hex(random_bytes(16)),
        'is_snapshot'  => false,
        'issued_by'    => User::where('email', 'remial.busa@mcbtsi.com')->value('id'),
        'expires_at'   => now()->addDays(14),
    ]);
}
echo "   Invite: token={$invite->token}\n";
echo "            is_snapshot=" . ($invite->is_snapshot ? 'true' : 'false') . "\n";
echo "            expires_at={$invite->expires_at}\n";
echo "            used_at=" . ($invite->used_at ?: 'NULL') . "\n\n";

if ($invite->isSnapshot()) {
    echo "   That invite is a SNAPSHOT. We want an OPEN one for this test.\n";
    echo "   Pick a different email or use a snapshot invite separately.\n";
    exit(1);
}

if ($invite->isUsed()) {
    echo "   That invite is already USED. Pick a different email.\n";
    exit(1);
}

// Pre-test snapshot of the DB
$userCountBefore    = User::count();
$machineCountBefore = Machine::count();
$ramenizing         = User::where('email', $email)->first();
echo ">> Step 2: Pre-test DB state\n";
echo "   users total:    $userCountBefore\n";
echo "   machines total: $machineCountBefore\n";
echo "   user($email): " . ($ramenizing ? "EXISTS (id={$ramenizing->id})" : "absent") . "\n\n";

if ($ramenizing) {
    echo "   $email already has a user account. Pick a different email.\n";
    exit(1);
}

// Mount the component. We pass the token through the same mechanism the
// route does (Volt reads the route param "token").
echo ">> Step 3: Mount the register component with token\n";
try {
    $test = Volt::test('pages.auth.register', ['token' => $invite->token]);
    echo "   OK — component mounted.\n";
    echo "   isOpenInvite:   " . var_export($test->get('isOpenInvite'), true) . "\n";
    echo "   needsInvite:    " . var_export($test->get('needsInvite'),  true) . "\n";
    echo "   inviteInvalid:  " . var_export($test->get('inviteInvalid'), true) . "\n";
    echo "   email pre-fill: " . var_export($test->get('email'), true) . "\n\n";
} catch (\Throwable $e) {
    echo "   FAILED — " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}


// Fill the form and submit
echo ">> Step 4: Fill form fields & submit\n";
try {
    $test->set('name', $name)
         ->set('password', 'Password!123')
         ->set('password_confirmation', 'Password!123')
         ->set('accountName', "Ram Enizing Diagnostic Lab")
         ->set('region', 'NCR')
         ->set('branch', 'Manila')
         ->set('address', '123 Rizal Ave, Manila')
         ->set('primaryBrand', 'Mindray')
         ->set('primaryModel', 'BC-6800')
         ->set('primarySerial', 'SN-MANUAL-001')
         ->set('primaryNickname', 'Hematology main')
         ->call('register');

    if ($test->errors()->isNotEmpty()) {
        echo "   ✗ Form has errors:\n";
        foreach ($test->errors()->all() as $err) {
            echo "      - $err\n";
        }
        echo "\n   Nothing was created. Re-run with a fresh email to retry.\n";
        exit(2);
    }
    echo "   ✓ Submit returned no validation errors.\n\n";

} catch (\Throwable $e) {
    echo "   ✗ Submit threw: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(2);
}

// Post-test DB state
echo ">> Step 5: Post-test DB state\n";
$user = User::where('email', $email)->first();
if (! $user) {
    echo "   ✗ User was NOT created!\n";
    exit(2);
}
echo "   ✓ User created:\n";
echo "      id:           {$user->id}\n";
echo "      name:         {$user->name}\n";
echo "      email:        {$user->email}\n";
echo "      role:         {$user->role}\n";
echo "      status:       {$user->status}\n";
echo "      monday_id:    " . ($user->monday_id ?? '(null)') . "\n";
echo "      account_name: " . ($user->account_name ?? '(null)') . "\n";
echo "      branch:       " . ($user->branch ?? '(null)') . "\n";
echo "      region:       " . ($user->region ?? '(null)') . "\n";
echo "      address:      " . ($user->address ?? '(null)') . "\n";

$machines = Machine::where('user_id', $user->id)->get();
echo "\n   ✓ Machines created: " . $machines->count() . "\n";
foreach ($machines as $m) {
    echo "      - " . ($m->is_primary ? '[PRIMARY] ' : '[secondary] ');
    echo "{$m->brand} {$m->model}";
    if ($m->serial_number) echo " (SN: {$m->serial_number})";
    if ($m->nickname)      echo " — \"{$m->nickname}\"";
    echo "\n";
}

$invite->refresh();
echo "\n   Invite state after submit:\n";
echo "      used_at:            " . ($invite->used_at ?: 'NULL') . "\n";
echo "      used_by_user_id:    " . ($invite->used_by_user_id ?? 'NULL') . "\n\n";

// Cross-check with Monday.com — pull the item by id and confirm it exists
if ($user->monday_id) {
    echo ">> Step 6: Cross-check Monday.com for the new item\n";
    try {
        $query = <<<GQL
        query (\$id: ID!) {
            items(ids: [\$id]) {
                id
                name
                column_values { id text }
            }
        }
        GQL;
        $data = $monday->query($query, ['id' => $user->monday_id]);
        $item = $data['items'][0] ?? null;
        if ($item) {
            echo "   ✓ Monday.com item found:\n";
            echo "      id:   {$item['id']}\n";
            echo "      name: {$item['name']}\n";
            echo "      columns:\n";
            foreach ($item['column_values'] as $col) {
                $val = $col['text'] ?? '';
                if ($val !== '') echo "        {$col['id']}: {$val}\n";
            }
        } else {
            echo "   ✗ Monday.com item NOT found for id={$user->monday_id}\n";
            echo "   (this could be a propagation delay or the column mapping is off)\n";
        }
    } catch (\Throwable $e) {
        echo "   ✗ Cross-check failed: " . $e->getMessage() . "\n";
    }
}

echo "\n================================================\n";
echo " TEST COMPLETE\n";
echo "================================================\n";
echo "All Done.\n";
