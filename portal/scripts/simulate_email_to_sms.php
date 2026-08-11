<?php
/**
 * simulate_email_to_sms.php
 *
 * Demonstrates email-to-SMS delivery via the Smart Communications gateway.
 * Target: 09989627022 (Remial Jude G. Busa, ITS — Smart prefix 0998)
 * Gateway: <11-digit>@smart.com.ph
 *
 * Usage:
 *   php scripts/simulate_email_to_sms.php            -- actually sends
 *   php scripts/simulate_email_to_sms.php --dry-run  -- shows the envelope, no SMTP
 *
 * The script enables Laravel's SMTP debug output (stream socket captured
 * by the SwiftMailer logger) so you can see the SMTP handshake in real time.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

$dryRun = in_array('--dry-run', $argv, true);

// 1. Resolve the carrier and gateway address
$phoneE164 = '+639989627022';        // canonical international
$phoneLocal = '09989627022';         // 11-digit national format
$carrier = 'Smart (TNT brand)';      // 0998 prefix = Smart/TNT
$gateway = $phoneLocal . '@smart.com.ph';

// 2. Build the SMS body (carrier gateways truncate ~160 chars)
$smsBody = "Test from portal: email-to-SMS working. Sent " . now()->format('H:i T') . ".";

echo "═══════════════════════════════════════════════════════════════\n";
echo "  EMAIL-TO-SMS SIMULATION\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Phone (E.164)  : $phoneE164\n";
echo "  Phone (local)  : $phoneLocal\n";
echo "  Carrier        : $carrier\n";
echo "  Gateway (To:)  : $gateway\n";
echo "  From           : " . config('mail.from.address') . "\n";
echo "  Body length    : " . strlen($smsBody) . " chars (limit 160)\n";
echo "  Mode           : " . ($dryRun ? 'DRY RUN (no SMTP)' : 'LIVE SEND') . "\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

if ($dryRun) {
    echo "Dry run - envelope only, no SMTP connection.\n";
    echo "Laravel code that would fire on a real send:\n\n";
    $example = <<<'PHP'
    Mail::raw($smsBody, function ($msg) use ($gateway) {
        $msg->from(config('mail.from.address'))
            ->to($gateway)
            ->subject('');
    });
PHP;
    echo $example . "\n\n";
    echo "Note: subject MUST be empty - Smart gateway uses the body as the SMS.\n";
    echo "From address is used as the SMS 'sender' line; some carriers strip it.\n";
    exit(0);
}

// 3. Live send in two phases:
//    A. Capture the fully-rendered MIME via the 'log' mailer (no SMTP)
//    B. Then send the real SMTP message via Gmail, which forwards to Smart gateway.
Log::channel('single')->info("=== email-to-SMS send start ===");
Log::channel('single')->info("to: $gateway");
Log::channel('single')->info("body: $smsBody");

$realDefault = config('mail.default');
$logPath = storage_path('logs/laravel.log');
$sizeBefore = file_exists($logPath) ? filesize($logPath) : 0;

try {
    // ── A. Capture MIME via log driver ──────────────────────────────
    config(['mail.default' => 'log']);
    Mail::clearResolvedInstances();
    app()->forgetInstance('mailer');
    Mail::raw($smsBody, function ($msg) use ($gateway) {
        $msg->from(config('mail.from.address'))
            ->to($gateway)
            ->subject(''); // empty subject — Smart reads body
    });
    Mail::clearResolvedInstances();
    app()->forgetInstance('mailer');

    // Read the bytes that were appended by the log driver
    $captured = file_exists($logPath) && filesize($logPath) > $sizeBefore
        ? substr(file_get_contents($logPath), $sizeBefore)
        : '';

    // ── B. Real SMTP send via Gmail ─────────────────────────────────
    config(['mail.default' => $realDefault]);
    Mail::raw($smsBody, function ($msg) use ($gateway) {
        $msg->from(config('mail.from.address'))
            ->to($gateway)
            ->subject(''); // empty subject — Smart reads body
    });

    echo "✅ SMTP send() returned without throwing.\n";
    echo "   That's the best PHP-side signal — the message was handed to Gmail's SMTP server.\n";
    echo "   Whether Smart's gateway picks it up and forwards to Remial's phone\n";
    echo "   happens after our process exits and is not observable from here.\n\n";
    echo "Ask Remial to check his phone (or wait up to 30 minutes for delivery).\n\n";

    if ($captured) {
        echo "--- MIME that was rendered (from 'log' driver capture) ---\n";
        echo $captured . "\n";
        echo "--- end MIME ---\n";
        Log::channel('single')->info("MIME preview captured (" . strlen($captured) . " bytes)");
    }
} catch (\Throwable $e) {
    echo "❌ SMTP send threw: " . $e->getMessage() . "\n";
    Log::channel('single')->error("SMTP send failed: " . $e->getMessage());
    exit(1);
}
