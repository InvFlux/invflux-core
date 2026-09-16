<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Writing a street name and a house number as one line, the way the destination country writes it.
 *
 * **A rendering rule, deliberately not an identity one.** Nothing here may reach
 * {@see DocumentParty::digest()}: a stored, pre-joined street line would put this table inside the
 * identity function, and then adding one country to it re-keys parties and orphans every pointer.
 * The parts are what a party stores; this is only how they are read back out. That is why the table
 * below may be corrected freely, while {@see CountryCodes} may not.
 *
 * **Country decides the convention, never the content.** Measured over 16,914 real addresses, 1,101
 * house numbers were written *before* the street — every one of them in Switzerland, whose
 * convention is the opposite. Roughly one address in fifteen ignores its own country's rule. So
 * this is safe for composing a line from parts a merchant entered in separate boxes, and would be
 * worthless for deciding which half of a free-text line is the number.
 */
final class StreetLine
{
    /**
     * Countries that write the number before the street. Everywhere else puts it after, which is the
     * majority and therefore the default.
     *
     * Anglosphere plus France, broadly. Partial by design: a country missing here renders in the
     * suffix form, which is the ordinary outcome rather than a failure — and unlike a mis-resolved
     * country code, a line written in the wrong order is still deliverable and still legible.
     */
    private const NUMBER_FIRST = [
        'FR' => true, 'GB' => true, 'IE' => true, 'US' => true, 'CA' => true,
        'AU' => true, 'NZ' => true, 'IN' => true, 'ZA' => true, 'MT' => true,
    ];

    /**
     * The street as one printed line.
     *
     * Either part may be absent: a party captured before the split carries the whole line in
     * `$street` and no number, and roughly one address in ten has no house number at all — PO boxes,
     * named buildings, rural delivery. Both cases return the part that exists rather than a line with
     * a stray separator in it.
     */
    public static function compose(?string $street, ?string $number, ?string $country): string
    {
        $street = trim((string) $street);
        $number = trim((string) $number);

        if ('' === $number) {
            return $street;
        }
        if ('' === $street) {
            return $number;
        }

        return self::numberComesFirst($country)
            ? $number.' '.$street
            : $street.' '.$number;
    }

    /** Whether this country writes `12 rue du Rhône` rather than `Bahnhofstrasse 12`. */
    public static function numberComesFirst(?string $country): bool
    {
        $code = strtoupper(trim((string) $country));
        // Accept the `CC:SUBDIVISION` shape a host may hand over, for the same reason the fact
        // normalizer does: it is what WooCommerce stores its own store country as.
        $code = explode(':', $code, 2)[0];

        return self::NUMBER_FIRST[$code] ?? false;
    }
}
