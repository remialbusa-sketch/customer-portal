<?php

declare(strict_types=1);

namespace App\Support\Monday;

/**
 * Central registry of Monday.com board IDs and column IDs that the
 * portal reads from / writes to.
 *
 * Why this exists:
 *  - Monday column IDs are opaque strings (e.g. "color_mm3gbrby") and
 *    are the only stable identifier across schema renames.
 *  - Hard-coding them in feature code spreads the magic strings and
 *    makes renames painful.
 *  - Putting them here means the only place to update on a rename is
 *    this file (and a config dump for verification).
 *
 * Verification:
 *  - Each constant is annotated with its label from the board
 *    snapshot. The IDs must be re-verified after any board schema
 *    change. The `verify()` static method can be run from a
 *    tinker session to dump every constant for a sanity check.
 */
final class MondayColumnIds
{
    // ─── Boards ────────────────────────────────────────────────────────
    // These are last-resort fallbacks. Production code reads from
    // config('services.monday.*') first; these constants are only
    // used when the config key is missing. Keep in sync with
    // config/services.php.
    public const BOARD_TICKETS         = 5029331350; // customer tickets (live as of 2026-07, was 5028514175)
    public const BOARD_TSR             = 5029041107; // EXTERNAL - TSR
    public const BOARD_CUSTOMERS       = 5029327268; // Customer Details board

    // ─── Tickets board (5028514175) — read-mostly, but we write to status95 ─
    public const TICKETS_COL_STATUS          = 'status95'; // TODO: verify exact column id
    public const TICKETS_COL_SUBJECT         = 'text';      // placeholder, verify
    public const TICKETS_COL_RESPONSE_STATUS = 'color_mm4vbp35';  // "NOT YET" → "RESPONDED"
    public const TICKETS_COL_TIME_TRACKING   = 'duration_mm4hesrz'; // Monday native time_tracking widget

    /**
     * Labels on the RESPONSE STATUS column. Discovered via
     * `php scripts/list_status_labels.php color_mm4vbp35`.
     * The dropdown has exactly two options:
     *   index 0 = "NOT YET"  (default)
     *   index 1 = "RESPONDED"
     *
     * We don't index into the column by raw integer — the `label`
     * write path uses `create_labels_if_missing: true` so passing
     * the human name is enough. This map is here for any code that
     * needs to read the current state back.
     *
     * @var array<string, int>
     */
    public const TICKETS_RESPONSE_STATUS_LABEL_INDEX = [
        'NOT YET'   => 0,
        'RESPONDED' => 1,
    ];

    /**
     * Status label indices on the Tickets board's status95 column.
     * These are the values the board's status_picker stores as
     * `{ "index": N }`. We need the right index for each human label
     * so a TSR status of "Completed" maps to the exact label the
     * board expects, not just any label with the same text.
     *
     * TODO(verify): read board 5028514175 → column status95 → settings
     * → labels, then fill in the indices.
     *
     * @var array<string, int>
     */
    public const TICKETS_STATUS_LABEL_INDEX = [
        'Open'           => 0, // verify
        'Working on it'  => 1, // verify
        'Waiting for parts' => 2, // verify
        'Escalated'      => 3, // verify
        'Resolved'       => 4, // verify
        'Done'           => 5, // verify
        'Closed'         => 6, // verify
        'COMPLETED'      => 11, // verified 2026-07-20 against board 5029331350
    ];

    // ─── TSR board (5029041107) — written when a TSP submits a report ─
    public const TSR_COL_SERVICE_NUMBER      = 'board_relation_mm4gg1rm'; // Tickets board-relation (was mm3f6835 pre-2026-07)
    public const TSR_COL_SERVICE_STATUS      = 'color_mm3gbrby';
    public const TSR_COL_PROBLEM             = 'long_text_mks8824j';
    public const TSR_COL_JOB_DONE            = 'long_text_mks8y6j7';
    public const TSR_COL_PARTS_REPLACED      = 'text_mks8xtcq';
    public const TSR_COL_RECOMMENDATION      = 'long_text_mksdf1jb';
    public const TSR_COL_LOGIN_DATE          = 'date_mks8wqcw';
    public const TSR_COL_SERVICE_START       = 'date_mks8t42p';
    public const TSR_COL_SERVICE_END         = 'date_mks8gbw0';
    public const TSR_COL_LOGOUT_DATE         = 'date_mks8mvb2';
    public const TSR_COL_MACHINE_SYSTEM      = 'single_selectn7mh0gm';
    public const TSR_COL_SERIAL              = 'long_text_mkw3zweq';
    public const TSR_COL_SOFTWARE            = 'short_text7hjan9fo';
    public const TSR_COL_CONTRACT            = 'single_selectnuarkqi';
    public const TSR_COL_TSP_WORKWITH        = 'multiple_person_mks8jn7f';
    public const TSR_COL_TSP_EMAIL           = 'email_mks72yj4';
    public const TSR_COL_TSP_SIGNATURE       = 'signaturew5mfhn25';
    public const TSR_COL_TSP_WORKWITH_SIG    = 'signature3hw5m6pa';
    public const TSR_COL_CUSTOMER_INCHARGE   = 'text_mkw29ykk';
    public const TSR_COL_CUSTOMER_INCHARGE_E = 'emailpxq2qbr6';
    public const TSR_COL_CUSTOMER_INCHARGE_S = 'file_mks8jddc';
    public const TSR_COL_BIOMED_INCHARGE     = 'short_texton5gwzbm';
    public const TSR_COL_BIOMED_EMAIL        = 'email81d8h472';
    public const TSR_COL_BIOMED_SIGNATURE    = 'signaturecfdbi0hq';
    public const TSR_COL_REMARKS             = 'long_textfntkrfpg';
    public const TSR_COL_CALL_LOGIN_TIME     = 'hour_mkzwcmjs';
    public const TSR_COL_FILE_ATTACHMENTS    = 'file_mky1mtxf';
    public const TSR_COL_PDF_STATUS          = 'color_mm3tc8rj';

    /**
     * TSR Service Status label indices on the TSR board's color_mm3gbrby
     * column. The TSR board has its own 5-value status (different from
     * the ticket board's 6-value status), and the portal writes the
     * TSR's status to this column when a TSP submits or updates a report.
     *
     * TODO(verify): read board 5029041107 → column color_mm3gbrby → settings
     * → labels, then fill in the indices.
     *
     * @var array<string, int>
     */
    public const TSR_STATUS_LABEL_INDEX = [
        'OPEN'        => 0, // verify
        'IN-PROGRESS' => 1, // verify
        'PENDING'     => 2, // verify
        'ESCALATED'   => 3, // verify
        'COMPLETED'   => 4, // verify
    ];

    /**
     * Dump every constant for a sanity check. Useful in tinker:
     *   php artisan tinker
     *   > App\Support\Monday\MondayColumnIds::verify();
     */
    public static function verify(): array
    {
        $reflection = new \ReflectionClass(self::class);
        $out = ['boards' => [], 'tickets' => [], 'tsr' => []];
        foreach ($reflection->getConstants() as $name => $value) {
            if (str_starts_with($name, 'BOARD_')) {
                $out['boards'][$name] = $value;
            } elseif (str_starts_with($name, 'TICKETS_')) {
                $out['tickets'][$name] = $value;
            } elseif (str_starts_with($name, 'TSR_')) {
                $out['tsr'][$name] = $value;
            }
        }
        return $out;
    }
}
