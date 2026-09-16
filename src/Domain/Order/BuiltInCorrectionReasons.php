<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * The canonical built-in reasons shipped with InvFlux core.
 *
 * Storage adapters seed `invflux_order_correction_reasons` from {@see all()} on
 * install. Add-on packages may extend the registry through the forthcoming
 * `OrderCorrectionReasonRegistrar` rather than mutating this list.
 *
 * @api
 */
final class BuiltInCorrectionReasons
{
    /**
     * Codes of built-in reasons no longer offered. Storage removes such a row wherever no correction
     * records it, and keeps it where one does, so a correction's history never loses its reason.
     */
    private const RETIRED = [
        // Said the same thing as `change_mind`; when the regret came is the correction's timing.
        'buyers_remorse',
        // An order still awaiting payment holds a reservation, not committed stock, so it is
        // cancelled rather than corrected; one paid after its reservation lapsed is a shortfall.
        'late_payment',
    ];

    /**
     * Reasons only the system records, never an operator: the late-payment shortfall is refunded
     * automatically when a customer pays after their checkout reservation lapsed, and which of the
     * two applies is the store's disclosure setting, not a judgement made on the order.
     */
    private const SYSTEM_ONLY = ['shortfall_disclosed', 'shortfall_undisclosed'];

    /**
     * Reasons that say the units held for the order are unusable or not there — so before shipment
     * they are written off, never put back on the shelf. Every other reason puts them back.
     */
    private const WRITES_OFF = ['defective', 'short_pick'];

    /**
     * Build the canonical list. Called once per install pass.
     *
     * Returned Records have `id = null`; storage `install()` assigns the TINYINT id
     * on INSERT. Each is constructed via `Record::newWith()` so `validate()` runs at
     * the source — any seed mistake fails loudly.
     *
     * @return list<OrderCorrectionReason>
     */
    public static function all(): array
    {
        $pre = CorrectionTiming::Pre;
        $post = CorrectionTiming::Post;
        $any = CorrectionTiming::Any;

        return [
            // ── Customer-side ───────────────────────────────────────────────────────────
            OrderCorrectionReason::newWith(['code' => 'change_mind',           'name' => 'Changed mind',                     'cause' => Cause::Customer, 'timing' => $any]),
            OrderCorrectionReason::newWith(['code' => 'wrong_size',            'name' => 'Wrong size / fit',                 'cause' => Cause::Customer, 'timing' => $post]),
            OrderCorrectionReason::newWith(['code' => 'shortfall_disclosed',   'name' => 'Shortfall — window disclosed',     'cause' => Cause::Customer, 'timing' => $pre]),
            OrderCorrectionReason::newWith(['code' => 'refused_at_door',       'name' => 'Refused at door',                  'cause' => Cause::Customer, 'timing' => $post]),

            // ── Merchant-side ───────────────────────────────────────────────────────────
            OrderCorrectionReason::newWith(['code' => 'defective',             'name' => 'Defective from origin',            'cause' => Cause::Merchant, 'timing' => $any]),
            OrderCorrectionReason::newWith(['code' => 'wrong_item',            'name' => 'Wrong item shipped',               'cause' => Cause::Merchant, 'timing' => $post]),
            OrderCorrectionReason::newWith(['code' => 'short_pick',            'name' => 'Missing at pick',                  'cause' => Cause::Merchant, 'timing' => $pre]),
            OrderCorrectionReason::newWith(['code' => 'out_of_stock',          'name' => 'Out of stock',                     'cause' => Cause::Merchant, 'timing' => $pre]),
            OrderCorrectionReason::newWith(['code' => 'shortfall_undisclosed', 'name' => 'Shortfall — window not disclosed', 'cause' => Cause::Merchant, 'timing' => $pre]),
            OrderCorrectionReason::newWith(['code' => 'pricing_error',         'name' => 'Pricing error',                    'cause' => Cause::Merchant, 'timing' => $pre]),
            OrderCorrectionReason::newWith(['code' => 'cannot_ship',           'name' => "Can't ship to destination",        'cause' => Cause::Merchant, 'timing' => $pre]),
            OrderCorrectionReason::newWith(['code' => 'suspected_fraud',       'name' => 'Suspected fraud',                  'cause' => Cause::Merchant, 'timing' => $pre]),

            // ── Logistics ───────────────────────────────────────────────────────────────
            OrderCorrectionReason::newWith(['code' => 'damaged_in_transit',    'name' => 'Damaged in transit',               'cause' => Cause::Logistics, 'timing' => $post]),
            OrderCorrectionReason::newWith(['code' => 'lost',                  'name' => 'Lost',                             'cause' => Cause::Logistics, 'timing' => $post]),
            OrderCorrectionReason::newWith(['code' => 'undeliverable',         'name' => 'Undeliverable',                    'cause' => Cause::Logistics, 'timing' => $post]),
        ];
    }

    /**
     * Codes of built-in reasons no longer offered — see {@see RETIRED}.
     *
     * @return list<string>
     */
    public static function retired(): array
    {
        return self::RETIRED;
    }

    /**
     * Codes of the reasons only the system records — see {@see SYSTEM_ONLY}.
     *
     * @return list<string>
     */
    public static function systemOnly(): array
    {
        return self::SYSTEM_ONLY;
    }

    /**
     * Before shipment, are this reason's units written off rather than put back on the shelf? The
     * reason alone decides: nobody shelves a defective unit, or writes off a cancellation for fraud.
     * Whether the write-off may happen at all is {@see PreShipmentWriteOff}'s to say.
     */
    public static function writesOff(string $code): bool
    {
        return \in_array($code, self::WRITES_OFF, true);
    }

    /**
     * Codes of the reasons that write units off before shipment — see {@see writesOff()}.
     *
     * @return list<string>
     */
    public static function writingOff(): array
    {
        return self::WRITES_OFF;
    }
}
