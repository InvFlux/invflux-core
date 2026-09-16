<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Address;

/**
 * Names for the places an address names — a country code becomes a country, a subdivision code
 * becomes a region, each written in the language the document is written in.
 *
 * **A port, because the two halves have different answers.** Country names are a solved problem
 * anywhere: CLDR carries all ~250 in every locale, and {@see IcuPlaceNames} reads them straight
 * from `intl`. Subdivision names are not — PHP's `intl` exposes no subdivision display names at
 * all — so the only good source is whatever the host platform already holds. That is the one piece
 * of address knowledge a host is genuinely better at, and the only reason this is an interface
 * rather than a static call.
 *
 * Implementations **return the code itself when they cannot do better**, never an empty string: a
 * document reading `NY` is legible and a document with a missing line is not.
 */
interface PlaceNames
{
    /**
     * The country's name in $locale, for an ISO 3166-1 alpha-2 code.
     *
     * @param string $code   already normalized — upper-case alpha-2, no subdivision suffix
     * @param string $locale a locale identifier such as `fr_FR`
     */
    public function country(string $code, string $locale): string;

    /**
     * The subdivision's name in $locale — a state, province, prefecture, canton or department.
     *
     * The country is a parameter because subdivision codes are only unique within one: `GE` is
     * Georgia in the United States and Genève in Switzerland.
     *
     * @param string $countryCode already normalized — upper-case alpha-2
     * @param string $code        as stored; may already be a name rather than a code, since a
     *                            country with no code list is captured as free text
     */
    public function subdivision(string $countryCode, string $code, string $locale): string;
}
