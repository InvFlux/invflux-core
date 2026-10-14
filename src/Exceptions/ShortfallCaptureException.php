<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * A close-short whose shortfall cannot be recorded as given: a part with no cause, a cause that does
 * not fit its part, or an unknown cause with nothing written about it. Carries the part (and the
 * product, for a line override) so a caller can point at the field.
 *
 * @api
 */
final class ShortfallCaptureException extends InvFluxException
{
    public const MISSING_CAUSE = 'missing_cause';
    public const CAUSE_NOT_ALLOWED = 'cause_not_allowed';
    public const NOTE_REQUIRED = 'note_required';

    private function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly string $part,
        public readonly ?int $subjectId = null,
    ) {
        parent::__construct($message);
    }

    public static function missingCause(string $part, int $subjectId): self
    {
        return new self(self::MISSING_CAUSE, sprintf('No cause given for the "%s" part of product %d.', $part, $subjectId), $part, $subjectId);
    }

    public static function causeNotAllowed(string $part, string $cause, ?int $subjectId = null): self
    {
        return new self(self::CAUSE_NOT_ALLOWED, sprintf('Cause "%s" does not fit the "%s" part.', $cause, $part), $part, $subjectId);
    }

    public static function noteRequired(string $part): self
    {
        return new self(self::NOTE_REQUIRED, sprintf('An unknown cause for the "%s" part needs a note saying what is known.', $part), $part);
    }
}
