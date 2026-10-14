<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\InvFlux\Exceptions\ShortfallCaptureException;

/**
 * What the operator said about a close-short: per {@see ShortfallPart}, one cause (and optionally a
 * party) on the header for every line, with per-line overrides, and one note. The quantities are not
 * here — they come from the counts ({@see ShortfallSplit}).
 *
 * A party left out is the suggestion from the cause and the order's Incoterm
 * ({@see ShortfallPart::defaultParty()}), recorded as inferred; one given is the operator's.
 *
 * @api
 */
final class ShortfallCapture
{
    /**
     * @param array<string, array{cause: ShortfallCause, party?: ShortfallParty|null}>             $parts part value → header choice
     * @param array<int, array<string, array{cause: ShortfallCause, party?: ShortfallParty|null}>> $lines subject id → part value → override
     */
    public function __construct(
        public readonly array $parts,
        public readonly array $lines = [],
        public readonly ?string $note = null,
        /** False only for a close recorded through the older single-reason path. */
        private readonly bool $requireNoteForUnknown = true,
    ) {
    }

    /**
     * The capture a close made with one of {@see \Nandan108\InvFlux\Application\Procurement\CloseShortReceipt::REASONS}
     * implies, for callers that still pass a single reason: each part gets the reason's cause where it
     * fits that part, else `unknown`.
     */
    public static function fromReason(string $reason, ?string $note = null): self
    {
        $cause = match ($reason) {
            'supplier_oos'  => ShortfallCause::OutOfStock,
            'packing_error' => ShortfallCause::ShippedShort,
            'discontinued'  => ShortfallCause::Discontinued,
            'wont_ship'     => ShortfallCause::Declined,
            default         => ShortfallCause::Unknown,
        };
        $parts = [];
        foreach (ShortfallPart::cases() as $part) {
            $fallback = $part->allows(ShortfallCause::Unknown) ? ShortfallCause::Unknown : $part->causes()[0];
            $parts[$part->value] = ['cause' => $part->allows($cause) ? $cause : $fallback];
        }

        return new self($parts, [], $note, false);
    }

    /**
     * The cause, party and whether the party is inferred, for one part of one line.
     *
     * @return array{cause: ShortfallCause, party: ShortfallParty, inferred: bool}
     *
     * @throws ShortfallCaptureException
     */
    public function resolve(int $subjectId, ShortfallPart $part, ?Incoterm $incoterm): array
    {
        $choice = $this->lines[$subjectId][$part->value] ?? $this->parts[$part->value] ?? null;
        if (null === $choice) {
            throw ShortfallCaptureException::missingCause($part->value, $subjectId);
        }
        $cause = $choice['cause'];
        if (!$part->allows($cause)) {
            throw ShortfallCaptureException::causeNotAllowed($part->value, $cause->value, $subjectId);
        }
        if ($this->requireNoteForUnknown && ShortfallCause::Unknown === $cause && '' === trim((string) $this->note)) {
            throw ShortfallCaptureException::noteRequired($part->value);
        }
        $party = $choice['party'] ?? null;

        return null === $party
            ? ['cause' => $cause, 'party' => $part->defaultParty($cause, $incoterm), 'inferred' => true]
            : ['cause' => $cause, 'party' => $party, 'inferred' => false];
    }
}
