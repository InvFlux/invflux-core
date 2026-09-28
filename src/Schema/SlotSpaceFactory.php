<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

use Nandan108\InvFlux\Flow\FlowDefinition;
use Nandan108\InvFlux\Layer\LayeredSlotSpaceDefinition;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\FlowContext;

/**
 * Build the default layered slot-space definition shared across adapters.
 *
 * Two layers:
 *  - commercial: stt × loc — tracks sale state per warehouse; loc is a shared dimension
 *  - physical:   loc (leaf values only) — tracks stock by warehouse/bin; shared dimension
 *
 * The loc dimension is shared: its values are registered via addDimensionValues() and
 * loaded from DB at bootstrap. Commercial sees all warehouse values; physical sees only
 * leaf values (bins when a multi-level location hierarchy is configured).
 *
 * @api
 */
final class SlotSpaceFactory
{
    public const LAYER_COMMERCIAL = 'commercial';
    public const LAYER_PHYSICAL = 'physical';

    /**
     * The `loc` value a fresh slot space is initialised with: the **root of on-hand locations**.
     * Not a warehouse and not warehouse-level — warehouses are `oh`'s children once a
     * multi-location tier registers them.
     *
     * To find out where stock currently goes, ask {@see SlotDefaultResolver}.
     */
    public const DEFAULT_LOCATION_SEED = 'oh';

    /**
     * The structural level naming a warehouse — a place the merchant holds stock in.
     *
     * Matching on a level is **depth-independent**, so a warehouse keeps matching this name however
     * many grouping tiers a merchant later nests it under (region, country). That is what lets the
     * name stay accurate as the tree deepens, and why the answer to "the commercial layer must also
     * address supplier-side and transit stock" is a second level name rather than a broader reading
     * of this one — see {@see SharedDimensionValues::LEVEL_EXTERNAL}.
     */
    public const LEVEL_WAREHOUSE = 'warehouse';

    /**
     * Execute-time parameters the flows below name in their patterns.
     *
     * A pattern value of `{location}` is substituted from the execute params when the flow runs
     * (see the movement engine's `ResolvesFlowParameters`), so **one** definition serves every
     * warehouse instead of one built per call. A parameter the caller does not supply is refused
     * where it is substituted, naming the parameter — never silently matched against nothing.
     */
    public const PARAM_LOCATION = 'location';
    public const PARAM_SLOT = 'slot';
    public const PARAM_DEFICIT = 'deficit';
    public const PARAM_RESERVED_DEFICIT = 'reserved_deficit';

    private const AT_LOCATION = '{'.self::PARAM_LOCATION.'}';
    private const ANY_SLOT = '{'.self::PARAM_SLOT.'}';

    /**
     * The parameterized flows, named so a caller (or an event binding) can ask for one by name.
     *
     * These were `Flow` **builders** taking `$locationValue`, which meant they could not live in
     * the flow map and so could not be named by anything — an event binding included. As
     * parameterized definitions they are ordinary registered flows.
     */
    public const FLOW_WRITE_IN = 'write_in';
    public const FLOW_WRITE_OFF = 'write_off';
    public const FLOW_STOCK_ADJUST_ATP_ADD = 'stock_adjust_atp_add';
    public const FLOW_STOCK_ADJUST_ATP_SUB = 'stock_adjust_atp_sub';
    public const FLOW_RECONCILE_ADD = 'reconcile_add';
    public const FLOW_RECONCILE_SUB = 'reconcile_sub';

    /** Create the full layered slot-space definition with all registered flows. */
    public function createLayered(): LayeredSlotSpaceDefinition
    {
        $commercial = SlotSpaceDefinition::define(self::LAYER_COMMERCIAL, [
            // `stt` is a shared (DB-backed) dimension exactly like `loc`: its native values
            // (atp/res/ctd) are seeded at bootstrap via addDimensionValues(), and add-ons register
            // further states (sup.*, pnd, qi, bkd) the same way. `Stt` only *names* the native set;
            // it does not close the dimension. Flat values → the `root()` selector (parent_id IS
            // NULL) admits them all.
            // `position` fixes where a dimension lands in the serialized slot key, so `loc` first
            // spells a slot `oh.atp` — place, then what the stock there is promisable for. That is
            // the order the whole codebase already reads in prose and in movement labels, and the
            // order the model is built in: the physical fact is the one that exists independently,
            // and the commercial state is an assertion made about it.
            //
            // The commercial layer addresses stock at a coarse grain, and that grain spans two
            // kinds of place: warehouses, and the locations that are not warehouses and never will
            // be — supplier-side stock, transit legs, a customer holding goods within a return
            // window. Both are selected; neither is mislabelled as the other. Contributed values
            // carry `external`, so a base install still selects exactly one warehouse.
            DimensionDefinition::sharedRef('loc', position: 0, valueSelector: DimensionValueSelector::levels(
                self::LEVEL_WAREHOUSE,
                SharedDimensionValues::LEVEL_EXTERNAL,
            )),
            // `stt` is a shared (DB-backed) dimension exactly like `loc`: its native values
            // (atp/res/ctd) are seeded at bootstrap via addDimensionValues(), and add-ons register
            // further states (sup.*, pnd, qi, bkd) the same way. `Stt` only *names* the native set;
            // it does not close the dimension. Flat values → the `root()` selector (parent_id IS
            // NULL) admits them all.
            DimensionDefinition::sharedRef('stt', position: 1, valueSelector: DimensionValueSelector::root()),
        ])
            ->withFlow(FlowDefinition::define('reserve')->move(['stt' => Stt::ATP], ['stt' => Stt::RES]))
            ->withFlow(FlowDefinition::define('release')->move(['stt' => Stt::RES], ['stt' => Stt::ATP]))
            ->withFlow(FlowDefinition::define('book_reserved')->move(['stt' => Stt::RES], ['stt' => Stt::CTD]))
            // These two look like they should be the same flow and **deliberately are not**. Both
            // commit demand for an order holding no reservation of its own; they differ in whether
            // they may revoke somebody else's tentative hold to do it. See
            // {@see bookDemandGrowthFlow()} for the reasoning — do not unify them.
            ->withFlow(FlowDefinition::define('book_recovered')->move(['stt' => Stt::ATP], ['stt' => Stt::CTD]))
            ->withFlow(self::bookDemandGrowthFlow())
            ->withFlow(FlowDefinition::define('clear_ctd')->destroy(['stt' => Stt::CTD]))
            ->withFlow(FlowDefinition::define('correction_restock')->move(['stt' => Stt::CTD], ['stt' => Stt::ATP]))
            ->withFlow(FlowDefinition::define('correction_writeoff_ctd')->destroy(['stt' => Stt::CTD]))
            // Post-dispatch return restock: the units already left ctd (cleared to nil on
            // dispatch), so restocking them CREATES into atp (no source slot to move from).
            // loc resolves via the layer sharedRef; callers scope to a single warehouse via
            // DimensionScope so the create target is unambiguous.
            ->withFlow(FlowDefinition::define('correction_restock_create')->create(['stt' => Stt::ATP]))
            // Post-dispatch return restock into ctd — when a return arrives while other orders are
            // oversold (a ctd deficit), the demand-aware fill routes the healing portion here first
            // (Domain\Stock\SlotAllocationCascade), so returned physical stock backs committed orders
            // before it can become sellable (maintains "ctd deficit ⇒ atp = res = 0").
            ->withFlow(FlowDefinition::define('correction_restock_create_ctd')->create(['stt' => Stt::CTD]))
            // Post-dispatch return restock into `res` — the same fill, one band up: with commitments
            // whole but a live checkout's hold unbacked, the returned units back that hold rather than
            // going on sale under it. Without this leg the fill jumps from `ctd` to `atp` and `atp`
            // overstates availability by the unbacked band.
            ->withFlow(FlowDefinition::define('correction_restock_create_res')->create(['stt' => Stt::RES]))
            // ── Parameterized flows: `{location}` is bound at execute time ──────────────────
            //
            // Write-in priority cascade — *back what is already claimed before promising more*. A
            // rising physical surface refills the states in claim order: step 1 creates into `ctd`,
            // **capped at the per-subject `deficit`** (confirmed order demand not yet physically
            // backed); step 2 into `res`, **capped at the `reserved_deficit`** (quantity held by live
            // checkouts and not physically backed either); step 3 spills the remainder into `atp`.
            // Both caps are **cross-domain** — they live in the order domain (backorders, live
            // reservations), invisible to the slot space — so the caller supplies them as execute
            // params. **Absent ⇒ 0**, which collapses this to a plain `create(atp)`: exactly the
            // historical `nil → atp` receipt. Used by goods receipt (under a `po_receipt` or
            // `stock_intake` movement type — the flow is the same physical event either way) and by
            // positive corrections.
            //
            // Skipping `res` would not merely delay a hold's backing: it makes `atp` **overstate
            // availability** by the size of the unbacked band, so the restocked units are offered to
            // other shoppers while a live cart is holding them.
            ->withFlow(
                FlowDefinition::define(self::FLOW_WRITE_IN)
                    ->create(['stt' => Stt::CTD, 'loc' => self::AT_LOCATION])
                    ->constraint(static fn (MovementEdge $edge, FlowContext $ctx): int => self::deficitCap($ctx))
                    ->create(['stt' => Stt::RES, 'loc' => self::AT_LOCATION])
                    ->constraint(static fn (MovementEdge $edge, FlowContext $ctx): int => self::reservedDeficitCap($ctx))
                    ->create(['stt' => Stt::ATP, 'loc' => self::AT_LOCATION]),
            )
            // Write-off priority cascade — *protect commitments*: drain `atp` first, then revoke
            // tentative holds (`res`), then, only as a last resort, break firm commitments (`ctd`).
            // The solver drains each step's slot up to its availability and threads the unsatisfied
            // remainder to the next. Crossing out of `atp` into `res`/`ctd` is a **commitment breach**
            // that must emit an exception at the call site (deferred — §2.1). For non-dispatch
            // write-offs (shrinkage / scrap) and GR corrections; dispatch draws `ctd` directly via
            // `clear_ctd`.
            ->withFlow(
                FlowDefinition::define(self::FLOW_WRITE_OFF)
                    ->destroy(['stt' => Stt::ATP, 'loc' => self::AT_LOCATION])
                    ->destroy(['stt' => Stt::RES, 'loc' => self::AT_LOCATION])
                    ->destroy(['stt' => Stt::CTD, 'loc' => self::AT_LOCATION]),
            )
            // Stock adjustment against free stock. Pinned to `atp` in the definition rather than
            // parameterized like the reconcile pair below: "adjust free stock" is what this operation
            // *means*, and a caller able to name the state would be doing something else.
            ->withFlow(FlowDefinition::define(self::FLOW_STOCK_ADJUST_ATP_ADD)->create(['stt' => Stt::ATP, 'loc' => self::AT_LOCATION]))
            ->withFlow(FlowDefinition::define(self::FLOW_STOCK_ADJUST_ATP_SUB)->destroy(['stt' => Stt::ATP, 'loc' => self::AT_LOCATION]))
            // Reconcile one slot to a desired quantity. `{slot}` is a parameter because the caller
            // reconciles whichever slot drifted — but only a **native** one, which is a regime
            // boundary rather than a limitation of the pattern, so it is enforced by
            // {@see reconciliationFlowName()} and not left to whatever the codec happens to accept.
            ->withFlow(FlowDefinition::define(self::FLOW_RECONCILE_ADD)->create(['stt' => self::ANY_SLOT, 'loc' => self::AT_LOCATION]))
            ->withFlow(FlowDefinition::define(self::FLOW_RECONCILE_SUB)->destroy(['stt' => self::ANY_SLOT, 'loc' => self::AT_LOCATION]));

        // clear_ctd destroys all slots matched by the engine after DimensionScope pre-filters
        // the physical rows to the single scoped loc value, so the empty pattern [] is correct.
        $physical = SlotSpaceDefinition::define(self::LAYER_PHYSICAL, [
            // Position 0, matching the commercial layer: a position is a dimension's place in the
            // key across the whole space, not an index within one layer, so `loc` carries the same
            // one everywhere it appears. This layer declares no `stt`, so its keys are a bare
            // `oh` — a slot key never renders an absent dimension.
            DimensionDefinition::sharedRef('loc', position: 0, valueSelector: DimensionValueSelector::leaf()),
        ])
            ->withFlow(FlowDefinition::define('clear_ctd')->destroy([]));

        return LayeredSlotSpaceDefinition::define([
            self::LAYER_COMMERCIAL => $commercial,
            self::LAYER_PHYSICAL   => $physical,
        ]);
    }

    /**
     * The flow that reconciles one commercial slot toward a desired quantity, by name.
     *
     * Returns a name rather than a built flow because the flow itself is registered and
     * parameterized; what the caller needs decided here is *which* of the pair, plus the two
     * refusals that are not the pattern's business:
     *
     * - **Native slots only.** This is a **regime marker, not a hardcoding.** Inbound
     *   reconciliation is only meaningful while the host's single stock number maps onto a simple
     *   native space. Once add-ons enrich the space — several locations, several channels,
     *   non-native states — that number is an *aggregate projection of the `atp` marginal*, and
     *   writing back to an aggregate is meaningless. Leaving the check to the codec would silently
     *   widen the regime to whatever values happened to be registered.
     * - **Non-zero delta.** A zero-delta reconciliation is a caller that failed to notice there was
     *   nothing to do; executing it would append a ledger row recording no movement.
     *
     * Execute with `params: [PARAM_SLOT => $slotKey, PARAM_LOCATION => $locationValue]`.
     *
     * @psalm-param non-empty-string $slotKey
     *
     * @return non-empty-string
     */
    public function reconciliationFlowName(string $slotKey, int $delta): string
    {
        if (!in_array($slotKey, Stt::NATIVE, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported reconciliation slot "%s".', $slotKey));
        }
        if (0 === $delta) {
            throw new \InvalidArgumentException('Reconciliation delta must be non-zero.');
        }

        return $delta > 0 ? self::FLOW_RECONCILE_ADD : self::FLOW_RECONCILE_SUB;
    }

    /**
     * The stock-adjustment flow for a free-stock delta, by name.
     *
     * Execute with `params: [PARAM_LOCATION => $locationValue]`.
     *
     * @return non-empty-string
     */
    public function stockAdjustAtpFlowName(int $delta): string
    {
        if (0 === $delta) {
            throw new \InvalidArgumentException('Stock-adjustment free stock delta must be non-zero.');
        }

        return $delta > 0 ? self::FLOW_STOCK_ADJUST_ATP_ADD : self::FLOW_STOCK_ADJUST_ATP_SUB;
    }

    /**
     * Demand *grew* on an order already holding a commitment — a document raising a line. Commits
     * the newly owed units free stock first, then out of tentative holds, and the engine threads
     * each step's unsatisfied remainder to the next, so what neither slot can cover stays
     * outstanding demand and surfaces as a deficit.
     *
     * **Why this revokes tentative holds and `book_recovered` does not.** The two are otherwise the
     * same act, and the difference is whose decision is being honoured:
     *
     * - A *late payment* recovering its claim must stop at `atp`. The `hold_stock_minutes` release
     *   already adjudicated that this order's hold had expired; falling back to `res` silently
     *   reverses that adjudication at a third party's expense. The customer who paid on time would
     *   lose the unit to the customer who did not, and would never learn why.
     * - A *document raising what is owed* is a deliberate act on a confirmed order, reversing no
     *   prior decision. Nothing can stop an operator from over-committing an order this way, so the
     *   requirement is that the consequence lands correctly rather than that it be prevented — which
     *   means the newly owed units are taken, and whoever's hold was revoked finds out at their own
     *   booking rather than silently shipping short.
     *
     * So the asymmetry is a fairness policy, not an oversight, and it costs the *strong* form of the
     * commitment invariant: a `ctd` deficit may now rest beside a positive `res`. That is sound —
     * `res` is not promisable, so it oversells nothing; only a deficit beside positive **`atp`** is
     * an oversell, and that remains impossible.
     *
     * Callers must **not** pre-cap the requested quantity at `atp`: it makes the second step
     * unreachable and reinstates the bug one layer up, where it is much harder to see.
     */
    private static function bookDemandGrowthFlow(): FlowDefinition
    {
        return FlowDefinition::define('book_demand_growth')
            ->move(['stt' => Stt::ATP], ['stt' => Stt::CTD])
            ->move(['stt' => Stt::RES], ['stt' => Stt::CTD]);
    }

    /**
     * The `ctd`-fill cap for the write-in cascade: the caller-supplied {@see PARAM_DEFICIT} execute
     * param (confirmed-but-unbacked demand), clamped ≥ 0. Absent / non-numeric ⇒ 0 (no commitment
     * backing this receipt), so the write-in behaves as a plain `create(atp)`.
     *
     * Absent is a **valid** answer here, unlike {@see PARAM_LOCATION}, which the engine refuses —
     * the difference being that a missing cap has a correct default and a missing target does not.
     */
    private static function deficitCap(FlowContext $ctx): int
    {
        return self::numericParam($ctx, self::PARAM_DEFICIT);
    }

    /**
     * The `res`-fill cap for the write-in cascade: the caller-supplied {@see PARAM_RESERVED_DEFICIT}
     * execute param — quantity held by live checkouts that no physical unit backs — clamped ≥ 0.
     * Absent / non-numeric ⇒ 0, so a caller that tracks no reservations skips the band entirely.
     *
     * Separate from {@see PARAM_DEFICIT} because the two are different claims by different parties,
     * and a caller may legitimately know one and not the other.
     */
    private static function reservedDeficitCap(FlowContext $ctx): int
    {
        return self::numericParam($ctx, self::PARAM_RESERVED_DEFICIT);
    }

    /** One execute param read as a non-negative integer; absent / non-numeric ⇒ 0. */
    private static function numericParam(FlowContext $ctx, string $name): int
    {
        /** @psalm-var mixed $params */
        $params = $ctx->context['params'] ?? null;
        /** @psalm-var mixed $value */
        $value = \is_array($params) ? ($params[$name] ?? null) : null;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
