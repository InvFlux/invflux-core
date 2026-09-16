<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Thrown when a purchase-order status transition is not permitted by
 * {@see PurchaseOrderLifecycle}. The adapter maps this to a 422.
 *
 * @api
 */
final class InvalidPurchaseOrderTransition extends \DomainException
{
    public function __construct(
        public readonly ?PoStatus $from,
        public readonly PoStatus $to,
    ) {
        parent::__construct(sprintf(
            'Purchase order cannot transition from status %s to status %d.',
            null === $from ? '(none)' : (string) $from->value,
            $to->value,
        ));
    }
}
