<?php

declare(strict_types=1);

namespace App\Support\Monday;

use App\Enums\ServiceStatus;

/**
 * Maps a Technical Service Report (TSR) status to the label the
 * Tickets board (5029331350) expects on its `status95` column.
 *
 * The two boards have different status vocabularies:
 *  - TSR (5029041107) has 5 values: OPEN / IN-PROGRESS / PENDING /
 *    ESCALATED / COMPLETED
 *  - Tickets (5029331350) has the executive's standard pipeline:
 *    OPEN / Working on it / PENDING / ESCALATED / Resolved / COMPLETED
 *
 * The mapping is NOT 1:1 because a TSR's "Completed" does not always
 * mean a ticket is "Resolved" (the TSP might complete the on-site
 * service and still have paperwork to finish). The agreed mapping is:
 *
 *   TSR OPEN        → keep current ticket status (no change)
 *   TSR IN-PROGRESS → "Working on it"
 *   TSR PENDING     → "PENDING"
 *   TSR ESCALATED   → "ESCALATED"
 *   TSR COMPLETED   → "COMPLETED"
 *
 * The class returns the *label string* (e.g. "Working on it") so the
 * caller can pair it with the right index from
 * `MondayColumnIds::TICKETS_STATUS_LABEL_INDEX` when building the
 * GraphQL mutation.
 *
 * The returned label is also what the portal shows on the ticket
 * detail page's "Last TSR drove status to ..." note.
 */
final class TsrStatusMapper
{
    /**
     * @return string  The exact label string the Tickets board uses
     *                 for its `status95` status column.
     */
    public static function toTicketStatusLabel(ServiceStatus $tsr): string
    {
        return match ($tsr) {
            ServiceStatus::Open        => 'OPEN',           // no-op, see toTicketChange()
            ServiceStatus::InProgress  => 'Working on it',
            ServiceStatus::Pending     => 'PENDING',
            ServiceStatus::Escalated   => 'ESCALATED',
            ServiceStatus::Completed   => 'COMPLETED',
        };
    }

    /**
     * Returns the monday.com status_picker index for the ticket board's
     * status95 column, suitable for the column_values JSON:
     *   { "status95": { "index": N } }
     *
     * Throws if the label has no known index — the verify() method
     * on MondayColumnIds will print the placeholders that need filling.
     */
    public static function toTicketStatusIndex(ServiceStatus $tsr): int
    {
        $label = self::toTicketStatusLabel($tsr);
        $index = MondayColumnIds::TICKETS_STATUS_LABEL_INDEX[$label] ?? null;
        if ($index === null) {
            throw new \RuntimeException(
                "Tickets board status95 has no recorded index for label "
                . "'{$label}'. Run App\Support\Monday\MondayColumnIds::verify() "
                . "and update TICKETS_STATUS_LABEL_INDEX after reading the "
                . "live board schema."
            );
        }
        return $index;
    }

    /**
     * Returns the column_values JSON for the mutation. Pass this directly
     * to the GraphQL `change_multiple_column_values` mutation's
     * `column_values` argument.
     *
     * @return array<string, array{index:int}>
     */
    public static function toColumnValues(ServiceStatus $tsr): array
    {
        return [
            MondayColumnIds::TICKETS_COL_STATUS => [
                'index' => self::toTicketStatusIndex($tsr),
            ],
        ];
    }

    /**
     * For OPEN: the ticket's status does not change. The caller may
     * still want to record that a TSR was opened against the ticket
     * (via an update / chat message), but no column_value change.
     *
     * Returns null when the TSR is OPEN (no status change to make),
     * and the column_values array otherwise.
     *
     * @return array<string, array{index:int}>|null
     */
    public static function toTicketChange(ServiceStatus $tsr): ?array
    {
        if ($tsr === ServiceStatus::Open) {
            return null;
        }
        return self::toColumnValues($tsr);
    }

    /**
     * Inverse direction — when a Monday webhook (or a board refresh)
     * brings a new ticket status, recompute what the latest local TSR
     * status should be set to so the local audit trail stays in sync.
     *
     * Accepts the live board's label vocabulary, including its verbatim
     * "IN-RPOGRESS" typo (see MondayColumnIds::TICKETS_STATUS_LABEL_INDEX).
     *
     * Returns null when the ticket status has no TSR equivalent
     * (e.g. "Resolved" is post-TSR; "OPEN" is pre-TSR).
     */
    public static function fromTicketStatusLabel(string $ticketLabel): ?ServiceStatus
    {
        return match ($ticketLabel) {
            'OPEN'                                  => ServiceStatus::Open,
            'Working on it', 'IN-RPOGRESS',
            'IN-PROGRESS', 'Assigned'               => ServiceStatus::InProgress,
            'PENDING', 'Waiting for parts'          => ServiceStatus::Pending,
            'ESCALATED', 'Escalated'                => ServiceStatus::Escalated,
            'COMPLETED', 'Resolved', 'Done'         => ServiceStatus::Completed,
            default                                 => null,
        };
    }
}
