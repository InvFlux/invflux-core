<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Shipment;

/**
 * Persistence for {@see Shipment} aggregates and their {@see ShipmentLine} children.
 *
 * Shipments are append-mostly: the Essentials path writes one processed shipment per
 * order at dispatch-confirm and never mutates it (cost is immutable). Pro adds
 * the save→process lifecycle and reversals as *new* rows, never in-place edits.
 *
 * @api
 */
interface ShipmentRepository
{
    /**
     * Persist a shipment together with its lines, in one unit of work.
     *
     * Implementations save the shipment (minting its id) then bulk-insert the
     * lines against that id. Intended to run inside the caller's dispatch
     * transaction so the shipment, the inventory decrement, and the stamped
     * cost all commit together.
     *
     * @param list<ShipmentLine> $lines lines whose shipment_id is (re)assigned to $shipment->id
     */
    public function persist(Shipment $shipment, array $lines): void;

    /**
     * All shipments for one order, oldest first.
     *
     * @param string $orderId 16-byte binary UUIDv7
     *
     * @return list<Shipment>
     */
    public function forOrder(string $orderId): array;

    /**
     * Lines of one shipment.
     *
     * @param string $shipmentId 16-byte binary UUIDv7
     *
     * @return list<ShipmentLine>
     */
    public function linesForShipment(string $shipmentId): array;
}
