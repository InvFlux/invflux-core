<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Tests\Domain;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\InvFlux\Domain\Procurement\Terms;
use PHPUnit\Framework\TestCase;

/**
 * The shape of the terms digest expression — asserted here because getting it wrong is invisible.
 *
 * MySQL normalises a JSON object's keys into length-then-lexical order; MariaDB keeps the order
 * they were written in. Writing them already sorted is what makes the two engines produce the same
 * bytes, and therefore the same hash. A field added in the wrong position would diverge **per
 * engine**: every install on one would compute different hashes from every install on the other,
 * and the local suite — running against one engine — could not see it.
 *
 * So these read the expression itself rather than its output. They need no database, which is the
 * point: the failure they guard is one a single-engine test is structurally unable to catch.
 */
final class TermsDigestTest extends TestCase
{
    public function testDigestFieldsAreInLengthThenLexicalOrder(): void
    {
        $sorted = Terms::DIGEST_FIELDS;
        usort($sorted, static fn (string $a, string $b): int => [strlen($a), $a] <=> [strlen($b), $b]);

        self::assertSame(
            $sorted,
            Terms::DIGEST_FIELDS,
            'digest fields must be written in length-then-lexical order, or MariaDB and MySQL hash the same terms differently',
        );
    }

    /** The expression names exactly those fields, in exactly that order. */
    public function testTheExpressionWritesTheFieldsInThatOrder(): void
    {
        preg_match_all("/'([a-z_]+)',\s*`/", self::expression(), $matches);

        self::assertSame(
            Terms::DIGEST_FIELDS,
            $matches[1],
            'the JSON_OBJECT keys and DIGEST_FIELDS have drifted apart',
        );
    }

    /**
     * Every field is dropped when null rather than serialised as one. That is what lets the table
     * gain a column later without recomputing the digest of rows that do not carry it — and with it
     * every foreign key those digests hold up.
     */
    public function testNullableFieldsAreRemovedRatherThanSerialised(): void
    {
        $expression = self::expression();

        self::assertStringContainsString('JSON_REMOVE', $expression);
        foreach (Terms::DIGEST_FIELDS as $field) {
            if ('body' === $field) {
                continue; // Never null: a row with no text is not an artefact.
            }
            self::assertStringContainsString(
                sprintf('IF(`%s` IS NULL', $field),
                $expression,
                sprintf('`%s` must drop out of the digest when absent, not hash as a null', $field),
            );
        }
    }

    /** The digest is stored, not recomputed per read — a virtual column cannot carry a unique key. */
    public function testTheDigestIsStored(): void
    {
        self::assertSame('STORED', self::column()->generatedMode?->value);
    }

    /**
     * A **signed** 64-bit key, read as two's complement. Unsigned, half of all digests would exceed
     * `PHP_INT_MAX` and reach PHP as strings or floats — equal hashes that no longer compare equal.
     */
    public function testTheKeyIsASignedSixtyFourBitReadingOfTheDigest(): void
    {
        self::assertSame('bigint', self::column()->type->value);
        self::assertStringContainsString(', 16, -10)', self::expression(), 'CONV with a negative base is the signed reading');
    }

    private static function expression(): string
    {
        $expression = self::column()->generatedAs;
        self::assertIsString($expression);

        return $expression;
    }

    private static function column(): Column
    {
        $attributes = (new \ReflectionProperty(Terms::class, 'content_hash'))->getAttributes(Column::class);
        self::assertNotSame([], $attributes, 'content_hash lost its #[Column]');

        return $attributes[0]->newInstance();
    }
}
