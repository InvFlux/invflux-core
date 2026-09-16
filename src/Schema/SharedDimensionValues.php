<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

/**
 * Canonical definitions for dimension values that **more than one add-on may legitimately
 * introduce**.
 *
 * Core *names* these; it never registers them. Nothing here is provisioned unless some
 * {@see Extension\SlotSpaceContributor} asks for it with `requireValues()`, so a base install has
 * no `bkd`, no `sup`, no transit leg — exactly as before. This is the {@see Stt} idiom one level
 * up: naming a value does not close the dimension, and an add-on with a value only *it* can
 * introduce still declares that value itself, with its own `ownerKey`, and needs nothing from here.
 *
 * ## Why a shared definition is needed at all
 *
 * Two contributors declaring the same value is supported and deduplicated — a transit leg belongs
 * to inbound procurement *and* to the post-dispatch journey, and neither owns it. But the
 * de-duplication compares the whole definition, `ownerKey` included, so two add-ons that each
 * stamp their own owner on `bkd` **conflict**, and they conflict only on a site that installs
 * both. The add-on authors never see it; the merchant does, at boot.
 *
 * That is why these carry no owner: `ownerKey` answers "whose lifecycle governs this value", and a
 * value either party may introduce is governed by neither. The base owner is the honest answer,
 * and it is the same call already made for the base movement types. Declaring identical
 * definitions by hand would work equally well — for a flat `stt` value it is one parameter not
 * passed — but a transit leg has a parent, an addressability and a level to agree on, and
 * "everyone must type the same three attributes" is a convention that fails silently on a
 * merchant's install rather than in anyone's test suite.
 *
 * ## Semantics live in position, not here
 *
 * These definitions carry structure — parent, level, addressability — and nothing else. A value's
 * *meaning* is where it sits in the value space: promisable ⇔ `atp`, in our custody ⇔ under the
 * on-hand root. There are deliberately no `promisable` or `on_premise` flags to read; a projection
 * asks for a marginal over an axis or a subtree instead.
 *
 * @api
 */
final class SharedDimensionValues
{
    /**
     * The structural level naming a **kind of place** the commercial layer addresses that is not a
     * warehouse and never will be — supplier-side stock, transit legs, the customer's hands.
     *
     * A separate level rather than a widened `warehouse`: warehouses are a real tier that
     * merchants group and nest, and a supplier is not one of them at any depth.
     */
    public const LEVEL_EXTERNAL = 'external';

    // ── stt ──────────────────────────────────────────────────────────────────────────────────

    /** In an inbound leg — ordered or shipped, not yet here, and not promisable. */
    public const PND = 'pnd';

    /** On premise, awaiting a pass/fail decision, withheld from sale until it is made. */
    public const QI = 'qi';

    /**
     * On premise, withheld pending a decision — damaged, recalled, disputed.
     *
     * *Why* a unit is blocked is a reason code on the movement that put it here, never a state of
     * its own; a state per reason would make re-classifying a mistake into a stock movement.
     */
    public const BKD = 'bkd';

    // ── loc ──────────────────────────────────────────────────────────────────────────────────

    /** Supplier-side: ordered, confirmed, still in the supplier's hands. */
    public const SUP = 'sup';

    /** External transit, as a grouping node — stock is never *at* `trs`, only on one of its legs. */
    public const TRS = 'trs';

    /** The inbound leg: dispatched by the supplier, not yet received. */
    public const TRS_INB = 'trs/inb';

    /** The outbound leg: dispatched to the customer, not yet delivered. */
    public const TRS_DSP = 'trs/dsp';

    /** The return-to-origin leg: refused or undeliverable, travelling back. */
    public const TRS_RTO = 'trs/rto';

    /** In the customer's hands, with a return window still open. */
    public const CUST = 'cust';

    /** A `stt` value: not promisable, and meaningless outside an inbound leg. */
    public static function pending(): DimensionValueDefinition
    {
        return new DimensionValueDefinition(self::PND);
    }

    /** A `stt` value: awaiting inspection. */
    public static function qualityInspection(): DimensionValueDefinition
    {
        return new DimensionValueDefinition(self::QI);
    }

    /** A `stt` value: blocked pending a disposition decision. */
    public static function blocked(): DimensionValueDefinition
    {
        return new DimensionValueDefinition(self::BKD);
    }

    /** A `loc` value: the single coarse supplier-side location. */
    public static function supplierSide(): DimensionValueDefinition
    {
        return new DimensionValueDefinition(self::SUP, level: self::LEVEL_EXTERNAL);
    }

    /**
     * A `loc` value: the transit root.
     *
     * **Not addressable, and that is load-bearing.** Stock sits on a leg, never on `trs` itself, so
     * the root exists to group them. Declaring it addressable and then adding a leg would make it
     * gain its first child while holding stock — which is the hierarchisation path, and would mint
     * a catch-all child for stock that should never have been there.
     */
    public static function transit(): DimensionValueDefinition
    {
        return new DimensionValueDefinition(self::TRS, addressable: false);
    }

    /** A `loc` value: the inbound transit leg. Requires {@see transit()} to be declared with it. */
    public static function transitInbound(): DimensionValueDefinition
    {
        return new DimensionValueDefinition(self::TRS_INB, parentCode: self::TRS, level: self::LEVEL_EXTERNAL);
    }

    /** A `loc` value: the outbound transit leg. Requires {@see transit()} to be declared with it. */
    public static function transitDispatched(): DimensionValueDefinition
    {
        return new DimensionValueDefinition(self::TRS_DSP, parentCode: self::TRS, level: self::LEVEL_EXTERNAL);
    }

    /** A `loc` value: the return-to-origin leg. Requires {@see transit()} to be declared with it. */
    public static function transitReturnToOrigin(): DimensionValueDefinition
    {
        return new DimensionValueDefinition(self::TRS_RTO, parentCode: self::TRS, level: self::LEVEL_EXTERNAL);
    }

    /** A `loc` value: in the customer's hands, return window open. */
    public static function customer(): DimensionValueDefinition
    {
        return new DimensionValueDefinition(self::CUST, level: self::LEVEL_EXTERNAL);
    }
}
