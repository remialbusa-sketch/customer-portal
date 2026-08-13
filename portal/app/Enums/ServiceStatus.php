<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The five states a Technical Service Report (TSR) can be in, as
 * recorded on the EXTERNAL - TSR board (Monday id 5029041107).
 *
 * These are the values stored in the `service_reports.service_status`
 * column (enum on the database) and the values the TSP picks on the
 * portal form. They are also the values the executive KPI dashboard
 * aggregates by.
 *
 * The mapping from a TSR status to a *ticket* status (on the Tickets
 * board 5029331350) is NOT 1:1 — see App\Support\Monday\TsrStatusMapper.
 */
enum ServiceStatus: string
{
    case Open        = 'open';
    case InProgress  = 'in_progress';
    case Pending     = 'pending';
    case Escalated   = 'escalated';
    case Completed   = 'completed';

    /**
     * Human-readable label for the UI (portal, KPI dashboard, exports).
     */
    public function label(): string
    {
        return match ($this) {
            self::Open        => 'Open',
            self::InProgress  => 'In Progress',
            self::Pending     => 'Pending',
            self::Escalated   => 'Escalated',
            self::Completed   => 'Completed',
        };
    }

    /**
     * Compact color for badges (Tailwind classes — portal-side) and
     * for the KPI dashboard's status distribution widget.
     */
    public function color(): string
    {
        return match ($this) {
            self::Open        => 'gray',
            self::InProgress  => 'blue',
            self::Pending     => 'yellow',
            self::Escalated   => 'red',
            self::Completed   => 'green',
        };
    }

    /**
     * True when this status represents work that is in flight
     * (excludes Open and Completed, used by "Active TSRs" KPI).
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Open, self::Completed => false,
            default                     => true,
        };
    }

    /**
     * True when this status is terminal — used to lock the TSR form
     * (no further edits, just the "Reopen" button).
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed;
    }

    /**
     * @return array<string, string>  value => label
     */
    public static function toArray(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }
        return $out;
    }
}
