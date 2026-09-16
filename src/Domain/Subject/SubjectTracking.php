<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * How a subject's stock is **physically identified** at capture time.
 *
 * This is *prospective intent*, not a description of what exists: a lot-tracked subject holding
 * no stock yet has no {@see SubjectKind::Batch} children, so structure cannot answer the
 * question, while goods receipt must already know what identity to demand. The attribute causes
 * the structure, never the reverse.
 *
 * It answers only the **physical-address** question — whose slots hold this subject's stock.
 * Two neighbouring questions are deliberately elsewhere, and conflating them is the mistake this
 * enum is shaped to prevent:
 *
 *  - *does InvFlux own this quantity at all?* — governance (`Subject::$ivfx_governed`);
 *  - *how is it costed?* — the costing side-car (WAC today, cost layers later). Batch
 *    **management** and batch **valuation** are independent: FIFO costing without lot identity
 *    is legitimate, as is lot tracking on a plain average cost.
 *
 * Legal on {@see SubjectKind::Unit} only — see {@see SubjectKind::allowsTracking()}.
 *
 * @api
 */
enum SubjectTracking: string
{
    /**
     * No physical identity captured. On a Unit — the only kind that may choose — this means it
     * holds both layers itself: its own commercial *and* physical slots. The default, and what a
     * base install ever sees.
     *
     * On every other kind it is the sole legal value and reads as *not applicable*: a Batch is
     * already the physical leaf, and Aggregate / Kit / NonStocked hold no physical stock to
     * identify. Hence there is no `self` case — "owns its physical layer" is **derived** from
     * (kind, tracking), never stored: as a stored value it would restate `kind` for four kinds
     * out of five and make `kind = kit, tracking = self` representable.
     */
    case None = 'none';

    /**
     * Lot / batch identity captured at intake. The unit keeps the commercial layer and
     * **delegates the physical layer** to {@see SubjectKind::Batch} children, one per
     * (unit, lot) — a second receipt of a known lot adds quantity to the existing batch
     * rather than minting a sibling.
     */
    case Lot = 'lot';

    /**
     * Per-piece identity captured at intake. Each piece is a **batch of one** — a
     * {@see SubjectKind::Batch} child holding 0 or 1 unit, its serial recorded as a
     * `serial_no` subject identifier alongside any GTIN. Resolution is per (unit, serial), so a
     * piece re-entering custody continues its own ledger rather than starting a second one.
     */
    case Serial = 'serial';

    /** Does this mode delegate the physical layer to child subjects? */
    public function delegatesPhysicalLayer(): bool
    {
        return self::None !== $this;
    }

    /** Is a piece of this subject identified individually (at most one unit per child)? */
    public function isPerPiece(): bool
    {
        return self::Serial === $this;
    }
}
