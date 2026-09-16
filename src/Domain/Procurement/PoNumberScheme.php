<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Produces a human PO number from a per-series counter. The repository's PO-create flow locks
 * the {@see PoNumberCounter} row for the scheme's series (`SELECT … FOR UPDATE`), formats the
 * next value via this scheme, then increments the counter — all in the create transaction.
 *
 * Essentials registers only {@see SequentialScheme}; Pro adds token / date-reset / per-supplier
 * / per-warehouse schemes, gated by LicenseGate.
 *
 * @api
 */
interface PoNumberScheme
{
    /** The PoNumberCounter series this scheme draws from (Essentials uses `'default'`). */
    public function seriesKey(): string;

    /** First counter value for a fresh series (Essentials: the settings-configured start value). */
    public function startValue(): int;

    /** Format the human PO number from a counter sequence value. */
    public function format(int $sequence): string;
}
