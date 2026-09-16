<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * How purchase tax applies to a given supplier — a *fiscal position* in miniature: a per-partner rule
 * that decides which rate reaches the order and what the document must state, without pretending to
 * be a tax-determination engine.
 *
 * The common cross-border case is {@see self::ReverseCharge}: the supplier invoices at 0% and the
 * buyer self-accounts for the tax. What that needs is mostly *identity and a sentence on the
 * document* — both parties' tax identifiers plus the statutory mention — rather than arithmetic,
 * which is why a per-supplier treatment carries the weight here instead of a rule table matching
 * ship-from, ship-to, product class and date.
 *
 * **This never decides whether tax is recoverable.** That is a property of the *buyer* (are we
 * registered? can we reclaim?), so it is one install-wide setting; a supplier's treatment only
 * changes the rate that applies. Conflating the two produces a per-supplier switch that appears to
 * work and quietly makes one item's cost layers inconsistent.
 *
 * @api
 */
enum SupplierTaxTreatment: string
{
    /** Ordinary domestic purchasing: the rate cascade applies and the document shows net / tax / gross. */
    case Standard = 'standard';

    /**
     * Cross-border B2B: invoiced at 0%, buyer self-accounts. The document must carry both parties'
     * tax numbers plus the mention. Named for the EU rule but not confined to it — the UK, India's
     * RCM, Singapore, Australia and the Gulf states all shift the same liability the same way.
     */
    case ReverseCharge = 'reverse_charge';

    /** Import from outside the tax area: 0% at the supplier; any tax arises at customs, not here. */
    case ExportExempt = 'export_exempt';

    /** The goods or the supply are exempt in this jurisdiction: 0%, with the exemption stated. */
    case Exempt = 'exempt';

    /** Supplier is below the registration threshold and charges no tax at all — no tax block on the document. */
    case NotRegistered = 'not_registered';

    /** Whether this treatment yields tax on the order at all; false for every zero-rated regime. */
    public function chargesTax(): bool
    {
        return self::Standard === $this;
    }
}
