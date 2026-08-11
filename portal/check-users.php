<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Users (target) ===\n";
foreach (\App\Models\User::whereIn('email', ['ramenizing@mcbtsi.com', 'remial.busa@mcbtsi.com'])->get() as $u) {
    echo "{$u->email} | role={$u->role} | monday_id=" . ($u->monday_id ?? 'null') . " | name={$u->name}\n";
}

echo "\n=== Open (non-snapshot) invites ===\n";
foreach (\App\Models\CustomerInvite::where('is_open', true)->where('is_snapshot', false)->get() as $i) {
    echo "code={$i->code} | email=" . ($i->email ?? 'null') . " | account=" . ($i->account_name ?? 'null') . "\n";
}

echo "\n=== Snapshot invites (most recent 5) ===\n";
foreach (\App\Models\CustomerInvite::where('is_snapshot', true)->orderByDesc('id')->limit(5)->get() as $i) {
    echo "code={$i->code} | email=" . ($i->email ?? 'null') . " | account=" . ($i->account_name ?? 'null') . " | monday_customer_id=" . ($i->monday_customer_id ?? 'null') . "\n";
}
