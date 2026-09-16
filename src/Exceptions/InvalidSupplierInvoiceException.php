<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * A supplier invoice that cannot be recorded as stated — it bills nothing, or it bills a line the
 * purchase order does not contain.
 *
 * Refusing the second case is deliberate rather than strict. A charge with no ordered line behind it
 * has nowhere to attach a rate and no receipt that will ever consume it, so recording it would put a
 * figure in the system that valuation must then be taught to ignore. It is a dispute, and disputes
 * are what the three-way match exists to hold.
 *
 * @api
 */
final class InvalidSupplierInvoiceException extends InvFluxException
{
    public static function empty(): self
    {
        return new self('An invoice records at least one billed line.');
    }

    public static function lineNotOnOrder(int $poLineId): self
    {
        return new self(sprintf('Invoice line bills order line %d, which is not on this purchase order.', $poLineId));
    }
}
