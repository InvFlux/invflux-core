<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Costing;

/**
 * What a base-currency conversion actually did — the record the caller audits from.
 *
 * Both counts matter to the operator, and they count different things: `$subjectsConverted` is cost
 * state that now reads in the new base, `$adjustmentLinesGrounded` is history that was stamped with
 * the *outgoing* base so it stays readable. A conversion that grounds thousands of lines and converts
 * a handful of subjects is a normal shape on a store that adjusted stock long before it costed it.
 *
 * @api
 */
final class CostConversionResult
{
    /**
     * @param non-empty-string $from                    the base the costs were denominated in
     * @param non-empty-string $to                      the base they now read in
     * @param numeric-string   $rate                    units of $to per 1 unit of $from
     * @param int              $subjectsConverted       sidecar rows restated
     * @param int              $adjustmentLinesGrounded pre-grounding history lines stamped with $from
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $rate,
        public readonly int $subjectsConverted,
        public readonly int $adjustmentLinesGrounded,
    ) {
    }
}
