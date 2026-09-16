<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * The baseline a PO-line quantity variance is measured against — the "whose question are we answering"
 * lens. The same physical line has two legitimate delivery-variance readings depending on the concern:
 *
 * - {@see VarianceLens::Ordered}  → *purchasing / admin* lens: did we ultimately get what we committed
 *   to (and will pay for)? Baseline = `qty_requested`.
 * - {@see VarianceLens::Expected} → *receiving-dock / ops* lens: did we get what the supplier announced
 *   (OA / ASN / order confirmation)? Baseline = `qty_expected`, falling back to `qty_requested` when no
 *   confirmation has been recorded (so the two lenses coincide until an OA/ASN lands).
 *
 * The lens is a *display* choice on the reception surfaces (the receive grid's Expected⇄Ordered toggle,
 * the PO-detail badge); the confirmation-variance axis (ordered vs supplier-confirmed) is always
 * measured against `Ordered` and does not take a lens. `qty_invoiced` is a separate billing / 3-way-match
 * axis (Pro) and is not modelled here.
 *
 * @api
 */
enum VarianceLens: string
{
    /** Purchasing/admin baseline — what was ordered (`qty_requested`). */
    case Ordered = 'ordered';

    /** Receiving/ops baseline — what the supplier confirmed (`qty_expected ?? qty_requested`). */
    case Expected = 'expected';
}
