<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Address;

use Nandan108\InvFlux\Domain\Procurement\StreetLine;

/**
 * Lays a postal address out the way its own country writes one — the single place an InvFlux
 * document turns address *parts* into printed lines.
 *
 * **Returns plain text, never markup.** Each medium escapes for itself: an HTML-escaped string
 * printed into a spreadsheet cell shows the entities, and a document assembler that receives text
 * can hand it to either. Nothing here escapes, so nothing downstream has to un-escape.
 *
 * **The country line is always printed.** A storefront omits it when it matches the store's own
 * country, on the reasoning that a customer knows which country they are in. That reasoning does
 * not transfer: the property it tests belongs to the *sender*, while the address belongs to the
 * recipient, and a purchase order is filed, forwarded and read by people the sender never meets.
 * There is no store here to compare against, which is the structural form of that decision.
 */
final class PostalAddressFormatter
{
    /**
     * @param array<string, string> $overrides format string by alpha-2, replacing a shipped entry.
     *                                         Empty here means the shipped table stands; the
     *                                         parameter is what lets a merchant, an add-on or a
     *                                         second host adapter correct one corridor without
     *                                         forking the table
     */
    public function __construct(
        private readonly PlaceNames $names = new IcuPlaceNames(),
        private readonly array $overrides = [],
    ) {
    }

    /**
     * One address, as printed lines joined by $separator.
     *
     * @param array{
     *     company?: ?string, name?: ?string, first_name?: ?string, last_name?: ?string,
     *     address_1?: ?string, building_number?: ?string, address_2?: ?string,
     *     city?: ?string, state?: ?string, postcode?: ?string, country?: ?string
     * } $parts state and country are codes as stored; the display names are resolved here
     * @param string $locale the document's language, not the site's — the country name is written
     *                       in the language the rest of the document is written in
     */
    public function format(array $parts, string $locale, string $separator = "\n"): string
    {
        /** @psalm-var array<string, string> $parts */
        $parts = array_map(static fn (?string $v): string => trim((string) $v), $parts);

        $country = self::countryCode($parts['country'] ?? '');
        $stateCode = $parts['state'] ?? '';

        // A separately-captured house number rejoins the street here, in the destination country's
        // word order, and then stops existing. This must happen before layout and must happen only
        // here: the format strings carry no `{building_number}`, so a caller that skipped this step
        // would print a street with no number on it — an address that looks complete and is not.
        $street = StreetLine::compose(
            $parts['address_1'] ?? '',
            $parts['building_number'] ?? '',
            $country,
        );

        $values = [
            'company'    => $parts['company'] ?? '',
            'first_name' => $parts['first_name'] ?? '',
            'last_name'  => $parts['last_name'] ?? '',
            'name'       => self::fullName($parts),
            'address_1'  => $street,
            'address_2'  => $parts['address_2'] ?? '',
            'city'       => $parts['city'] ?? '',
            'state'      => '' === $stateCode ? '' : $this->names->subdivision($country, $stateCode, $locale),
            'state_code' => $stateCode,
            'postcode'   => $parts['postcode'] ?? '',
            'country'    => '' === $country ? '' : $this->names->country($country, $locale),
        ];

        $replacements = [];
        foreach ($values as $key => $value) {
            $replacements['{'.$key.'}'] = $value;
            $replacements['{'.$key.'_upper}'] = mb_strtoupper($value, 'UTF-8');
        }

        $laid = strtr(AddressLayouts::forCountry($country, $this->overrides), $replacements);

        return implode($separator, self::tidy($laid));
    }

    /**
     * The printed lines, with everything the substitution left behind removed.
     *
     * A format may name parts an address does not carry, so the leftovers are the point rather than
     * an edge case: an absent subdivision leaves `Geneva,` and an absent postcode leaves a run of
     * spaces. Dropping empty lines is what lets one format string serve a company with no addressee
     * and an addressee with no company.
     *
     * @return list<string>
     */
    private static function tidy(string $laid): array
    {
        $lines = [];
        foreach (explode("\n", $laid) as $line) {
            $line = trim((string) preg_replace('/ {2,}/', ' ', $line), " \t,-");
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The addressee as one string.
     *
     * A supplier states one name and a customer states two, so both shapes reach this; a party that
     * carries `name` outright wins, because splitting it back into halves to re-join them would
     * only invent a word order the source never claimed.
     *
     * @param array<string, string> $parts
     */
    private static function fullName(array $parts): string
    {
        if ('' !== ($parts['name'] ?? '')) {
            return $parts['name'];
        }

        return trim(($parts['first_name'] ?? '').' '.($parts['last_name'] ?? ''));
    }

    /**
     * ISO 3166-1 alpha-2, from what a party stored.
     *
     * Accepts the `CC:SUBDIVISION` shape a host may hand over, for the same reason
     * {@see StreetLine::numberComesFirst()} does: it is how WooCommerce stores its own store
     * country, so it arrives on the buyer's own address block.
     */
    private static function countryCode(string $stored): string
    {
        return explode(':', mb_strtoupper(trim($stored), 'UTF-8'), 2)[0];
    }
}
