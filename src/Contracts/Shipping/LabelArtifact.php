<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Shipping;

/**
 * What a {@see LabelProvider} returns: where the label is, and how to follow the parcel.
 *
 * `labelUrl` is a location, never the label itself — a signed link or an attachment URL the operator's
 * browser opens for printing. InvFlux stores the reference and never holds the document.
 *
 * @api
 */
final class LabelArtifact
{
    /**
     * @param string               $trackingNumber the carrier's tracking number
     * @param string               $labelUrl       where the printable label can be fetched
     * @param string               $externalRef    the provider's own id for this shipment, kept for later reconciliation
     * @param string               $trackingUrl    a public tracking page, or '' when the provider has none
     * @param array<string, mixed> $rawPayload     provider-specific extras, stored with the shipment's service options
     */
    public function __construct(
        public readonly string $trackingNumber,
        public readonly string $labelUrl,
        public readonly string $externalRef,
        public readonly string $trackingUrl = '',
        public readonly array $rawPayload = [],
    ) {
    }
}
