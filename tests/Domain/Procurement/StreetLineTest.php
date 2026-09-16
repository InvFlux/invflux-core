<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\StreetLine;
use PHPUnit\Framework\TestCase;

/**
 * Composing a street line from separately-captured parts.
 *
 * A rendering rule, not an identity one — nothing here may reach the party digest, or adding one
 * country to the table below would re-key parties and orphan their pointers.
 */
final class StreetLineTest extends TestCase
{
    /** @dataProvider countriesAndTheirOrder */
    public function testTheNumberGoesWhereTheCountryPutsIt(string $country, string $expected): void
    {
        self::assertSame($expected, StreetLine::compose('Bahnhofstrasse', '12', $country));
    }

    /** @return iterable<string, array{string, string}> */
    public static function countriesAndTheirOrder(): iterable
    {
        yield 'Switzerland suffixes' => ['CH', 'Bahnhofstrasse 12'];
        yield 'Germany suffixes' => ['DE', 'Bahnhofstrasse 12'];
        yield 'France prefixes' => ['FR', '12 Bahnhofstrasse'];
        yield 'the UK prefixes' => ['GB', '12 Bahnhofstrasse'];
        yield 'the US prefixes' => ['US', '12 Bahnhofstrasse'];
        yield 'an unlisted country takes the majority form' => ['JP', 'Bahnhofstrasse 12'];
        yield 'an absent country takes the majority form' => ['', 'Bahnhofstrasse 12'];
    }

    /**
     * WooCommerce stores its own store country as `CC:SUBDIVISION`, and that value reaches here
     * through the party facts. Splitting it is the same accommodation the fact normalizer makes.
     */
    public function testTheWooCommerceStoreCountryShapeIsUnderstood(): void
    {
        self::assertSame('12 rue du Rhône', StreetLine::compose('rue du Rhône', '12', 'FR:75'));
        self::assertSame('rue du Rhône 12', StreetLine::compose('rue du Rhône', '12', 'CH:GE'));
    }

    /**
     * Either part may be missing, and neither case may leave a stray separator behind. A party
     * captured before the split carries its whole line in the street; roughly one address in ten has
     * no house number at all.
     *
     * @dataProvider incompleteParts
     */
    public function testAMissingPartYieldsTheOtherAlone(?string $street, ?string $number, string $expected): void
    {
        self::assertSame($expected, StreetLine::compose($street, $number, 'CH'));
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function incompleteParts(): iterable
    {
        yield 'no number (unstructured party)' => ['Bahnhofstrasse 12', null, 'Bahnhofstrasse 12'];
        yield 'no number at all (PO box, named building)' => ['Chalet Bellevue', '', 'Chalet Bellevue'];
        yield 'number only' => [null, '12', '12'];
        yield 'neither' => [null, null, ''];
        yield 'whitespace is not a part' => ['  ', ' ', ''];
    }

    /** Surrounding whitespace never survives into a printed line. */
    public function testPartsAreTrimmedBeforeJoining(): void
    {
        self::assertSame('Bahnhofstrasse 12', StreetLine::compose('  Bahnhofstrasse ', ' 12 ', 'CH'));
    }
}
