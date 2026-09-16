<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * The canonical built-in order-correction-type registry shipped with InvFlux core.
 *
 * Storage adapters seed `invflux_order_correction_types` from {@see all()} on install.
 * Add-on packages may extend the registry through the forthcoming
 * `OrderCorrectionTypeRegistrar` rather than mutating this list.
 *
 * Tier gating is **not** modelled here. The full set is seeded; tier-specific
 * visibility (e.g., post-shipment return types being Pro-only in the workbench UI) is
 * enforced at the application layer via `LicenseGate::allows()`.
 *
 * Flag matrix: `pre_dispatch × restock` forms the 2×2 that drives slot movement;
 * `refund` is the default-refund-owed flag. Fault attribution is NOT here — it
 * derives from {@see Cause} (on the reason) plus correction-type direction plus
 * store policy, at the application layer.
 *
 * @api
 */
final class BuiltInCorrectionTypes
{
    /**
     * Build the canonical list. Called once per install pass.
     *
     * Returned Records have `id = null` (pre-persistence); storage `install()` is
     * responsible for INSERTing and assigning the TINYINT id. Each is constructed via
     * `Record::newWith()` so `validate()` runs immediately and any seed mistake fails
     * loudly at the source.
     *
     * @return list<OrderCorrectionType>
     */
    public static function all(): array
    {
        return [
            // ── Cancellations (pre-shipment, restock + refund) ──────────────────────────
            OrderCorrectionType::newWith(['code' => 'cancel_system',   'name' => 'System cancelled',      'pre_dispatch' => true,  'restock' => true,  'refund' => true]),
            OrderCorrectionType::newWith(['code' => 'cancel_customer', 'name' => 'Cancelled by customer', 'pre_dispatch' => true,  'restock' => true,  'refund' => true]),
            OrderCorrectionType::newWith(['code' => 'cancel_merchant', 'name' => 'Cancelled by merchant', 'pre_dispatch' => true,  'restock' => true,  'refund' => true]),

            // ── Cancellation reversal (order reinstated: cancelled → processing) ─────────
            // The compensating record for un-doing a cancellation: it RESTORES the line's
            // outstanding (decrements `qty_corrected`) and its unsettled cancel-refund is waived —
            // it never mutates the original processed cancel correction. Flags are `false/false`
            // (no engine slot movement of its own; the stock re-book rides the normal booking path,
            // and the write is handled by the dedicated reversal path, not the 2×2 engine).
            OrderCorrectionType::newWith(['code' => 'cancel_reversal', 'name' => 'Cancellation reversed (reinstated)', 'pre_dispatch' => false, 'restock' => false, 'refund' => false]),

            // ── Pre-shipment write-offs (refund but no restock — stock destroyed/lost) ─
            OrderCorrectionType::newWith(['code' => 'writeoff_defective', 'name' => 'Pre-shipment write-off: defective', 'pre_dispatch' => true, 'restock' => false, 'refund' => true]),
            OrderCorrectionType::newWith(['code' => 'writeoff_missing',   'name' => 'Pre-shipment write-off: missing',   'pre_dispatch' => true, 'restock' => false, 'refund' => true]),

            // ── Late-payment shortfall (refund only; qty never moved out of atp) ────────
            OrderCorrectionType::newWith(['code' => 'shortfall_disclosed',   'name' => 'Late-payment shortfall (window disclosed)',     'pre_dispatch' => true, 'restock' => false, 'refund' => true]),
            OrderCorrectionType::newWith(['code' => 'shortfall_undisclosed', 'name' => 'Late-payment shortfall (window not disclosed)', 'pre_dispatch' => true, 'restock' => false, 'refund' => true]),

            // ── Capture-time shortfall (refund only; stock insufficient when a manual
            //    payment was recorded — distinct from the late-payment-window cases above) ─
            OrderCorrectionType::newWith(['code' => 'capture_short', 'name' => 'Insufficient stock at capture', 'pre_dispatch' => true, 'restock' => false, 'refund' => true]),

            // ── Post-shipment returns (no pre-dispatch flag — already shipped) ──────────
            OrderCorrectionType::newWith(['code' => 'return_resaleable',           'name' => 'Return: resaleable',           'pre_dispatch' => false, 'restock' => true, 'refund' => true]),
            OrderCorrectionType::newWith(['code' => 'return_resaleable_exception', 'name' => 'Return: resaleable exception', 'pre_dispatch' => false, 'restock' => true, 'refund' => true]),

            // ── Foreign (WC-direct) refunds mirrored into InvFlux ───────────────────────
            // Operator refunded the order in the WooCommerce screen, not through Dispatch.
            // ForeignRefundCorrectionWriter selects the type by (restock × live dispatch state),
            // creates the correction UNPROCESSED, then calls process() — routing through the
            // canonical 2×2 engine. All four quadrants carry accurate pre_dispatch/restock flags:
            //   (false, false) → no slot movement  (post-dispatch, no restock)
            //   (false, true)  → nil → atp          (post-dispatch, restock)
            //   (true,  false) → ctd → nil          (pre-dispatch, no restock)
            //   (true,  true)  → ctd → atp          (pre-dispatch, restock)
            // `refund=true` is an inert "refund owed" UI hint; the money is already in WC.
            OrderCorrectionType::newWith(['code' => 'refund_external',             'name' => 'External refund (post-dispatch, no restock)', 'pre_dispatch' => false, 'restock' => false, 'refund' => true]),
            OrderCorrectionType::newWith(['code' => 'refund_external_restock',     'name' => 'External restocked refund (post-dispatch)',    'pre_dispatch' => false, 'restock' => true,  'refund' => true]),
            OrderCorrectionType::newWith(['code' => 'refund_external_pre',         'name' => 'External refund (pre-dispatch, no restock)',   'pre_dispatch' => true,  'restock' => false, 'refund' => true]),
            OrderCorrectionType::newWith(['code' => 'refund_external_pre_restock', 'name' => 'External restocked refund (pre-dispatch)',     'pre_dispatch' => true,  'restock' => true,  'refund' => true]),
        ];
    }

    /**
     * Type code => the reason a correction of that type records unless told otherwise.
     *
     * A **default, never the fault itself**: fault is the reason's {@see Cause}, and an operator
     * can always choose another reason. Only a type with one obvious reason has a default. None for
     * a type that is the customer's fault as often as the merchant's — a return (changed mind, or
     * wrong item shipped), a refund issued outside InvFlux, `cancel_system` (which two writers use
     * for unrelated reasons) — nor for `cancel_merchant`, whose reasons (a pricing error, suspected
     * fraud, a destination that cannot be served) have nothing in common but the merchant. For
     * those, the caller states the reason or records none.
     */
    private const DEFAULT_REASONS = [
        'cancel_customer'       => 'change_mind',
        'writeoff_defective'    => 'defective',
        'writeoff_missing'      => 'short_pick',
        'shortfall_disclosed'   => 'shortfall_disclosed',
        'shortfall_undisclosed' => 'shortfall_undisclosed',
        'capture_short'         => 'out_of_stock',
    ];

    /**
     * The reason code a correction of this type records by default, or null when the type has no
     * default — see {@see DEFAULT_REASONS}. Every code returned is one of
     * {@see BuiltInCorrectionReasons::all()}.
     */
    public static function defaultReasonCode(string $typeCode): ?string
    {
        return self::DEFAULT_REASONS[$typeCode] ?? null;
    }
}
