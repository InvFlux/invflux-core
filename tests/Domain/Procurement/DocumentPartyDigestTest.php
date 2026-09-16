<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\DocumentParty;
use PHPUnit\Framework\TestCase;

/**
 * The digest is the table's primary key, so its *format* is a wire contract between installations
 * and not merely an implementation detail. Interning against it is only a union — the whole reason
 * this table is content-addressed — while two installs compute the same key for the same facts.
 *
 * Persistence behaviour (interning, collision re-keying, insert-or-ignore) is covered by the storage
 * package's integration tests; what is pinned here is the arithmetic those tests rest on.
 */
final class DocumentPartyDigestTest extends TestCase
{
    private const GERMAN_SUPPLIER = ['address_1' => 'Hauptstr. 1', 'city' => 'Berlin', 'postcode' => '10115', 'country' => 'DE'];

    /**
     * **A tripwire, not a characterization test.** Changing the canonical form re-keys every row
     * ever computed, on every installation, which silently turns a merge from a union into a pile of
     * duplicates. If this fails, that is what you are about to do: either revert, or accept it
     * knowingly and write the re-key migration.
     *
     * Moved once, deliberately, at {@see PartyFactNormalizer::VERSION} 1 — when the digest began
     * hashing a normalized projection instead of the values as stated.
     */
    public function testTheCanonicalFormatIsFrozen(): void
    {
        $party = DocumentParty::of('Bäcker GmbH', self::GERMAN_SUPPLIER, 'DE812345678');

        self::assertSame(2678902735521492985, $party->content_hash);
    }

    /**
     * The property that makes the fact set extensible: a fact a party does not carry contributes
     * nothing. Passing it as null, as an empty string, or not at all must all reach one key —
     * which is the same reason a *new* fact can be added later without moving the keys of the rows
     * that predate it.
     */
    public function testAnAbsentFactContributesNothing(): void
    {
        $terse = DocumentParty::of('Bäcker GmbH', self::GERMAN_SUPPLIER, 'DE812345678');
        $verbose = DocumentParty::of(
            'Bäcker GmbH',
            self::GERMAN_SUPPLIER + ['address_2' => null, 'state' => ''],
            'DE812345678',
            contactName: '',
            contactEmail: null,
        );

        self::assertSame($terse->content_hash, $verbose->content_hash);
    }

    /** Facts are hashed under their own names, so one value cannot pass for another's. */
    public function testTwoFactsSwappedYieldDifferentParties(): void
    {
        $a = DocumentParty::of('Acme', ['city' => 'Springfield', 'state' => 'IL', 'country' => 'US']);
        $b = DocumentParty::of('Acme', ['city' => 'IL', 'state' => 'Springfield', 'country' => 'US']);

        self::assertNotSame($a->content_hash, $b->content_hash);
    }

    /** Whitespace is not a fact: a hand-typed trailing space must not mint a second party. */
    public function testSurroundingWhitespaceIsNormalizedAway(): void
    {
        $clean = DocumentParty::of('Acme', ['city' => 'Berlin', 'country' => 'DE']);
        $padded = DocumentParty::of('  Acme ', ['city' => " Berlin\t", 'country' => 'DE ']);

        self::assertSame($clean->content_hash, $padded->content_hash);
    }

    /**
     * An attempt lands somewhere unrelated rather than on the next slot, so a colliding party does
     * not walk into whatever legitimately occupies it. Attempt 0 is the content's own key.
     */
    public function testRekeyingMovesTheKeyWithoutTouchingTheFacts(): void
    {
        $party = DocumentParty::of('Bäcker GmbH', self::GERMAN_SUPPLIER, 'DE812345678');
        $original = $party->content_hash;
        $facts = $party->facts();

        $party->rekey(1);
        self::assertNotSame($original, $party->content_hash);
        self::assertNotSame($original + 1, $party->content_hash, 'an attempt is hashed in, never added');
        self::assertSame($facts, $party->facts(), 'only the key moves');

        $party->rekey(0);
        self::assertSame($original, $party->content_hash, 'attempt 0 is the key the content alone implies');
    }

    /**
     * The round-trip a merge runs on: a party rebuilt from stored facts keys the same as the live
     * read that produced them, whatever slot the row it came from happened to occupy.
     */
    public function testAPartyRebuiltFromItsFactsKeysIdentically(): void
    {
        $live = DocumentParty::of('Bäcker GmbH', self::GERMAN_SUPPLIER, 'DE812345678', 'Ada Lovelace', 'ada@example.test');
        $live->rekey(3);

        $rebuilt = DocumentParty::fromFacts($live->facts());

        self::assertSame(
            DocumentParty::of('Bäcker GmbH', self::GERMAN_SUPPLIER, 'DE812345678', 'Ada Lovelace', 'ada@example.test')->content_hash,
            $rebuilt->content_hash,
            'a re-keyed source row must still intern by its content',
        );
        self::assertTrue($rebuilt->sameFactsAs($live));
    }

    /**
     * The property the whole fact set rests on, asserted directly rather than inferred from the
     * frozen-format tripwire: a fact a party does not carry contributes nothing, so `building_number`
     * arriving on the table leaves every existing party exactly where it was.
     *
     * This is what makes it safe to add a fact to a live installation. Without it, every new column
     * would re-key the whole table, and a store's parties would part company with the same store's
     * parties on another installation.
     */
    public function testAddingAFactLeavesPartiesThatLackItAlone(): void
    {
        $unstructured = DocumentParty::of('Acme', ['address_1' => 'Bahnhofstrasse 12', 'city' => 'Zürich', 'country' => 'CH']);

        self::assertSame(
            $unstructured->content_hash,
            DocumentParty::of('Acme', [
                'address_1'       => 'Bahnhofstrasse 12',
                'building_number' => null,
                'city'            => 'Zürich',
                'country'         => 'CH',
            ])->content_hash,
            'passing the new fact as null must key identically to not passing it at all',
        );
    }

    /**
     * The structured and unstructured spellings of one address are **two parties**, and that is
     * correct rather than a missed dedup: they state different facts, and the split one can answer a
     * question — what is the building number — that the other cannot. Merging them would require
     * parsing the unstructured line, which is the guess this design refuses to make.
     */
    public function testASplitAddressIsNotTheSamePartyAsAJoinedOne(): void
    {
        $joined = DocumentParty::of('Acme', ['address_1' => 'Bahnhofstrasse 12', 'country' => 'CH']);
        $split = DocumentParty::of('Acme', ['address_1' => 'Bahnhofstrasse', 'building_number' => '12', 'country' => 'CH']);

        self::assertNotSame($joined->content_hash, $split->content_hash);
        self::assertFalse($joined->sameFactsAs($split));
        self::assertSame('Bahnhofstrasse 12', $joined->streetLine(), 'both still print the same line');
        self::assertSame('Bahnhofstrasse 12', $split->streetLine());
    }

    /** A party with nothing but a name is still a party, and still has one stable key. */
    public function testAPartyWithOnlyANameStillHasAKey(): void
    {
        self::assertSame(-7561716522095603860, DocumentParty::of('Solo', [])->content_hash);
    }

    /**
     * The collision check and the digest have to read the same set of columns. They did not: the
     * customer facts were added to {@see DocumentParty::digest()} and not to
     * {@see DocumentParty::sameFactsAs()}, leaving `first_name`, `last_name` and `company` outside
     * the check — so a genuine 64-bit collision between two parties differing only in those would
     * have been declared identical and made to share a row, printing one person's name on the
     * other's document. That is the exact failure the check exists to prevent.
     *
     * @dataProvider customerFactsOutsideTheOldCheck
     */
    public function testEveryHashedFactIsAlsoComparedForCollisions(DocumentParty $a, DocumentParty $b): void
    {
        self::assertNotSame($a->content_hash, $b->content_hash, 'the digest already separated these');
        self::assertFalse($a->sameFactsAs($b), 'a hashed fact must also be compared');
    }

    /** @return iterable<string, array{DocumentParty, DocumentParty}> */
    public static function customerFactsOutsideTheOldCheck(): iterable
    {
        $ada = DocumentParty::fromFacts([
            'name' => 'Ada Lovelace', 'firstName' => 'Ada', 'lastName' => 'Lovelace', 'company' => 'Analytical Ltd',
        ]);

        yield 'first name' => [$ada, DocumentParty::fromFacts([
            'name' => 'Ada Lovelace', 'firstName' => 'Grace', 'lastName' => 'Lovelace', 'company' => 'Analytical Ltd',
        ])];
        yield 'last name' => [$ada, DocumentParty::fromFacts([
            'name' => 'Ada Lovelace', 'firstName' => 'Ada', 'lastName' => 'Hopper', 'company' => 'Analytical Ltd',
        ])];
        yield 'company' => [$ada, DocumentParty::fromFacts([
            'name' => 'Ada Lovelace', 'firstName' => 'Ada', 'lastName' => 'Lovelace', 'company' => 'Someone Else SA',
        ])];
    }

    /**
     * The invariant that keeps interning coherent: whenever two parties reach the same key by their
     * content, the collision check must agree they are the same party. If the projection folded a
     * difference the comparison still saw, interning would read the row back, call it a collision,
     * re-key the newcomer onto its own row — and the dedup would silently stop happening.
     *
     * @dataProvider spellingsOfOneParty
     */
    public function testFoldedSpellingsInternAsOneParty(DocumentParty $a, DocumentParty $b): void
    {
        self::assertSame($a->content_hash, $b->content_hash, 'one party, one key');
        self::assertTrue($a->sameFactsAs($b), 'the comparison must fold exactly as the digest does');
    }

    /** @return iterable<string, array{DocumentParty, DocumentParty}> */
    public static function spellingsOfOneParty(): iterable
    {
        $party = static fn (string $name, string $street, string $city): DocumentParty => DocumentParty::fromFacts([
            'name'    => $name,
            'address' => ['address_1' => $street, 'city' => $city, 'postcode' => '1204', 'country' => 'CH'],
        ]);
        $nfd = static fn (string $v): string => (string) \Normalizer::normalize($v, \Normalizer::FORM_D);

        $stated = $party('Iris Fournier', 'Rue du Rhône 12', 'Genève');

        yield 'case' => [$stated, $party('IRIS FOURNIER', 'RUE DU RHÔNE 12', 'GENÈVE')];
        yield 'accents dropped by a keyboard' => [$stated, $party('Iris Fournier', 'Rue du Rhone 12', 'Geneve')];
        yield 'decomposed unicode' => [$stated, $party($nfd('Iris Fournier'), $nfd('Rue du Rhône 12'), $nfd('Genève'))];
        yield 'doubled spaces' => [$stated, $party('Iris  Fournier', 'Rue  du Rhône  12', 'Genève')];
    }
}
