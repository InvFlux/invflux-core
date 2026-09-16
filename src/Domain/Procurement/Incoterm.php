<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Incoterms® 2020 — the ICC's standard three-letter terms stating where the seller's obligation ends
 * and the buyer's begins: who arranges carriage, who bears the cost, and at which point risk passes.
 *
 * **An Incoterm is not a shipping method.** The two are routinely conflated and answer different
 * questions: the Incoterm is the *commercial term* (who is responsible, and until where), the
 * shipping method is *how the goods physically travel* (which carrier, which service). A PO carries
 * both, separately.
 *
 * A term is meaningless without its **named place** — "FCA" alone says nothing, "FCA Rotterdam" is a
 * term. The place lives beside this on the purchase order rather than in the enum, because the same
 * term is used with a different place on every order.
 *
 * The list is closed *per revision*: these eleven are Incoterms 2020. A future revision (the ICC
 * revises roughly each decade) adds or retires cases here — which is a deliberate code change, not
 * something a merchant configures, because the terms carry legal meaning defined by the ICC and not
 * by us.
 *
 * @api
 */
enum Incoterm: string
{
    // ── Any mode of transport ────────────────────────────────────────────────
    /** Ex Works — buyer collects at the seller's premises and bears everything from there. */
    case EXW = 'EXW';
    /** Free Carrier — seller hands over to the buyer's carrier at the named place. */
    case FCA = 'FCA';
    /** Carriage Paid To — seller pays carriage to the named place; risk passes at first carrier. */
    case CPT = 'CPT';
    /** Carriage and Insurance Paid To — as CPT, plus the seller insures the carriage. */
    case CIP = 'CIP';
    /** Delivered At Place — seller bears cost and risk to the named place, ready for unloading. */
    case DAP = 'DAP';
    /** Delivered At Place Unloaded — as DAP, and the seller unloads. */
    case DPU = 'DPU';
    /** Delivered Duty Paid — seller bears everything including import duty and clearance. */
    case DDP = 'DDP';

    // ── Sea and inland waterway only ─────────────────────────────────────────
    /** Free Alongside Ship — seller delivers alongside the vessel at the named port. */
    case FAS = 'FAS';
    /** Free On Board — seller delivers on board; risk passes once the goods are aboard. */
    case FOB = 'FOB';
    /** Cost and Freight — seller pays freight to the destination port; risk passes on board. */
    case CFR = 'CFR';
    /** Cost, Insurance and Freight — as CFR, plus the seller insures the voyage. */
    case CIF = 'CIF';

    /**
     * Whether the term is restricted to sea and inland-waterway transport. The four maritime terms
     * are a common misuse — FOB in particular gets written on air and road orders, where it has no
     * defined meaning — so a UI can warn rather than silently accept one.
     */
    public function isMaritimeOnly(): bool
    {
        return \in_array($this, [self::FAS, self::FOB, self::CFR, self::CIF], true);
    }
}
