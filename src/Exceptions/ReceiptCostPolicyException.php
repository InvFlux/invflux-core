<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

use Nandan108\InvFlux\Domain\Procurement\CostSource;
use Nandan108\InvFlux\Domain\Procurement\ReceiptReason;

/**
 * A source-less goods receipt whose lines do not satisfy what its reason obliges them to say about
 * cost.
 *
 * This is the enforcement of {@see ReceiptReason::costSource()}, and it is what keeps an intake
 * raised against no document from becoming a way to bring stock in without a valuation. Refusing is
 * the point: a receipt that posts the movement and quietly skips the weighted-average recompute
 * leaves stock on the shelf that the books value at nothing, and nothing downstream can tell that
 * from a genuine zero.
 *
 * @api
 */
final class ReceiptCostPolicyException extends InvFluxException
{
    private function __construct(
        public readonly ReceiptReason $reason,
        public readonly CostSource $costSource,
        public readonly ?int $subjectId,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * A reason whose cost only the operator can know, on a line that states none.
     *
     * Nothing can stand in here. A standing valuation would be the wrong number by construction —
     * what an opening balance or an unordered delivery cost is a fact about *this* arrival — so the
     * only honest outcome is to refuse the receipt and ask.
     */
    public static function costMustBeStated(ReceiptReason $reason, int $subjectId): self
    {
        return new self(
            $reason,
            CostSource::Entered,
            $subjectId,
            sprintf(
                'Goods receipt line for subject %d states no unit cost, and reason "%s" requires one: '
                .'the cost of this arrival is knowable only to whoever received it.',
                $subjectId,
                $reason->value,
            ),
        );
    }

    /**
     * A reason whose cost is computed from stock that already exists somewhere else, in an install
     * where that somewhere else cannot be named.
     *
     * A transfer carries cost from the origin's layers, so it needs an origin — a second addressable
     * location to have come from. Where the location dimension holds one on-hand address, a transfer
     * in has no counterpart and the derivation has nothing to read. Refused rather than silently
     * degraded to a standing valuation, which would record cost that never moved.
     */
    public static function costCannotBeDerived(ReceiptReason $reason): self
    {
        return new self(
            $reason,
            CostSource::Derived,
            null,
            sprintf(
                'Goods receipt reason "%s" derives its cost from the stock it came from, which requires '
                .'an origin location this install cannot address.',
                $reason->value,
            ),
        );
    }
}
