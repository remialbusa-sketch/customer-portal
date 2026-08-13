<?php
/**
 * Manual end-to-end test of the NEW customer first-login flow.
 *
 * Invites are gone. A customer added on the monday.com Customer
 * Details board gets an email (Monday automation) with the default
 * password and logs in for the first time. This script exercises
 * the same code paths the browser does, without a browser:
 *   1. Ping the monday.com API.
 *   2. Pick a customer on the Customer Details board.
 *   3. Drive LoginForm with the DEFAULT password — the exact call
 *      the Livewire login page makes.
 *   4. Verify the navigation shows the temporary-password notice
 *      (with "Set password now" / "Set up later").
 *   5. Drive the password change page (pages.auth.change-password).
 *   6. Verify the flag was cleared, the password works, and the
 *      notice is gone.
 *
 * This intentionally does NOT use RefreshDatabase — it operates on
 * the same SQLite/MySQL DB the dev server is using.
 *
 * Usage:
 *   php manual-test.php [customer@email.test] [--force]
 *
 *   --force deletes an existing local account for the email first so
 *   the first-login path can be re-tested from scratch.
 */

$root = __DIR__;
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Livewire\Forms\LoginForm;
use App\Models\User;
use App\Services\MondayCustomerDirectory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

$email       = $argv[1] ?? null;
$force       = in_array('--force', $argv, true);
$newPassword = 'Manual-Dev-Pass-2026!';
$fail        = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    if ($cond) {
        echo "   [ OK ] $label" . ($detail ? "  ($detail)" : '') . "\n";
    } else {
        $fail++;
        echo "   [FAIL] $label" . ($detail ? "  ($detail)" : '') . "\n";
    }
}

echo "================================================\n";
echo " MANUAL E2E TEST: customer first-login flow\n";
echo "================================================\n\n";

// ── Step 0: can we talk to Monday? ────────────────────────────────────
echo ">> Step 0: Ping Monday.com API...\n";
$monday = $app->make(\App\Services\MondayClient::class);
try {
    $me = $monday->query('query { me { id name email } }')['me'] ?? null;
    if (! $me) {
        echo "   FAILED — no `me` returned. Aborting.\n";
        exit(1);
    }
    echo "   OK — connected as Monday user: {$me['name']} (id={$me['id']})\n\n";
} catch (\Throwable $e) {
    echo "   FAILED — " . $e->getMessage() . "\nAborting before we wreck anything.\n";
    exit(1);
}

// ── Step 1: pick a customer from the board ────────────────────────────
echo ">> Step 1: Pick a customer on the Customer Details board\n";
$directory = app(MondayCustomerDirectory::class);

if (! $email) {
    foreach ($directory->all() as $row) {
        if (! empty($row['email'])) {
            $email = $row['email'];
            break;
        }
    }
}
if (! $email) {
    echo "   FAILED — no email given and no customer with an email on the board.\n";
    exit(1);
}

$customer = $directory->findByEmail($email);
if (! $customer) {
    echo "   FAILED — $email is NOT on the Customer Details board.\n";
    echo "   First-login provisioning only works for board customers.\n";
    exit(1);
}
echo "   email:        $email\n";
echo "   monday_id:    {$customer['id']}\n";
echo "   name:         {$customer['name']}\n";
echo "   account_name: " . ($customer['account_name'] ?? '(null)') . "\n";
echo "   branch:       " . ($customer['branch'] ?? '(null)') . "\n";
echo "   region:       " . ($customer['region'] ?? '(null)') . "\n\n";

// ── Step 2: fresh start ───────────────────────────────────────────────
echo ">> Step 2: Fresh start for the local account\n";
$existing = User::where('email', $email)->first();
if ($existing && ! $force) {
    echo "   Local account already exists (id={$existing->id}).\n";
    echo "   To wipe it and re-test first login, re-run with --force.\n";
    exit(1);
}
if ($existing) {
    $existing->delete();
    echo "   Deleted existing account (id was {$existing->id}).\n\n";
} else {
    echo "   No local account — clean slate.\n\n";
}

// ── Step 3: first login with the default password ─────────────────────
echo ">> Step 3: Drive LoginForm with the DEFAULT password\n";
try {
    $form = new LoginForm();
    $form->email    = $email;
    $form->password = User::DEFAULT_PASSWORD;
    $form->authenticate(); // throws ValidationException on failure
    echo "   OK — authenticate() returned without errors.\n\n";
} catch (\Throwable $e) {
    echo "   FAILED — " . $e->getMessage() . "\n";
    exit(1);
}

$user = User::where('email', $email)->first();
check('account auto-provisioned',       $user !== null);
check('role = customer',                $user && $user->role === 'customer', $user?->role ?? 'none');
check('status = active',                $user && $user->status === 'active', $user?->status ?? 'none');
check('must_change_password = true',    $user && $user->must_change_password === true);
check('monday_id copied from board',    $user && $user->monday_id === $customer['id'], $user->monday_id ?? 'null');
check('region copied from board',       $user && $user->region === $customer['region'], $user->region ?? 'null');
check('branch copied from board',       $user && $user->branch === $customer['branch'], $user->branch ?? 'null');
check('account_name copied from board', $user && $user->account_name === $customer['account_name'], $user->account_name ?? 'null');
check('default password works',         $user && Hash::check(User::DEFAULT_PASSWORD, $user->password));
check('user is authenticated',          Auth::check(), $user->email ?? '?');

// ── Step 4: the temporary-password notice in the navigation ──────────
echo "\n>> Step 4: Temporary-password notice in the navigation\n";
Auth::login($user);

try {
    $nav = Volt::test('layout.navigation');
    check('pending account sees the notice',         str_contains($nav->html(), 'Temporary password'));
    check('notice offers "Set password now"',        str_contains($nav->html(), 'Set password now'));
    check('notice offers "Set up later"',            str_contains($nav->html(), 'Set up later'));

    $nav->call('dismissPasswordNotice');
    check('"Set up later" stores the session flag', session('passwordChangeDismissed') === true);
} catch (\Throwable $e) {
    check('navigation notice mounted', false, $e->getMessage());
}
// ── Step 5: drive the password change page ───────────────────────────
echo "\n>> Step 5: Drive the password change (Volt page)\n";
try {
    $test = Volt::test('pages.auth.change-password')
        ->set('password', $newPassword)
        ->set('password_confirmation', $newPassword)
        ->call('changePassword');

    check('change-password returned no validation errors', $test->errors()->isEmpty(),
        $test->errors()->isEmpty() ? '' : implode('; ', $test->errors()->all()));

    $user->refresh();
    check('must_change_password now false',        $user->must_change_password === false);
    check('new password is stored',                Hash::check($newPassword, $user->password));
    check('old default password no longer works', ! Hash::check(User::DEFAULT_PASSWORD, $user->password));
} catch (\Throwable $e) {
    check('Volt change-password page ran', false, $e->getMessage());
}

// ── Step 6: the notice disappears after the change ───────────────────
echo "\n>> Step 6: Notice after the change\n";
try {
    $nav = Volt::test('layout.navigation');
    check('notice no longer shown (flag cleared)', ! str_contains($nav->html(), 'Temporary password'));
} catch (\Throwable $e) {
    check('navigation mounted after change', false, $e->getMessage());
}

// ── Summary ───────────────────────────────────────────────────────────
echo "\n================================================\n";
echo $fail === 0 ? " ALL CHECKS PASSED\n" : " $fail CHECK(S) FAILED\n";
echo "================================================\n";
echo "The account for $email now has the password: $newPassword\n";
exit($fail === 0 ? 0 : 1);
