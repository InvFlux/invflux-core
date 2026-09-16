<?php

declare(strict_types=1);

namespace Tests\Util;

use Nandan108\InvFlux\Util\Ulid;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function testGeneratesTwentySixCharCrockfordAlphabetStrings(): void
    {
        $ulid = Ulid::generate();

        $this->assertSame(26, strlen($ulid));
        $this->assertMatchesRegularExpression('/^[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $ulid);
    }

    public function testGeneratedValuesAreUnique(): void
    {
        $samples = [];
        for ($i = 0; $i < 1000; ++$i) {
            $samples[] = Ulid::generate();
        }

        $this->assertCount(1000, array_unique($samples));
    }

    public function testTimestampPrefixSortsChronologically(): void
    {
        // ULIDs generated in temporal order should sort in the same order as strings.
        $first = Ulid::generate();
        usleep(2000); // 2ms — guaranteed to bump the timestamp prefix
        $second = Ulid::generate();

        $this->assertLessThan(0, strcmp($first, $second));
    }

    public function testIsValidAcceptsWellFormedUlids(): void
    {
        $this->assertTrue(Ulid::isValid(Ulid::generate()));
    }

    public function testIsValidRejectsWrongLength(): void
    {
        $this->assertFalse(Ulid::isValid(str_repeat('A', 25)));
        $this->assertFalse(Ulid::isValid(str_repeat('A', 27)));
    }

    public function testIsValidRejectsExcludedLetters(): void
    {
        // Crockford excludes I, L, O, U. A string with any of these is invalid.
        $base = str_repeat('A', 25);
        $this->assertFalse(Ulid::isValid($base.'I'));
        $this->assertFalse(Ulid::isValid($base.'L'));
        $this->assertFalse(Ulid::isValid($base.'O'));
        $this->assertFalse(Ulid::isValid($base.'U'));
    }

    public function testIsValidIsCaseInsensitive(): void
    {
        $ulid = Ulid::generate();
        $this->assertTrue(Ulid::isValid(strtolower($ulid)));
    }
}
