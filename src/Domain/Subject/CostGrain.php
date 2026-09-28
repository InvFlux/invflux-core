<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * Which subject a **cost layer** hangs off — the grain at which acquisition cost is remembered.
 *
 * It answers only *where a layer lives*, never how the layer is consumed: FIFO, LIFO and weighted
 * average are all expressible at either grain, and choosing one of them is a separate decision made
 * by the costing side-car.
 *
 * **Independent of {@see SubjectTracking}, in one direction only.** Tracking answers the *physical*
 * question — whose slots hold the stock — and costing answers the *financial* one, so a lot-tracked
 * subject may perfectly well carry a single rolling average, which is what `Unit` means here.
 * The converse does not hold: `Batch` needs somewhere to put a layer, and that somewhere is a
 * {@see SubjectKind::Batch} child, which only a tracked subject has. Hence the legality rule on
 * {@see Subject}, and hence the dependency runs costing → tracking rather than the reverse.
 *
 * At Essentials the grain is **unobservable**: weighted average cost is one rolling figure per unit,
 * so every subject a base install creates is `Unit` and nothing reads the column. It becomes
 * meaningful only where cost layers and lot identity both exist.
 *
 * @api
 */
enum CostGrain: string
{
    /**
     * Layers hang off the unit subject itself — one cost history for the SKU, whatever physical
     * identity its stock carries. The default, and the only grain a base install ever has.
     */
    case Unit = 'unit';

    /**
     * Layers hang off the subject's {@see SubjectKind::Batch} children — one cost history per lot
     * or per piece, so a receipt's price stays attached to the units it actually bought.
     *
     * Requires the subject to be tracked: with no `Batch` children there is nothing to hang a
     * layer off, which is what `cost_grain_needs_tracking` on {@see Subject} enforces.
     */
    case Batch = 'batch';

    /** Does this grain put layers on child subjects rather than on the unit itself? */
    public function delegatesToChildren(): bool
    {
        return self::Batch === $this;
    }
}
