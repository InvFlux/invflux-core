<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Shipping;

/**
 * Produces a carrier label for a shipment — the one interface a label integration implements.
 *
 * InvFlux never talks to a carrier, holds a carrier credential or renders a label itself: that is
 * the integration's domain. What InvFlux owns is the shipment, and the moment a label is wanted is
 * the moment the shipment is being confirmed. So the contract is narrow on purpose — a description
 * of what is leaving in, a tracking number and a label location out — and everything carrier-shaped
 * (rates, services, credentials, printing) stays on the integration's side of it.
 *
 * Called synchronously, while the operator waits at the packing bench. An integration whose carrier
 * answers slowly is better used from its own screens, with its shipments recorded back into InvFlux.
 *
 * Implementations are registered by the integration on `invflux_container_built`; the base install
 * registers none and calls none.
 *
 * @api
 */
interface LabelProvider
{
    /**
     * The carrier codes this provider answers for (`'colissimo'`, `'dhl_express'`, …). The codes are
     * the integration's own vocabulary; InvFlux only stores them on the shipment and routes by them.
     *
     * @return list<non-empty-string>
     */
    public function carrierCodes(): array;

    /**
     * Produce the label for one shipment.
     *
     * @throws LabelProductionException when no label could be produced — transient (the caller may
     *                                  retry) or permanent (the shipment is left for the operator)
     */
    public function produceLabel(LabelRequest $request): LabelArtifact;
}
