<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Diagnostics;

/**
 * Execute one structured health check and optionally repair detected drift.
 *
 * @api
 */
interface DiagnosticCheck
{
    /** Return the stable unique identifier for this check, e.g. `woo_stock_sync`. */
    public function key(): string;

    /** Return the default run interval in seconds. */
    public function defaultFrequencySeconds(): int;

    /** Execute the check and return structured findings. */
    public function run(): DiagnosticResult;

    /** Attempt an idempotent repair, or return null when repair is unsupported. */
    public function repair(DiagnosticResult $result): ?DiagnosticResult;
}
