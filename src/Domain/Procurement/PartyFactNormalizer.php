<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * The form a party's facts are *compared* in — never the form they are stored in.
 *
 * A {@see DocumentParty} states what a document said, so its columns keep the merchant's own
 * spelling. But two documents stating the same party in different hands must reach the same row, and
 * left raw they do not: `Rue du Rhône` / `RUE DU RHONE`, `+41 79 123 45 67` / `0791234567`, and
 * `CH` / `CH:GE` / `Suisse` are each one party under three keys. This projection is what the digest
 * and the collision check see, so the row is shared while both spellings stay printable.
 *
 * **Why folding case and accents is safe here, when it would not be in a display value.** The
 * objection is that the row keeps one spelling and a later document then prints a spelling it did
 * not state. That objection assumes one of them is right. Neither is: an address written without
 * its accents is not a variant reading, it is a keyboard. Since the facts are stored verbatim and
 * only the *comparison* folds, the merchant's own copy survives either way.
 *
 * **What the fold may not do is merge two different parties.** It cannot, in practice, because it
 * fires only when every other fact matches too — same street, same town, same postcode, same
 * contact. Two people are not separated by a circumflex alone. The asymmetry that governs every
 * judgement call below follows from that: **under-merging costs one duplicate row, over-merging
 * loses the fact that the two were ever stated differently, and that is unrecoverable.** Where a
 * rule is uncertain, it takes the cheap side and leaves the value alone.
 *
 * That is also why the accent strip is deliberately incomplete. `Ł`, `Ø`, `Đ` and `Æ` carry no
 * combining mark and survive it, so `Łódź` and `Lodz` stay two parties. Closing that gap means a
 * transliterator, and every transliterator worth using is ICU's — whose output moves with the host's
 * library version. A key that depends on which machine computed it breaks the union-merge
 * {@see DocumentPartyRepository} rests on, which is a real cost paid for the cheap side of the
 * asymmetry.
 *
 * @internal
 */
final class PartyFactNormalizer
{
    /**
     * Which projection produced a stored key.
     *
     * The projection is part of the identity function, so changing *any* rule below re-keys every
     * party whose values are not already canonical — `content_hash` is the primary key, and every FK
     * to it has to be repointed in the same change. Bumping this constant is the declaration that
     * such a migration is intended; it exists so the change cannot be made by accident, as an
     * ordinary refactor, and discovered later as a silently forked table.
     */
    public const VERSION = 1;

    /**
     * Facts whose value is an identifier rather than prose: comparable only after every separator
     * is gone, since `CHE-123.456.789` and `CHE123456789` are one registration and `SW1A 1AA` and
     * `SW1A1AA` are one postcode.
     */
    private const IDENTIFIER_FACTS = ['tax_number', 'postcode'];

    /**
     * International dialling codes, used for exactly one thing: recognising a party's *own* country
     * code at the front of a number written in international form, so `+41 79 …` and `079 …` reduce
     * alike.
     *
     * **Deliberately partial, and safe when it is wrong.** A country absent here simply keeps its
     * digits, which under-merges. A code that is wrong for its country cannot merge two different
     * numbers, because it is only ever tested against numbers on a party already in that country.
     * Full E.164 would need per-country trunk-prefix rules — Italy keeps its leading zero, Spain has
     * no trunk digit at all — and getting those wrong is how a phone normaliser starts mangling
     * numbers instead of comparing them.
     */
    private const DIALLING_CODES = [
        'CH' => '41',  'LI' => '423', 'AT' => '43',  'DE' => '49',  'FR' => '33',
        'IT' => '39',  'BE' => '32',  'NL' => '31',  'LU' => '352', 'ES' => '34',
        'PT' => '351', 'GB' => '44',  'IE' => '353', 'DK' => '45',  'SE' => '46',
        'NO' => '47',  'FI' => '358', 'IS' => '354', 'PL' => '48',  'CZ' => '420',
        'SK' => '421', 'HU' => '36',  'SI' => '386', 'HR' => '385', 'RO' => '40',
        'BG' => '359', 'GR' => '30',  'EE' => '372', 'LV' => '371', 'LT' => '370',
        'CY' => '357', 'MT' => '356', 'MC' => '377', 'AD' => '376', 'SM' => '378',
        'US' => '1',   'CA' => '1',   'AU' => '61',  'NZ' => '64',  'JP' => '81',
        'CN' => '86',  'IN' => '91',  'BR' => '55',  'MX' => '52',  'ZA' => '27',
        'IL' => '972', 'AE' => '971', 'TR' => '90',  'RU' => '7',   'UA' => '380',
        'RS' => '381', 'MA' => '212', 'TN' => '216', 'DZ' => '213',
    ];

    /**
     * The comparable form of a whole fact set.
     *
     * Takes the set rather than one field at a time because two rules are not independent: the phone
     * needs the country, and it needs the *resolved* one — a phone cannot be reduced against
     * `Suisse`. Ordering that correctly is this method's job and not a caller's to remember.
     *
     * Facts that project to nothing are dropped, exactly as the raw ones are, so a value that was
     * only punctuation or a non-breaking space becomes *absent* rather than a fact whose content is
     * a space.
     *
     * @param array<string, ?string> $facts field name => the value as stated
     *
     * @return array<string, string> field name => comparable value, empties removed
     */
    public static function project(array $facts): array
    {
        $country = self::country($facts['country'] ?? null);

        $projected = [];
        foreach ($facts as $field => $value) {
            if (null === $value) {
                continue;
            }

            $projected[$field] = match (true) {
                'country' === $field                            => $country,
                'contact_phone' === $field                      => self::phone($value, $country),
                \in_array($field, self::IDENTIFIER_FACTS, true) => self::squash($value),
                default                                         => self::fold($value),
            };
        }

        return array_filter($projected, static fn (string $v): bool => '' !== $v);
    }

    /**
     * Prose, made comparable: composed, single-spaced, caseless and unaccented.
     *
     * `MB_CASE_FOLD` rather than an upper- or lower-casing, because caseless matching is the
     * operation Unicode defines for exactly this and it handles the cases a naive fold does not —
     * `Straße` and `STRASSE` reach the same string.
     */
    private static function fold(string $value): string
    {
        $value = \Normalizer::normalize($value, \Normalizer::FORM_C);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        $value = mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');

        // Decompose, then drop the accents that decomposing exposed. Composed and decomposed
        // spellings look identical on screen and differ byte for byte, so this closes an invisible
        // way to fork a key as much as it folds accents away.
        return (string) preg_replace(
            '/\p{Mn}+/u',
            '',
            (string) \Normalizer::normalize($value, \Normalizer::FORM_D),
        );
    }

    /** {@see fold()}, with every separator removed — for values that are identifiers, not prose. */
    private static function squash(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/u', '', self::fold($value));
    }

    /**
     * The alpha-2 code, from whatever the source called the country.
     *
     * The `CC:SUBDIVISION` split happens here rather than in {@see CountryCodes} because it has to
     * happen *before* folding removes the colon — and because that shape is WooCommerce's
     * `woocommerce_default_country`, which is a host detail this class is already the funnel for.
     */
    private static function country(?string $value): string
    {
        if (null === $value) {
            return '';
        }
        $bare = explode(':', $value, 2)[0];

        return CountryCodes::toAlpha2(self::squash($bare));
    }

    /**
     * A phone number reduced to its national significant digits, so the international and national
     * spellings of one number reach the same string.
     *
     * The reduction avoids needing per-country trunk rules by treating both forms symmetrically:
     * an international number loses its country code, then either form loses a single leading trunk
     * zero. `+41 79 123 45 67` and `079 123 45 67` both become `791234567`; Italy's `+39 06 …` and
     * `06 …` both become `6…`, despite Italy keeping the zero internationally, because the zero is
     * stripped after the country code either way.
     *
     * A number in international form for some *other* country keeps its `+` and full digits: it is
     * not this party's local number and reducing it against this party's country would be a guess.
     *
     * **A phone's country frequently is not its address's** — 0.64% of the numbers in the reference
     * data, mostly cross-border residents. The mismatch cannot cause a wrong merge, because a code is
     * only ever stripped when it is the party's own; it costs a duplicate row when one person writes
     * a foreign number internationally on one document and in its own national form on another.
     * Telling that a bare `06…` on a Swiss address is French would take a per-country numbering plan,
     * which is the dependency this whole method exists to avoid.
     */
    private static function phone(string $value, string $country): string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);
        if ('' === $digits) {
            return '';
        }

        $isInternational = str_starts_with(trim($value), '+') || str_starts_with($digits, '00');
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($isInternational) {
            $code = self::DIALLING_CODES[$country] ?? '';
            if ('' === $code || !str_starts_with($digits, $code)) {
                // Someone else's country, or one this table does not carry. Keep it whole and
                // distinguishable rather than reducing it against the wrong dialling code.
                return '+'.$digits;
            }
            $digits = substr($digits, \strlen($code));
        }

        return ltrim($digits, '0');
    }
}
