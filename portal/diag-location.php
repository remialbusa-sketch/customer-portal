<?php
/**
 * Test various location value formats to find one that Monday accepts.
 */

$root = __DIR__;
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\MondayClient;

$monday = $app->make(MondayClient::class);
$boardId = (int) config('services.monday.customers_board_id');
$addressCol = config('services.monday.customers_columns.address');
echo "Board: $boardId  Address col: $addressCol\n\n";

$gql = <<<GQL
mutation (\$boardId: ID!, \$itemName: String!, \$columnValues: JSON!) {
    create_item(
        board_id: \$boardId,
        item_name: \$itemName,
        column_values: \$columnValues,
        create_labels_if_missing: true
    ) {
        id
        name
    }
}
GQL;

$emailCol = config('services.monday.customers_columns.email');
$baseName = 'DIAG ' . date('Y-m-d H:i:s');

$cases = [
    'A: lat/lng as strings, Manila coords' => [
        'lat' => '14.5995', 'lng' => '120.9842', 'address' => 'Manila, Philippines',
    ],
    'B: lat/lng as numbers' => [
        'lat' => 14.5995, 'lng' => 120.9842, 'address' => 'Manila, Philippines',
    ],
    'C: address only (no lat/lng keys)' => [
        'address' => 'Manila, Philippines',
    ],
    'D: lat/lng as null' => [
        'lat' => null, 'lng' => null, 'address' => 'Manila, Philippines',
    ],
    'E: just address text no keys' => 'Manila, Philippines',
];

foreach ($cases as $label => $addrVal) {
    echo "--- $label ---\n";
    $cv = [
        $emailCol => [
            'email' => 'diag-' . bin2hex(random_bytes(3)) . '@example.com',
            'text'  => 'diag@example.com',
        ],
    ];
    if (is_array($addrVal)) {
        $cv[$addressCol] = $addrVal;
    } else {
        // raw string
        $cv[$addressCol] = $addrVal;
    }
    try {
        $resp = $monday->query($gql, [
            'boardId'      => (string) $boardId,
            'itemName'     => $baseName . ' ' . $label,
            'columnValues' => json_encode((object) $cv),
        ]);
        echo "  SUCCESS: id={$resp['create_item']['id']}\n";
    } catch (\App\Exceptions\MondayApiException $e) {
        $err = $e->error['extensions']['error_data'] ?? [];
        echo "  FAIL: " . $e->getMessage() . "\n";
        echo "    column_value: " . ($err['column_value'] ?? '(none)') . "\n";
    } catch (\Throwable $e) {
        echo "  OTHER: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
