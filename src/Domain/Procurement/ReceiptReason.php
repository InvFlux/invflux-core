<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Why stock arrived when no document ordered it — the reason a **standalone** goods receipt carries
 * in place of a source reference.
 *
 * **Not a label.** The reason decides *what cost the intake must state*, the same way
 * {@see \Nandan108\InvFlux\Domain\Order\OrderCorrectionType}'s flags decide slot movement and refund
 * logic. That is what stops a source-less intake becoming a back door around costing: every reason
 * has a defined answer to "what did this unit cost?", and the two cases where an operator is *not*
 * asked to type one are exactly those where the number is derived rather than invented — a transfer
 * carries cost from the origin's layers, and an assembly rolls it up from the components it consumed.
 *
 * A receipt with a source (a purchase order, or an advance shipping notice contributed by an add-on)
 * takes its cost from that document and carries no reason at all.
 *
 * @api
 */
enum ReceiptReason: string
{
    /** First load of a product InvFlux has not tracked before — the opening balance. */
    case OpeningBalance = 'opening_balance';

    /** A supplier delivered without a purchase order behind it. You paid for it, so you state what. */
    case SupplierDelivery = 'supplier_delivery';

    /**
     * Made in-house rather than bought. The cost is what it cost to *make* (materials plus labour),
     * hand-entered here; an assembly capability derives it from consumed components instead.
     */
    case InHouseBuild = 'in_house_build';

    /** Arrived from another of our own locations. Cost travels with the goods. */
    case TransferIn = 'transfer_in';

    /** Units found on the shelf that the books did not know about. */
    case FoundStock = 'found_stock';

    /** Received free — a supplier sample, a donation, a promotional unit. */
    case SampleOrDonation = 'sample_or_donation';

    /** Goods back from a customer outside the returns process, so no return document values them. */
    case ReturnOutsideRma = 'return_outside_rma';

    /**
     * What the intake has to say about cost. The operator is asked for a figure under
     * {@see CostSource::Entered} and {@see CostSource::SeedFallback}, and never under the other two.
     */
    public function costSource(): CostSource
    {
        return match ($this) {
            self::OpeningBalance, self::SupplierDelivery, self::InHouseBuild => CostSource::Entered,
            self::TransferIn                                                 => CostSource::Derived,
            self::FoundStock, self::ReturnOutsideRma                         => CostSource::SeedFallback,
            self::SampleOrDonation                                           => CostSource::Free,
        };
    }

    /**
     * Whether an intake under this reason may leave a line's cost unstated.
     *
     * True only where a number the operator did not type is still a *real* number: derived from
     * somewhere ({@see CostSource::Derived}), fallen back to the product's standing valuation
     * ({@see CostSource::SeedFallback}), or genuinely zero ({@see CostSource::Free}). It is never
     * true for a reason whose whole point is that someone paid something.
     */
    public function allowsAbsentCost(): bool
    {
        return CostSource::Entered !== $this->costSource();
    }
}
