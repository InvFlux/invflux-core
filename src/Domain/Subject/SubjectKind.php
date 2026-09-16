<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * Classify a subject's role in the inventory hierarchy.
 *
 * Every case answers the same question — **where does availability come from?**
 *
 * | kind         | slots  | availability                      |
 * | ------------ | ------ | --------------------------------- |
 * | `Aggregate`  | none   | sum of its children               |
 * | `Unit`       | owns   | its own                           |
 * | `Kit`        | none   | `min(component atp ÷ qty_per)`    |
 * | `Batch`      | owns   | its own (physical layer)          |
 * | `NonStocked` | none   | unbounded — there is no quantity  |
 *
 * Ask the predicates below, never the case. Two genuinely independent axes run through this enum,
 * and conflating them is the mistake it is shaped to prevent:
 *
 *  - {@see ownsSlots()} — does this subject hold inventory of its own? The *domain* question.
 *  - {@see isVariantLevel()} — where does it sit in the FK hierarchy? The *storage* question.
 *
 * Neither implies the other, in either direction:
 *
 * |                  | owns slots            | owns none                      |
 * | ---------------- | --------------------- | ------------------------------ |
 * | **variant-level**| `Unit`                | `Kit`, `NonStocked`            |
 * | **elsewhere**    | `Batch` (below unit)  | `Aggregate` (product-level)    |
 *
 * Three of the five kinds hold no slots and they sit at two different levels, so "holds no stock"
 * says nothing about where a row belongs and vice versa. Code that reached for one axis while
 * meaning the other classifies a whole quadrant backwards.
 *
 * @api
 */
enum SubjectKind: string
{
    /**
     * Grouping node — no direct inventory. Children are Unit or Kit subjects.
     * Example: a WooCommerce variable product whose variations are the real inventory units.
     */
    case Aggregate = 'aggregate';

    /**
     * Commercial inventory unit — the thing customers buy.
     * Always holds commercial-layer inventory, and holds the physical layer too **unless** lot
     * tracking delegates it: a Unit with Batch children carries only the commercial layer, while
     * its batches carry the physical one. Layer residency is therefore a property of the pair
     * (kind, tracking), never of the kind alone.
     * Example: a WooCommerce simple product or product variation.
     */
    case Unit = 'unit';

    /**
     * Kit-on-sale parent — the thing customers buy, but holds no inventory of its own.
     * Components are ordinary subjects linked via the BOM table; availability is derived
     * as min(component atp ÷ qty_per). Explodes into component demand at checkout. Never a
     * parent. Like Unit it is a simple product or a variation (FK shape identical to Unit);
     * unlike Unit it owns no slots. Parent must be an Aggregate, or none (simple product).
     * Example: a WooCommerce "build-a-box" / bundle whose picks decrement real component stock.
     *
     * **A bundle is only a Kit if the bundle itself is the order line.** The tell is where the
     * explosion happens. A bundle that explodes *in the cart* — the host replaces it with its
     * components before an order exists — never reaches InvFlux as a line: the order carries the
     * components, each already a subject of its own. Nothing books against the bundle, so it holds
     * no slots, and it is {@see NonStocked}, alongside grouped and external products.
     *
     * It still gets a subject. Every catalogue row does: the workbench operates on subjects, so a
     * product without one is not merely stockless, it is invisible and unmanageable. "Holds no
     * stock" and "has no subject" are different claims, and only the first is true here.
     *
     * The distinction from a Kit is also structural, not just semantic: a Kit's components are a
     * many-to-many bill of materials, so they can never be modelled as `parent_id` children the way
     * an Aggregate's variations are.
     */
    case Kit = 'kit';

    /**
     * Physical inventory leaf — an identified subdivision of a Unit's stock: a lot or
     * production batch, or, degenerately, a single identified piece (a serialized or
     * catch-weight item is a **batch of one** — same construction Odoo's unified lot model
     * uses for serial numbers). Holds physical-layer inventory. Parent must be a Unit.
     * Lifecycle is ephemeral: created on intake, deactivated when stock is depleted.
     */
    case Batch = 'batch';

    /**
     * Sellable, and there is no quantity to keep — by nature, not by omission.
     *
     * The ERP "non-inventory item": a service, a digital download, a made-to-order line. It is
     * *governed* like any other subject — InvFlux owns its identity, its order lines, its cost and
     * its procurement — but availability is unbounded rather than counted, so it holds no slots and
     * nothing ever reserves against it.
     *
     * The distinction that earns the case is **declaration versus omission**. A host flag saying
     * "not tracking this" is the absence of a statement, and absence forces availability to answer
     * "unknown"; this says "there is no quantity, permanently", so availability can answer "yes"
     * with confidence. It is also the reason the state belongs here at all: everything keyed on
     * `subject_id` survives a change of host, and a fact kept only in the host's own flags does not.
     *
     * It covers two shapes that differ commercially but not for inventory, because neither ever has
     * a quantity to reserve against:
     *
     *  - **sold as itself, uncounted** — a service, a download, a made-to-order line, an
     *    external/affiliate product fulfilled by someone else;
     *  - **never sold as itself** — a container whose components are what the customer actually
     *    buys: a grouped product, or a bundle the host explodes in the cart (see {@see Kit} for the
     *    bundle that stays an order line instead).
     *
     * Both still get subjects — the workbench operates on subjects, so a catalogue row without one
     * is invisible rather than merely stockless.
     *
     * Like Unit and Kit it is a simple product or a variation (identical FK shape); parent must be
     * an Aggregate, or none.
     */
    case NonStocked = 'non_stocked';

    /**
     * Does this subject hold inventory of its own — slots, ledger, reservations?
     *
     * False does not mean "no availability": an Aggregate sums its children, a Kit derives from its
     * components, a NonStocked item is simply always available. It means *this row* owns no slot
     * state, so nothing may book, reserve or move stock against it directly.
     */
    public function ownsSlots(): bool
    {
        return self::Unit === $this || self::Batch === $this;
    }

    /**
     * Does this subject sit at the **variant** level of the host's product hierarchy?
     *
     * The storage axis, distinct from {@see ownsSlots()}: it decides the self-referential FK shape
     * (`variant_id = self`) and which parent is legal, not whether stock lives here. Kit and
     * NonStocked are variant-level *and* slot-less — which is exactly why the two questions must be
     * asked separately.
     */
    public function isVariantLevel(): bool
    {
        return self::Unit === $this || self::Kit === $this || self::NonStocked === $this;
    }

    /**
     * May a subject of this kind hang under `$parentKind`?
     *
     * The hierarchy's one legality rule, stated once. It lived duplicated verbatim in two storage
     * call sites, which is how a new case became an audit instead of a declaration.
     *
     * Roots are not this method's business — a subject with no parent is always legal, and callers
     * check only when a parent is present.
     */
    public function canBeChildOf(self $parentKind): bool
    {
        return match (true) {
            $this->isVariantLevel() => self::Aggregate === $parentKind,
            self::Batch === $this   => self::Unit === $parentKind,
            // An Aggregate groups; it is never grouped.
            default                 => false,
        };
    }

    /**
     * May a subject of this kind carry a {@see SubjectTracking} mode other than
     * {@see SubjectTracking::None}?
     *
     * Only a Unit can: tracking delegates the *physical* layer to child subjects, and a Unit is
     * the one kind that holds a physical layer it could delegate. A Batch is already the
     * delegate (tracking a delegate would recurse); Aggregate, Kit and NonStocked never hold
     * physical stock, so there is nothing to identify.
     *
     * Stated here beside {@see canBeChildOf()} so the legality rules live in one place; the
     * single-row half of both is mirrored as a table CHECK constraint in storage — defence in
     * depth against writes that bypass this layer, never the authority.
     */
    public function allowsTracking(): bool
    {
        return self::Unit === $this;
    }

    public function isAggregate(): bool
    {
        return self::Aggregate === $this;
    }

    public function isUnit(): bool
    {
        return self::Unit === $this;
    }

    public function isKit(): bool
    {
        return self::Kit === $this;
    }

    public function isBatch(): bool
    {
        return self::Batch === $this;
    }

    public function isNonStocked(): bool
    {
        return self::NonStocked === $this;
    }
}
