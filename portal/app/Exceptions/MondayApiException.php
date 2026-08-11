<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Monday.com GraphQL API returns a structured error.
 *
 * Carries the original error payload (code, message, error_data)
 * so the caller can decide whether to retry with a degraded payload
 * (e.g. drop a board_relation column whose source board hasn't been
 * wired up in the Monday UI yet).
 */
class MondayApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $error = [],
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public static function fromGraphQL(array $error): self
    {
        $message = $error['message'] ?? 'Unknown Monday.com error';
        $code    = $error['extensions']['code'] ?? null;
        return new self($message, $error, $code);
    }

    /**
     * True when Monday is telling us a *specific* column is invalid
     * (wrong type, wrong value, source item not in the connected
     * board, etc). These are recoverable by re-issuing the call with
     * a stripped payload.
     */
    public function isColumnValidationError(): bool
    {
        return ($this->error['extensions']['code'] ?? null) === 'ColumnValueException';
    }

    public function columnId(): ?string
    {
        return $this->error['extensions']['error_data']['column_id'] ?? null;
    }

    public function columnValidationCode(): ?string
    {
        return $this->error['extensions']['error_data']['column_validation_error_code'] ?? null;
    }

    /**
     * True when Monday is telling us the *target item* the mutation
     * was acting on has been archived, deleted, or moved to trash.
     * Monday's GraphQL responds with the literal text "Cannot
     * change column value for inactive items" (and a few near-variants
     * like "inactive items" in the column_validation_error_code).
     *
     * This is a different failure mode from a column-value-format
     * error — the payload was fine, the destination is just gone.
     * Callers should treat it as "skip the write, mark the row
     * synced so the drainer stops retrying" rather than "strip the
     * column and re-issue".
     */
    public function isInactiveItemError(): bool
    {
        $code = $this->error['extensions']['error_data']['column_validation_error_code'] ?? null;
        if (in_array($code, ['inactiveItems', 'linkedToDeletedItems'], true)) {
            return true;
        }
        // Top-level error code (e.g. when Monday rejects the whole
        // mutation, not just a column value).
        $topCode = $this->error['extensions']['code'] ?? null;
        if ($topCode === 'inactiveItems' || $topCode === 'linkedToDeletedItems') {
            return true;
        }
        $msg = strtolower($this->getMessage());
        return str_contains($msg, 'inactive items') || str_contains($msg, 'inactive item');
    }
}
