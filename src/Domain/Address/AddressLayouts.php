<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Address;

/**
 * How each country writes a postal address — one format string per country, plus the one used by
 * every country that has no entry.
 *
 * **Why this is data we own rather than data we borrow.** A purchase order is a document that
 * leaves the building, and a host platform's address formatter is built for a *storefront*: it is
 * filterable by any installed plugin, and it drops the country line when the address matches the
 * store's own — a convention keyed on the sender, applied to a document addressed to someone else.
 * Neither is defensible on a document a supplier receives, files and forwards. Layout is also not
 * adapter-specific: two adapters reading the same party must print the same order, which is only
 * true if the table lives here.
 *
 * **Partial by design, and that is the safe direction.** A country with no entry renders
 * {@see self::FALLBACK}, which puts the postcode before the city — most of the world, and legible
 * in the rest. A country listed with the wrong convention is a worse outcome than a country not
 * listed, so entries are added when someone is sure, never to make the table look complete. The
 * same stance as {@see \Nandan108\InvFlux\Domain\Procurement\StreetLine}, for the same reason: a
 * line in an unexpected order is still deliverable and still legible.
 *
 * **Placeholders.** `{company} {name} {first_name} {last_name} {address_1} {address_2} {city}
 * {state} {state_code} {postcode} {country}`, each with an `_upper` variant
 * (`{city_upper}`). `{state}` is the subdivision's *name* where one can be resolved and its code
 * otherwise; `{state_code}` is always the code, which is what the US, Canada and Australia actually
 * print. A line whose every placeholder is empty is dropped, so a format may name parts an address
 * does not carry.
 *
 * `{address_1}` arrives with the house number already joined to the street — that word order is
 * itself per-country and is decided by {@see \Nandan108\InvFlux\Domain\Procurement\StreetLine}
 * before layout begins. There is deliberately no `{building_number}` placeholder: two places
 * deciding where the number goes is how they come to disagree.
 */
final class AddressLayouts
{
    /**
     * Used by every country with no entry below.
     *
     * Postcode before city, which is most of the world and reads unambiguously in the countries
     * where it is not the convention. Subdivision on its own line rather than merged, because a
     * merged line has to commit to a separator and a position that vary far more than this does.
     */
    public const FALLBACK = "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{state}\n{country}";

    /**
     * Format string by ISO 3166-1 alpha-2.
     *
     * Written from postal convention rather than adapted from any host platform's table: this
     * package is dual-licensed, so it cannot take in GPL-only data from a plugin it adapts.
     */
    public const LAYOUTS = [
        // ── Postcode before city, company above the addressee. Continental Europe's shared form.
        'AT' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'BE' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'BG' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'CH' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'CZ' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'DE' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'DK' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'EE' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'FI' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'GR' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'HR' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'IS' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'LI' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'LT' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'LU' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'LV' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'NL' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'NO' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'PL' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'PT' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'RO' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'RS' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'SE' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'SI' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",
        'SK' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}",

        // La Poste asks for the destination town in capitals; Monaco follows French practice.
        'FR' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city_upper}\n{country}",
        'MC' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city_upper}\n{country}",

        // Spain names the province under the locality; Italy appends its two-letter code to it
        // (`20121 Milano MI`), which is why Italy is the one place `{state_code}` is not anglophone.
        'ES' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{state}\n{country}",
        'IT' => "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city} {state_code}\n{country}",

        // Hungarian order is the reverse of everyone else's: family name first, then the settlement,
        // then the street, with the postcode last.
        'HU' => "{company}\n{last_name} {first_name}\n{city}\n{address_1}\n{address_2}\n{postcode}\n{country}",

        // ── The addressee above the company, and the subdivision on the locality line.
        'US' => "{name}\n{company}\n{address_1}\n{address_2}\n{city}, {state_code} {postcode}\n{country}",
        'CA' => "{name}\n{company}\n{address_1}\n{address_2}\n{city} {state_code} {postcode}\n{country}",
        'AU' => "{name}\n{company}\n{address_1}\n{address_2}\n{city} {state_code} {postcode}\n{country}",
        'NZ' => "{name}\n{company}\n{address_1}\n{address_2}\n{city} {postcode}\n{country}",

        // Royal Mail's standard is premises, thoroughfare, locality, post town, postcode. The county
        // has been unnecessary since postcodes, so nothing here names one.
        'GB' => "{name}\n{company}\n{address_1}\n{address_2}\n{city}\n{postcode}\n{country}",
        // Ireland is the opposite: An Post, its postal service, puts the county on a line of the
        // address, above the Eircode.
        'IE' => "{name}\n{company}\n{address_1}\n{address_2}\n{city}\n{state}\n{postcode}\n{country}",

        // ── Elsewhere.
        'BR' => "{name}\n{company}\n{address_1}\n{address_2}\n{city} - {state_code}\n{postcode}\n{country}",
        'IN' => "{company}\n{name}\n{address_1}\n{address_2}\n{city} {postcode}\n{state}\n{country}",
        'MX' => "{name}\n{company}\n{address_1}\n{address_2}\n{postcode} {city}\n{state}\n{country}",
        'TR' => "{name}\n{company}\n{address_1}\n{address_2}\n{postcode} {city} {state}\n{country}",
        'ZA' => "{name}\n{company}\n{address_1}\n{address_2}\n{city}\n{state}\n{postcode}\n{country}",
        'SG' => "{name}\n{company}\n{address_1}\n{address_2}\n{city} {postcode}\n{country}",

        // Hong Kong issues no postcodes, so there is no postcode line to write.
        'HK' => "{company}\n{name}\n{address_1}\n{address_2}\n{city}\n{state}\n{country}",

        // China, Japan and Russia write largest-first in their own scripts and smallest-first when
        // romanized. Each gets one entry, encoding the romanized order, because that is what an
        // address box collects — a layout is chosen by country and **nothing inspects the parts**,
        // so this is an assumption about the data rather than a rule the code applies. Native-script
        // parts are laid out Western-style. It is also why the document's language is irrelevant
        // here: a French order to a Chinese supplier carries whichever script that supplier's
        // record holds, and the two are independent.
        //
        // Keying on country *and* script later is additive — selection already funnels through
        // {@see self::forCountry()}. The cost is not there: it is writing native-script layouts,
        // which is the same research problem again in scripts that are harder to check. And the
        // obvious detector is wrong — a Latin company name above a kanji street is the ordinary
        // shape of a European merchant's record, so one CJK character must not flip a whole layout.
        //
        // Japan differs from the other two only in typography: the prefecture and the postcode
        // share a line, as `Tokyo 103-0027`.
        'CN' => "{name}\n{company}\n{address_1}\n{address_2}\n{city}\n{state}\n{postcode}\n{country}",
        'JP' => "{name}\n{company}\n{address_1}\n{address_2}\n{city}\n{state} {postcode}\n{country}",
        'RU' => "{name}\n{company}\n{address_1}\n{address_2}\n{city}\n{state}\n{postcode}\n{country}",
    ];

    /**
     * The format string for a country, falling back to {@see self::FALLBACK}.
     *
     * @param array<string, string> $overrides by alpha-2, replacing the shipped entry outright —
     *                                         a merchant working one trade corridor knows its
     *                                         conventions better than any shipped table
     */
    public static function forCountry(string $code, array $overrides = []): string
    {
        return $overrides[$code] ?? self::LAYOUTS[$code] ?? self::FALLBACK;
    }
}
