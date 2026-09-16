<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * When a correction reason can apply, relative to shipment.
 *
 * A late payment is known before anything ships; a size that does not fit, a parcel refused at the
 * door or lost by the carrier, only after. Offering an operator correcting unshipped units the
 * reasons that can only happen to shipped ones is noise at best, and a false record at worst.
 *
 * @api
 */
enum CorrectionTiming: string
{
    /** Only before the units ship. */
    case Pre = 'pre';

    /** Only once they have shipped. */
    case Post = 'post';

    /** Either side of shipment — a defect, a change of mind. */
    case Any = 'any';

    /** Can a reason with this timing explain a correction of a pre-dispatch (`true`) or post-dispatch type? */
    public function appliesTo(bool $preDispatch): bool
    {
        return self::Any === $this || (self::Pre === $this) === $preDispatch;
    }
}
