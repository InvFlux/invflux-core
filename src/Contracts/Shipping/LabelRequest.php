<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Shipping;

/**
 * What a {@see LabelProvider} is asked to label: one shipment, as InvFlux knows it.
 *
 * Identifiers only, plus the quantities leaving. Addresses, weights and customs data belong to the
 * host order and the products, which the integration reads from the host it is installed on; copying
 * them here would make this a second, staler source of facts the host already holds.
 *
 * @api
 */
final class LabelRequest
{
    /**
     * @param string                                $shipmentId     the shipment's id, 32-char lowercase hex
     * @param string                                $hostOrderId    the order's id in the host
     * @param string                                $carrierCode    one of the provider's {@see LabelProvider::carrierCodes()}
     * @param string                                $serviceType    the carrier service asked for, or '' for the provider's default
     * @param list<array{subjectId: int, qty: int}> $lines          what is leaving, per subject
     * @param array<string, mixed>                  $serviceOptions provider-specific options, passed through untouched
     */
    public function __construct(
        public readonly string $shipmentId,
        public readonly string $hostOrderId,
        public readonly string $carrierCode,
        public readonly string $serviceType,
        public readonly array $lines,
        public readonly array $serviceOptions = [],
    ) {
    }
}
