<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema\Extension;

use Nandan108\InvFlux\Domain\Procurement\ProcurementMovementType;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;

/**
 * Default bindings for the events **core's own use cases** raise.
 *
 * The ownership rule: whoever owns the use case owns its default binding. `ReceiveGoods` lives in
 * core, so what a goods receipt does by default is core's answer to give — an adapter that had to
 * supply it would be answering a question about code it does not own, and every adapter would
 * answer identically.
 *
 * The order lifecycle and corrections are the mirror image: those use cases live in the adapter, so
 * their bindings do too.
 *
 * **Folded by the assembler automatically, not registered as a tagged service.** These bindings are
 * not optional — `ReceiveGoods` refuses to move stock without one, deliberately — so making them a
 * registration step would turn "somebody forgot to wire it" into the exact failure that refusal
 * exists to catch. An add-on still overrides them the ordinary way, by binding the same event at a
 * higher priority.
 *
 * @api
 */
final class BaseFlowBindings implements SlotSpaceContributor
{
    public const KEY = 'invflux_core';

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function priority(): int
    {
        return 0;
    }

    #[\Override]
    public function contribute(SlotSpaceContribution $contribution): void
    {
        // Both are write-ins — a receipt IS one — and they differ only in movement type, which is
        // what stops a "received from suppliers" figure counting stock nobody supplied. They are
        // two events rather than one because they are separately routable: an inspection gate
        // belongs on a supplier delivery, not on stock found in the merchant's own stockroom.
        $contribution
            ->bindEvent(
                StockFlowEvent::GOODS_RECEIPT_COUNTED,
                SlotSpaceFactory::FLOW_WRITE_IN,
                ProcurementMovementType::PO_RECEIPT,
            )
            ->bindEvent(
                StockFlowEvent::STOCK_INTAKE_COUNTED,
                SlotSpaceFactory::FLOW_WRITE_IN,
                ProcurementMovementType::STOCK_INTAKE,
            );
    }
}
