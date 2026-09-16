<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Diagnostics;

/**
 * Result payload returned by one diagnostic check execution.
 *
 * @api
 */
final class DiagnosticResult
{
    /**
     * Create one diagnostic result.
     *
     * @param list<array<string, mixed>> $findings
     */
    public function __construct(
        public readonly DiagnosticStatus $status,
        public readonly array $findings = [],
        public readonly int $durationMs = 0,
    ) {
    }

    /** Return whether the check completed without findings. */
    public function isOk(): bool
    {
        return DiagnosticStatus::Ok === $this->status;
    }
}
