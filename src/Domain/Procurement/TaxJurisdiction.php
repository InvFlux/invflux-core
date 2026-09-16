<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Which tax vocabulary a country uses: the scheme its businesses are registered under, and the name
 * of the tax they charge.
 *
 * Reference data, not a tax engine. It answers "what word goes in front of this number" so a
 * document can name what it prints; it decides nothing about rates, liability or place of supply.
 *
 * **Both maps list only the exceptions**, and both default to the value-added tax pattern, because
 * more than 170 jurisdictions have one and the notable holdout — the United States — is listed. An
 * unlisted country therefore reads "VAT", which is the likeliest right answer and, where it is
 * wrong, wrong in the same direction as the rest of the industry's software. Add a row rather than
 * broadening a default when a merchant reports otherwise.
 *
 * Codes are ISO 3166-1 alpha-2. A value carrying a subdivision (`CH:GE`, as WooCommerce stores the
 * store country) is accepted: the subdivision is dropped, since no listed distinction turns on it.
 * India's per-state GSTIN is the one place a subdivision matters, and that belongs to the stored
 * scheme + value model rather than to this lookup.
 *
 * @api
 */
final class TaxJurisdiction
{
    /** Countries whose registration identifier is *not* a VAT number. @var array<string, TaxIdScheme> */
    private const ID_SCHEMES = [
        'US' => TaxIdScheme::Ein,
        'CA' => TaxIdScheme::Bn,
        'RU' => TaxIdScheme::Inn,
        'BR' => TaxIdScheme::Cnpj,
        'IN' => TaxIdScheme::Gstin,
        'JP' => TaxIdScheme::JapaneseRegistration,
        'CN' => TaxIdScheme::Uscc,
        'MX' => TaxIdScheme::Rfc,
        'CH' => TaxIdScheme::Uid,
        'AU' => TaxIdScheme::Abn,
        'NZ' => TaxIdScheme::Gst,
        'SG' => TaxIdScheme::Gst,
        'AE' => TaxIdScheme::Trn,
        'SA' => TaxIdScheme::Trn,
        'BH' => TaxIdScheme::Trn,
        'OM' => TaxIdScheme::Trn,
        'EG' => TaxIdScheme::Trn,
        'KR' => TaxIdScheme::Generic,
        'MY' => TaxIdScheme::Generic,
    ];

    /** Countries whose consumption tax is *not* called a VAT. @var array<string, TaxKind> */
    private const TAX_KINDS = [
        'US' => TaxKind::SalesTax,
        'JP' => TaxKind::ConsumptionTax,
        'IN' => TaxKind::Gst,
        'AU' => TaxKind::Gst,
        'NZ' => TaxKind::Gst,
        'SG' => TaxKind::Gst,
        'CA' => TaxKind::GstHst,
        // Brazil levies ICMS, IPI, PIS, COFINS and ISS concurrently; naming one would name the
        // wrong one. Malaysia's SST is likewise two taxes under one abbreviation.
        'BR' => TaxKind::Unspecified,
        'MY' => TaxKind::Unspecified,
    ];

    /** How a business in this country is identified to its tax authority. */
    public static function idScheme(?string $country): TaxIdScheme
    {
        return self::ID_SCHEMES[self::normalize($country)] ?? TaxIdScheme::Vat;
    }

    /** What the tax charged in this country is called. */
    public static function taxKind(?string $country): TaxKind
    {
        return self::TAX_KINDS[self::normalize($country)] ?? TaxKind::Vat;
    }

    /** The bare alpha-2 code, upper-cased, with any `CC:SUBDIVISION` suffix dropped. */
    private static function normalize(?string $country): string
    {
        $code = strtoupper(trim((string) $country));
        $sep = strpos($code, ':');

        return false === $sep ? $code : substr($code, 0, $sep);
    }
}
