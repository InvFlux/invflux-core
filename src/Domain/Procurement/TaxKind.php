<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * What the tax on a purchase *is called* in the jurisdiction charging it — the word in front of the
 * rate in a totals block.
 *
 * Separate from {@see TaxIdScheme} because the two do not track each other: Russia's tax is a VAT
 * while its identifier is an INN, Japan charges a consumption tax against a registration number, and
 * a US supplier charges sales tax while quoting no tax number at all. Deriving one from the other
 * would be right often enough to hide the cases where it is wrong.
 *
 * The tax is named by whoever charges it, which on a purchase order is the **supplier**. A rate
 * quoted to us by a Bangalore supplier is GST whatever our own jurisdiction calls its taxes.
 *
 * @api
 */
enum TaxKind: string
{
    /** Value-added tax: the EU/UK pattern, and by count of jurisdictions the ordinary case worldwide. */
    case Vat = 'vat';

    /** Goods and services tax — India, Australia, New Zealand, Singapore. */
    case Gst = 'gst';

    /**
     * Canada, where the federal tax is a GST in some provinces and a *harmonized* sales tax in
     * others — 5% in Alberta, 13% in Ontario, 15% in Nova Scotia. Its own case rather than a GST,
     * because printing "GST 13%" over an Ontario supplier's HST names the wrong tax, and because
     * the pair has a settled name in both official languages (`GST/HST`, `TPS/TVH`).
     */
    case GstHst = 'gst_hst';

    /** United States: a state-level (often county-level) sales tax, with no federal equivalent above it. */
    case SalesTax = 'sales_tax';

    /** Japan: 消費税, charged under the qualified-invoice system. */
    case ConsumptionTax = 'consumption_tax';

    /**
     * The jurisdiction has no single tax worth naming on our document. Brazil is the standing case —
     * ICMS, IPI, PIS, COFINS and ISS apply at once, so a lone label would name whichever we guessed.
     * Renders as plain "Tax", which is imprecise but never contradicts the supplier's own invoice.
     */
    case Unspecified = 'unspecified';
}
