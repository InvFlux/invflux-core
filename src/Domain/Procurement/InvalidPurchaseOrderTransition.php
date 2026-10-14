<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Thrown when a purchase-order status transition is not permitted: by the edge map of
 * {@see PurchaseOrderLifecycle}, or by a rule the map cannot express, which `$reason` names
 * (for instance, canceling an order whose goods are already in). The adapter maps this to a 422.
 *
 * @api
 */
final class InvalidPurchaseOrderTransition extends \DomainException
{
    /** The order already holds received goods, so it can only finish by a close-short. */
    public const REASON_GOODS_RECEIVED = 'goods have already been received against it';

    /** Nothing has been received, so the order cannot rest as partially received. */
    public const REASON_NOTHING_RECEIVED = 'nothing has been received against it yet';

    /** A received order reopens only for units still open on it, and none are. */
    public const REASON_NOTHING_OPEN = 'nothing is left open on it';

    public function __construct(
        public readonly ?PoStatus $from,
        public readonly PoStatus $to,
        public readonly ?string $reason = null,
    ) {
        parent::__construct(sprintf(
            'Purchase order cannot transition from status %s to status %d%s.',
            null === $from ? '(none)' : (string) $from->value,
            $to->value,
            null === $reason ? '' : ': '.$reason,
        ));
    }

}
