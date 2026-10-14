<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * One gap in the chain *ordered → confirmed → shipped → received* that a close-short writes off. A
 * shortfall splits into parts so that what was observed (the quantities, counted, never picked) stays
 * apart from why (the cause) and who answers for it (the party).
 *
 * `in_transit` (shipped − received) needs a shipped quantity, which only a shipping notice gives —
 * an add-on's; the base never produces it, and its `undelivered` runs from confirmed to received.
 *
 * @api
 */
enum ShortfallPart: string
{
    /** Ordered − confirmed: the supplier said, before shipping, that these would not come. */
    case Unconfirmed = 'unconfirmed';
    /** Confirmed − received (or − shipped, with a shipping notice): a commitment not met. */
    case Undelivered = 'undelivered';
    /** Shipped − received, with a shipping notice: lost or miscounted on the way. */
    case InTransit = 'in_transit';
    /** Units that arrived damaged and are closed out with the missing ones. */
    case Damaged = 'damaged';

    /**
     * The causes that fit this part — the only ones a prompt offers for it.
     *
     * @return non-empty-list<ShortfallCause>
     */
    public function causes(): array
    {
        return match ($this) {
            self::Unconfirmed => [ShortfallCause::OutOfStock, ShortfallCause::Discontinued, ShortfallCause::Declined, ShortfallCause::BuyerReduced],
            self::Undelivered => [ShortfallCause::ShippedShort, ShortfallCause::OutOfStock, ShortfallCause::Discontinued, ShortfallCause::Withheld, ShortfallCause::BuyerCancelled, ShortfallCause::LostInTransit, ShortfallCause::Unknown],
            self::InTransit   => [ShortfallCause::LostInTransit, ShortfallCause::CountedShort, ShortfallCause::Unknown],
            self::Damaged     => [ShortfallCause::DamagedInTransit, ShortfallCause::DamagedAtOrigin, ShortfallCause::RejectedOnInspection, ShortfallCause::Unknown],
        };
    }

    public function allows(ShortfallCause $cause): bool
    {
        return \in_array($cause, $this->causes(), true);
    }

    /**
     * Who answers for $cause in this part, as a suggestion the operator can change.
     *
     * Our own decisions are ours. Loss and damage on the way answer to whoever bore the risk then:
     * the supplier under a D-term (DAP, DPU, DDP — risk passes at our door), the carrier, answering to
     * us, once it had passed to us (every other term); unknown without an Incoterm. Everything else
     * is the supplier's.
     */
    public function defaultParty(ShortfallCause $cause, ?Incoterm $incoterm): ShortfallParty
    {
        if (ShortfallCause::BuyerReduced === $cause || ShortfallCause::BuyerCancelled === $cause) {
            return ShortfallParty::Us;
        }
        $inTransit = ShortfallCause::LostInTransit === $cause
            || ShortfallCause::DamagedInTransit === $cause
            || (self::InTransit === $this && ShortfallCause::CountedShort !== $cause);
        if ($inTransit) {
            return match ($incoterm) {
                null                                        => ShortfallParty::Unknown,
                Incoterm::DAP, Incoterm::DPU, Incoterm::DDP => ShortfallParty::Supplier,
                default                                     => ShortfallParty::Carrier,
            };
        }
        if (ShortfallCause::Unknown === $cause) {
            return ShortfallParty::Unknown;
        }

        return ShortfallParty::Supplier;
    }
}
