<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * What an {@see OrderCharge} is for — shipping, packing, a payment-method fee.
 *
 * **Follows UNCL 7161 wherever it has a code**, the reason-code list EN 16931 e-invoices and most
 * ERP exchanges use for document-level charges, so an export needs no mapping table of its own:
 * {@see unclCode()} is the code, and the two kinds without one are exported with their reason as
 * text.
 *
 * A source system rarely records a kind — WooCommerce's fee items carry a name and an amount and
 * nothing else — so the kind is decided when the charge is projected, and stored. See
 * {@see OrderCharge::$kind}.
 *
 * `rush` is its own kind, not a variety of `freight`, because consumer law can treat the two
 * differently: in the EU a withdrawal refunds the cost of standard delivery but not the premium the
 * consumer chose to pay over it.
 *
 * @api
 */
enum OrderChargeKind: string
{
    /** Standard delivery — shipping, and delivery fees. */
    case Freight = 'freight';

    /** The premium for express or priority delivery over the standard service. */
    case Rush = 'rush';

    /** Packaging, and gift wrap bought for the whole order. */
    case Packing = 'packing';

    /** Handling and delivery options — a signature on delivery, special handling. */
    case Handling = 'handling';

    /** Instalment or credit charges. */
    case Financing = 'financing';

    /** A fee for the means of payment the customer chose. UNCL 7161 has no code for it. */
    case Payment = 'payment';

    /** Not yet classified. */
    case Other = 'other';

    /** The UNCL 7161 allowance/charge reason code, or null for a kind the list has no code for. */
    public function unclCode(): ?string
    {
        return match ($this) {
            self::Freight   => 'FC',
            self::Rush      => 'AAT',
            self::Packing   => 'PC',
            self::Handling  => 'HD',
            self::Financing => 'FI',
            self::Payment,
            self::Other => null,
        };
    }
}
