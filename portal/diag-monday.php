<?php
/**
 * Diagnostic: list the columns of the Customers board so we can
 * figure out what `address` is and how to write it.
 */

$root = __DIR__;
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\MondayClient;

$monday = $app->make(MondayClient::class);
$boardId = (int) config('services.monday.customers_board_id');
echo "Customers board id: $boardId\n\n";

$graphql = <<<GQL
query (\$boardId: [ID!]) {
    boards (ids: \$boardId) {
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

$data = $monday->query($graphql, ['boardId' => [(string) $boardId]]);
$board = $data['boards'][0] ?? null;
if (! $board) {
    echo "No board returned.\n";
    var_dump($data);
    exit(1);
}

echo "Board: {$board['name']} (id={$board['id']})\n\n";
echo str_repeat('-', 80) . "\n";
printf("%-30s %-40s %s\n", 'ID', 'TITLE', 'TYPE');
echo str_repeat('-', 80) . "\n";
foreach ($board['columns'] as $c) {
    printf("%-30s %-40s %s\n", $c['id'], substr($c['title'], 0, 38), $c['type']);
    if (in_array($c['type'], ['location', 'country', 'phone', 'email', 'date', 'dropdown', 'status'], true)) {
        $settings = $c['settings_str'] ?? '';
        if ($settings !== '') {
            $decoded = json_decode($settings, true);
            if ($decoded) {
                echo "    settings: " . json_encode($decoded, JSON_UNESCAPED_SLASHES) . "\n";
            }
        }
    }
}

echo "\n=== Try a write with all fields to see the exact error ===\n";
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

$cols = config('services.monday.customers_columns');
$cv = [
    $cols['email'] => [
        'email' => 'diag-' . bin2hex(random_bytes(4)) . '@example.com',
        'text'  => 'diag@example.com',
    ],
];
// Try location form
$addressCol = config('services.monday.customers_columns.address');
if ($addressCol) {
    echo "Trying address column: $addressCol\n";
    $cv[$addressCol] = ['lat' => '', 'lng' => '', 'address' => '123 Rizal Ave, Manila'];
}

try {
    $resp = $monday->query($gql, [
        'boardId'      => (string) $boardId,
        'itemName'     => 'DIAG ' . date('Y-m-d H:i:s'),
        'columnValues' => json_encode((object) $cv),
    ]);
    echo "SUCCESS: " . json_encode($resp) . "\n";
} catch (\App\Exceptions\MondayApiException $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    echo "  error code: " . ($e->errorCode ?? '(none)') . "\n";
    echo "  column id:  " . ($e->columnId() ?? '(none)') . "\n";
    echo "  column code: " . ($e->columnValidationCode() ?? '(none)') . "\n";
    echo "  full error: " . json_encode($e->error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (\Throwable $e) {
    echo "OTHER: " . $e->getMessage() . "\n";
}
