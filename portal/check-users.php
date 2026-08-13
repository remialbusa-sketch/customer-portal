<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Users (target) ===\n";
foreach (\App\Models\User::whereIn('email', ['ramenizing@mcbtsi.com', 'remial.busa@mcbtsi.com'])->get() as $u) {
    echo "{$u->email} | role={$u->role} | status={$u->status} | must_change=" . ($u->must_change_password ? 'YES' : 'no')
       . " | monday_id=" . ($u->monday_id ?? 'null') . " | name={$u->name}\n";
}

echo "\n=== Accounts still on the temporary password ===\n";
$pending = \App\Models\User::where('must_change_password', true)->get();
if ($pending->isEmpty()) {
    echo "(none — every account has set its own password)\n";
}
foreach ($pending as $u) {
    echo "{$u->email} | role={$u->role} | status={$u->status} | created={$u->created_at}\n";
}
