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
            DimensionDefinition::sharedRef('stt', position: 0, valueSelector: DimensionValueSelector::root()),
            // The commercial layer addresses stock at a coarse grain, and that grain spans two
            // kinds of place: warehouses, and the locations that are not warehouses and never will
            // be — supplier-side stock, transit legs, a customer holding goods within a return
            // window. Both are selected; neither is mislabelled as the other. Contributed values
            // carry `external`, so a base install still selects exactly one warehouse.
            DimensionDefinition::sharedRef('loc', position: 1, valueSelector: DimensionValueSelector::levels(
                self::LEVEL_WAREHOUSE,
                SharedDimensionValues::LEVEL_EXTERNAL,
            )),
        ])
            ->withFlow(FlowDefinition::define('reserve')->move(['stt' => Stt::ATP], ['stt' => Stt::RES]))
            ->withFlow(FlowDefinition::define('release')->move(['stt' => Stt::RES], ['stt' => Stt::ATP]))
            ->withFlow(FlowDefinition::define('book_reserved')->move(['stt' => Stt::RES], ['stt' => Stt::CTD]))
            ->withFlow(FlowDefinition::define('book_recovered')->move(['stt' => Stt::ATP], ['stt' => Stt::CTD]))
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
            // ── Parameterized flows: `{location}` is bound at execute time ──────────────────
            //
            // Write-in priority cascade — *fill confirmed commitments before promising more*. Step 1
            // creates into `ctd`, **capped at the per-subject `deficit`** (confirmed order demand not
            // yet physically backed); step 2 spills the remainder into `atp`. The deficit is
            // **cross-domain** — it lives in the order domain (backorders), invisible to the slot
            // space — so the caller supplies it as an execute param. **Absent ⇒ 0**, which collapses
            // this to a plain `create(atp)`: exactly the historical `nil → atp` receipt. Used by goods
            // receipt (under a `po_receipt` or `stock_intake` movement type — the flow is the same
            // physical event either way) and by positive corrections.
            ->withFlow(
                FlowDefinition::define(self::FLOW_WRITE_IN)
                    ->create(['stt' => Stt::CTD, 'loc' => self::AT_LOCATION])
                    ->constraint(static fn (MovementEdge $edge, FlowContext $ctx): int => self::deficitCap($ctx))
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
            DimensionDefinition::sharedRef('loc', position: 1, valueSelector: DimensionValueSelector::leaf()),
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
     * The `ctd`-fill cap for the write-in cascade: the caller-supplied {@see PARAM_DEFICIT} execute
     * param (confirmed-but-unbacked demand), clamped ≥ 0. Absent / non-numeric ⇒ 0 (no commitment
     * backing this receipt), so the write-in behaves as a plain `create(atp)`.
     *
     * Absent is a **valid** answer here, unlike {@see PARAM_LOCATION}, which the engine refuses —
     * the difference being that a missing cap has a correct default and a missing target does not.
     */
    private static function deficitCap(FlowContext $ctx): int
    {
        /** @psalm-var mixed $params */
        $params = $ctx->context['params'] ?? null;
        /** @psalm-var mixed $deficit */
        $deficit = \is_array($params) ? ($params[self::PARAM_DEFICIT] ?? null) : null;

        return is_numeric($deficit) ? max(0, (int) $deficit) : 0;
    }
}
