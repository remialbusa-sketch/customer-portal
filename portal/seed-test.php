<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\CustomerInvite;
use Illuminate\Support\Carbon;

// 1. Patch remial.busa's monday_id (looked up via Monday API: me { id })
$tsp = User::where('email', 'remial.busa@mcbtsi.com')->firstOrFail();
$tsp->monday_id = '77561926';
$tsp->save();
echo "Updated {$tsp->email}: monday_id={$tsp->monday_id}, role={$tsp->role}\n";

// 2. Create an open invite for ramenizing@mcbtsi.com
$existing = CustomerInvite::where('email', 'ramenizing@mcbtsi.com')
    ->where('is_snapshot', false)
    ->whereNull('used_at')
    ->first();
if ($existing) {
    echo "Reusing existing open invite: {$existing->token}\n";
    $invite = $existing;
} else {
    $invite = CustomerInvite::create([
        'token'             => CustomerInvite::generateToken(),
        'email'             => 'ramenizing@mcbtsi.com',
        'account_name'      => null,
        'branch'            => null,
        'region'            => null,
        'address'           => null,
        'is_snapshot'       => false,
        'invited_by_user_id'=> $tsp->id,
        'expires_at'        => Carbon::now()->addDays(CustomerInvite::DEFAULT_TTL_DAYS),
    ]);
    echo "Created open invite: {$invite->token}\n";
}

echo "\n=== Registration URL ===\n";
echo "http://127.0.0.1:8765/register/{$invite->token}\n";
