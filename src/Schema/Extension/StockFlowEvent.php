<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema\Extension;

/**
 * The domain events a stock flow can be bound to.
 *
 * **A class of constants, not an enum, and that is the design.** The set must stay open: an add-on
 * introduces events core has never heard of — an inspection passing, a transit leg departing — and
 * binds flows to them. A closed enum would make every such event a core release. Core names the
 * ones it ships bindings for; everything else is a string an add-on owns, and the naming convention
 * is the only thing shared.
 *
 * **Convention:** `<subject>.<past-tense verb>`, lower snake. The subject is what the event happened
 * *to* (`order`, `goods_receipt`, `stock`), never the flow it happens to run today — which is the
 * whole point of binding: `order.dispatched` keeps its name when an add-on reroutes it from
 * "destroy the committed units" to "move them into transit".
 *
 * ## Not to be confused with `OrderEventType`
 *
 * {@see \Nandan108\InvFlux\Domain\Order\OrderEventType} is the **recorded-history** taxonomy — what
 * gets written to `invflux_order_events` for an operator to read back. These are **routing** ids:
 * what decides which flow moves stock. The two sets overlap without coinciding, in both directions:
 *
 * - Most order events move no stock at all (`order.parked`, `correction.edited`), so they are
 *   history-only and have no business here.
 * - Most stock-flow events are not order events (a goods receipt, a stock adjustment, an
 *   inspection), so they would be meaningless in an order's history.
 *
 * **The one true overlap is dispatch**, and the two ids are deliberately *different*:
 * `OrderEventType::ShipmentSent` (`shipment.sent`) records that a shipment went out — one order can
 * emit several. {@see ORDER_DISPATCHED} routes the stock movement for the units leaving. Merging
 * them would tie "how many shipments this order had" to "how many times stock moved", which a
 * partial dispatch already breaks. They are recorded and routed independently, on purpose.
 *
 * @api
 */
final class StockFlowEvent
{
    /**
     * Units are tentatively held for a cart or checkout in progress — revocable, and expiring.
     *
     * Fired only from the checkout path. An admin-created order, a REST `POST /wc/v3/orders` and a
     * programmatic order all reach a booking without ever passing through here.
     */
    public const ORDER_RESERVED = 'order.reserved';

    /** A tentative hold is given back — the cart was abandoned, the checkout failed. */
    public const ORDER_RELEASED = 'order.released';

    /** A confirmed order takes the units it had already reserved. */
    public const ORDER_BOOKED_FROM_RESERVED = 'order.booked_from_reserved';

    /**
     * A confirmed order takes units it never reserved — it never passed through checkout, or its
     * reservation expired first. The recovery path, and the reason booking cannot assume a hold.
     */
    public const ORDER_BOOKED_RECOVERED = 'order.booked_recovered';

    /**
     * Committed units leave. Bound by default to destroying them, because in a base install the
     * moment they leave the building is the moment they leave the model.
     *
     * This is the event add-ons most want, and the reason the binding layer exists: the
     * post-dispatch journey rebinds it to a move into `trs/dsp` and binds delivery to the destroy
     * instead — the same event, a different effect, and the COGS posting rides along with it
     * because effects key on the movement type the binding supplies.
     */
    public const ORDER_DISPATCHED = 'order.dispatched';

    /** A pre-shipment correction returns committed units to availability. */
    public const CORRECTION_RESTOCK = 'correction.restock';

    /**
     * A post-shipment return puts units back that the model no longer holds — so this creates
     * rather than moves. There is no source slot: the units left at dispatch.
     */
    public const CORRECTION_RESTOCK_CREATE = 'correction.restock_create';

    /**
     * Same as {@see CORRECTION_RESTOCK_CREATE}, but the returned units land on commitments instead
     * of on availability, because other orders are oversold. Returned stock backs what is already
     * owed before it can be promised again.
     */
    public const CORRECTION_RESTOCK_CREATE_CTD = 'correction.restock_create_ctd';

    /** Committed units are written off rather than returned — damaged, lost, non-returnable. */
    public const CORRECTION_WRITEOFF_CTD = 'correction.writeoff_ctd';

    /**
     * Goods are counted in against an ordering document. Bound by default to the write-in cascade,
     * which backs outstanding commitments before it promises anything new.
     */
    public const GOODS_RECEIPT_COUNTED = 'goods_receipt.counted';

    /**
     * Stock is counted in with no document behind it — an opening balance, units found on a shelf,
     * something built in-house.
     *
     * The same physical movement as a receipt, and a separate event because the two are separately
     * *routable*. An inspection gate applies to what a supplier delivered, not to stock the
     * merchant found in their own stockroom; one event would force an add-on to gate both or
     * neither. The default bindings differ only in movement type, which is what keeps a
     * "received from suppliers" figure from counting stock nobody supplied.
     */
    public const STOCK_INTAKE_COUNTED = 'stock.intake_counted';

    /**
     * Stock is added or removed by an operator rather than by trade.
     *
     * Two events rather than one with a signed quantity, because the two directions are genuinely
     * different bindings: an increase is a plain create, while a decrease is the one an add-on
     * wants to reroute at the write-off cascade so it drains availability before it breaks
     * commitments. One event could not carry that.
     */
    public const STOCK_INCREASED = 'stock.increased';
    public const STOCK_DECREASED = 'stock.decreased';

    /**
     * A drift between InvFlux and the host's own number is being settled toward the host.
     *
     * Distinct from {@see STOCK_INCREASED} / {@see STOCK_DECREASED} even though the movement looks
     * identical: an adjustment is somebody deciding stock changed, reconciliation is InvFlux
     * accepting that it was already wrong. They carry different movement types, so they are
     * separable in the ledger, and a merchant auditing shrinkage must not find reconciliation
     * noise in the same bucket.
     */
    public const RECONCILIATION_INCREASED = 'reconciliation.increased';
    public const RECONCILIATION_DECREASED = 'reconciliation.decreased';
}
