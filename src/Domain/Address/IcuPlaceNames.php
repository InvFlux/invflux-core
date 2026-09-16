<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Address;

/**
 * Country names from the pinned table first and the host's ICU second, subdivision names left as
 * their code.
 *
 * **The shipped table wins for the languages a document can be written in**, and that ordering is
 * the whole design. `extension_loaded('intl')` is true on hosts whose ICU carries English data and
 * nothing else, where every locale answers in English and no call fails — so a runtime-first
 * resolver would render a French purchase order naming a Swiss supplier's country `Switzerland`,
 * on a large class of ordinary installs, with nothing to notice. {@see CountryNames} explains what
 * measurement that rests on.
 *
 * ICU is still asked for anything the table has no answer for: a language we ship no wording in,
 * on a host whose data is complete. And a site with neither renders the raw alpha-2 code — a
 * purchase order reading `CH` is legible.
 */
final class IcuPlaceNames implements PlaceNames
{
    #[\Override]
    public function country(string $code, string $locale): string
    {
        if ('' === $code) {
            return $code;
        }

        $pinned = CountryNames::inLocale($code, $locale);
        if (null !== $pinned) {
            return $pinned;
        }

        if (!class_exists(\Locale::class)) {
            return $code;
        }

        // The leading hyphen is what makes ICU read the tag as a *region* subtag rather than a
        // language: `Locale::getDisplayRegion('CH')` would parse `ch` as a language and answer
        // nothing useful. An unknown region answers with the code itself, which is the fallback
        // this method wants anyway.
        /** @psalm-var mixed $name */
        $name = \Locale::getDisplayRegion('-'.$code, $locale);

        return \is_string($name) && '' !== $name ? $name : $code;
    }

    /**
     * The code, unchanged.
     *
     * CLDR does carry subdivision names, but PHP's `intl` extension exposes no API for them at any
     * version — there is no `getDisplaySubdivision`. So this is not a deferred improvement waiting
     * on a newer ICU; it is a capability the extension does not have, and a host that holds its own
     * region list should implement {@see PlaceNames} rather than wait for this one to grow.
     */
    #[\Override]
    public function subdivision(string $countryCode, string $code, string $locale): string
    {
        return $code;
    }
}
