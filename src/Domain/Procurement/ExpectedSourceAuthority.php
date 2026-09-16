<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Who may overwrite {@see PurchaseOrderLine::$qty_expected}, given who holds it now.
 *
 * The identities live on {@see PurchaseOrderLine} because they are a closed set naming *kinds of
 * document*. Their **ranking** lives here, apart from them, because it is a policy: the stored
 * number is deliberately not a rank, so that changing this file re-decides future writes without
 * silently re-reading every historical row as something it never was.
 *
 * Core owns this rather than any one add-on, for the reason core names shared dimension values —
 * more than one contributor can want the slot. A shipment-notice add-on and an invoice-matching
 * add-on each need to know they outrank an order acknowledgement and do not outrank a person, and
 * neither can learn that from the other.
 *
 * @api
 */
final class ExpectedSourceAuthority
{
    /**
     * Strength, ascending. The shape of it is one claim: **automatic sources rank by how late and
     * how specific the document is, and a human outranks all of them.**.
     *
     * A shipment notice supersedes an order acknowledgement because it is both later and about
     * actual goods; an invoice supersedes a shipment notice for the same reason. A figure a buyer
     * typed sits on top because it is the one source that already knows what the others said — it
     * was entered by someone looking at them — so letting the next automatic document overwrite it
     * would discard a decision rather than refine one.
     *
     * @var array<int, int>
     */
    private const RANK = [
        PurchaseOrderLine::EXPECTED_SOURCE_ORDERED   => 1,
        PurchaseOrderLine::EXPECTED_SOURCE_ORDER_ACK => 2,
        PurchaseOrderLine::EXPECTED_SOURCE_SHIPMENT  => 3,
        PurchaseOrderLine::EXPECTED_SOURCE_INVOICE   => 4,
        PurchaseOrderLine::EXPECTED_SOURCE_MANUAL    => 5,
    ];

    /**
     * Whether `$claimant` may write the line currently held by `$holder`.
     *
     * Null means unclaimed, so anyone may take it. Equal ranks may write: a source refreshing its
     * own figure from a later document of the same kind is the ordinary case, not a conflict.
     *
     * An unrecognised holder is refused. Something wrote that value meaning something by it, and a
     * number this policy cannot place is exactly the situation where overwriting is least safe.
     */
    public static function mayWrite(?int $holder, int $claimant): bool
    {
        if (null === $holder) {
            return true;
        }

        $held = self::RANK[$holder] ?? PHP_INT_MAX;

        return (self::RANK[$claimant] ?? 0) >= $held;
    }

    /**
     * Release the slot: clear the figure and the claim together.
     *
     * The only way a stronger holder hands off to a weaker one, and it is deliberate by
     * construction — there is no automatic path from "a buyer decided this" back to "let the next
     * document decide". Clearing one without the other is what leaves an orphan claim that nothing
     * can take and nothing can explain.
     */
    public static function release(PurchaseOrderLine $line): void
    {
        $line->qty_expected = null;
        $line->expected_source = null;
    }
}
