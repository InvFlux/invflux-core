<?php

declare(strict_types=1);

namespace Tests\Application\Procurement;

use Nandan108\InvFlux\Application\Procurement\CreateTermsLineage;
use Nandan108\InvFlux\Application\Procurement\RemoveTermsLineage;
use Nandan108\InvFlux\Application\Procurement\RenameTermsLineage;
use Nandan108\InvFlux\Application\Procurement\SaveTermsContent;
use Nandan108\InvFlux\Domain\Procurement\Terms;
use Nandan108\InvFlux\Domain\Procurement\TermsLineage;
use Nandan108\InvFlux\Domain\Procurement\TermsRepository;
use Nandan108\InvFlux\Domain\Procurement\TermsVersion;
use Nandan108\InvFlux\Exceptions\TermsException;
use PHPUnit\Framework\TestCase;

/**
 * Creating, saving, renaming and removing sets of purchase terms. The rules all turn on one question
 * — was any order issued under this text? — so each test sets that and checks what follows from it.
 */
final class TermsLineageUseCasesTest extends TestCase
{
    private ?InMemoryTermsRepository $memory = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->memory = new InMemoryTermsRepository();
    }

    public function testANewSetStartsAtVersionOneWithItsTextNormalised(): void
    {
        $lineage = (new CreateTermsLineage($this->repo()))('  Standard terms ', "1. Net 30.   \r\n");

        self::assertSame('Standard terms', $lineage->name);
        $current = $this->current($lineage);
        self::assertSame(1, $current->ordinal);
        self::assertSame('1. Net 30.', $this->repo()->texts[$current->content_hash], 'stored already canonical');
    }

    public function testASetNeedsTextAndAName(): void
    {
        $this->assertRefused(TermsException::EMPTY_BODY, fn () => (new CreateTermsLineage($this->repo()))('Standard', " \n\n "));
        $this->assertRefused(TermsException::EMPTY_NAME, fn () => (new CreateTermsLineage($this->repo()))('   ', 'Net 30.'));
        $this->assertRefused(TermsException::NAME_TOO_LONG, fn () => (new CreateTermsLineage($this->repo()))(str_repeat('x', 191), 'Net 30.'));
    }

    /**
     * An unedited copy would share its source's text and so be born in use. Differing only in
     * whitespace the normaliser removes is still unedited — the case a naive string comparison misses.
     */
    public function testACopyMustActuallyDiffer(): void
    {
        $source = (new CreateTermsLineage($this->repo()))('Standard', '1. Net 30.');

        $this->assertRefused(TermsException::COPY_UNCHANGED, fn () => (new CreateTermsLineage($this->repo()))('Copy of Standard', "1. Net 30.  \n", copyOf: (int) $source->id));

        $copy = (new CreateTermsLineage($this->repo()))('Copy of Standard', '1. Net 60.', copyOf: (int) $source->id);
        self::assertNotSame($this->current($source)->content_hash, $this->current($copy)->content_hash);
    }

    /** Nothing was issued under the text, so a save fixes it in place: same edition, new text. */
    public function testSavingUnusedTermsReplacesTheTextWithoutAVersion(): void
    {
        $lineage = (new CreateTermsLineage($this->repo()))('Standard', '1. Net 30.');

        $saved = (new SaveTermsContent($this->repo()))((int) $lineage->id, '1. Net 60.', editedFromOrdinal: 1);

        self::assertSame(SaveTermsContent::REPLACED, $saved['outcome']);
        self::assertSame(1, $saved['version']->ordinal);
        self::assertCount(1, $this->repo()->termsVersionsOf((int) $lineage->id));
        self::assertSame('1. Net 60.', $this->repo()->texts[$this->current($lineage)->content_hash]);
    }

    /** Orders went out under the text, so it is never rewritten: version 2 is minted, 1 retired. */
    public function testSavingUsedTermsMintsTheNextVersion(): void
    {
        $lineage = (new CreateTermsLineage($this->repo()))('Standard', '1. Net 30.');
        $first = $this->current($lineage);
        $this->repo()->orders[$first->content_hash] = 3;

        $saved = (new SaveTermsContent($this->repo()))((int) $lineage->id, '1. Net 60.', editedFromOrdinal: 1);

        self::assertSame(SaveTermsContent::VERSIONED, $saved['outcome']);
        self::assertSame(2, $this->current($lineage)->ordinal);
        self::assertNull($first->is_current, 'retired');
        self::assertNotNull($first->retired_at);
        self::assertSame('1. Net 30.', $this->repo()->texts[$first->content_hash], 'the issued text is untouched');
    }

    /** Re-saving what is there, down to whitespace, must not mint a version. */
    public function testSavingTheSameTextChangesNothing(): void
    {
        $lineage = (new CreateTermsLineage($this->repo()))('Standard', '1. Net 30.');
        $this->repo()->orders[$this->current($lineage)->content_hash] = 1;

        $saved = (new SaveTermsContent($this->repo()))((int) $lineage->id, "1. Net 30.   \n\n", editedFromOrdinal: 1);

        self::assertSame(SaveTermsContent::UNCHANGED, $saved['outcome']);
        self::assertCount(1, $this->repo()->termsVersionsOf((int) $lineage->id));
    }

    /** An edit opened on version 1 cannot save over a version 2 somebody else made meanwhile. */
    public function testASaveFromAnOlderEditionIsRefused(): void
    {
        $lineage = (new CreateTermsLineage($this->repo()))('Standard', '1. Net 30.');
        $this->repo()->orders[$this->current($lineage)->content_hash] = 1;
        (new SaveTermsContent($this->repo()))((int) $lineage->id, '1. Net 45.', editedFromOrdinal: 1);

        $this->assertRefused(TermsException::STALE_EDIT, fn () => (new SaveTermsContent($this->repo()))((int) $lineage->id, '1. Net 60.', editedFromOrdinal: 1));
    }

    public function testARemovedSetCannotBeEdited(): void
    {
        $lineage = (new CreateTermsLineage($this->repo()))('Standard', '1. Net 30.');
        $this->repo()->orders[$this->current($lineage)->content_hash] = 1;
        (new RemoveTermsLineage($this->repo()))((int) $lineage->id, archive: true);

        $this->assertRefused(TermsException::REMOVED, fn () => (new SaveTermsContent($this->repo()))((int) $lineage->id, '1. Net 60.', editedFromOrdinal: 1));
    }

    /** The name is internal and never printed, so it changes freely even on a set in use. */
    public function testASetInUseCanStillBeRenamed(): void
    {
        $lineage = (new CreateTermsLineage($this->repo()))('Standard', '1. Net 30.');
        $this->repo()->orders[$this->current($lineage)->content_hash] = 5;

        $renamed = (new RenameTermsLineage($this->repo()))((int) $lineage->id, ' House terms ');

        self::assertSame('House terms', $renamed->name);
    }

    /**
     * A name belongs to one set — whatever its case, archived sets included — so two sets never read
     * the same in a picker, and an archived set keeps answering by its name. A set keeping its own
     * name, or changing only its case, is no clash.
     */
    public function testANameBelongsToOneSet(): void
    {
        $standard = (new CreateTermsLineage($this->repo()))('Standard', '1. Net 30.');
        $this->assertRefused(TermsException::NAME_TAKEN, fn () => (new CreateTermsLineage($this->repo()))(' standard ', '1. Net 60.'));

        $framework = (new CreateTermsLineage($this->repo()))('Framework', '1. Net 45.');
        $this->assertRefused(TermsException::NAME_TAKEN, fn () => (new RenameTermsLineage($this->repo()))((int) $framework->id, 'STANDARD'));
        self::assertSame('STANDARD', (new RenameTermsLineage($this->repo()))((int) $standard->id, 'STANDARD')->name);

        $standard->removed_at = new \DateTimeImmutable();
        self::assertNotNull($this->repo()->saveTermsLineage($standard)->removed_at, 'archived');
        $this->assertRefused(TermsException::NAME_TAKEN, fn () => (new CreateTermsLineage($this->repo()))('Standard', '1. Net 90.'));
    }

    public function testASetNothingWasIssuedUnderIsDeleted(): void
    {
        $lineage = (new CreateTermsLineage($this->repo()))('Standard', '1. Net 30.');

        self::assertSame(
            ['outcome' => RemoveTermsLineage::DELETED, 'releasedDrafts' => []],
            (new RemoveTermsLineage($this->repo()))((int) $lineage->id),
        );
        self::assertNull($this->repo()->findTermsLineage((int) $lineage->id));
        self::assertSame([], $this->repo()->termsVersionsOf((int) $lineage->id));
    }

    /**
     * Only the first edition went out, and the current one never did — the set is still the bridge
     * from those orders back to a name, so deleting it is refused, counting the orders, and archiving
     * is how it is retired. Checking only the current edition would delete it.
     */
    public function testASetWhoseOlderEditionWasUsedIsArchivedNotDeleted(): void
    {
        $lineage = (new CreateTermsLineage($this->repo()))('Standard', '1. Net 30.');
        $this->repo()->orders[$this->current($lineage)->content_hash] = 2;
        (new SaveTermsContent($this->repo()))((int) $lineage->id, '1. Net 60.', editedFromOrdinal: 1);
        unset($this->repo()->orders[$this->current($lineage)->content_hash]);

        try {
            (new RemoveTermsLineage($this->repo()))((int) $lineage->id);
            self::fail('a set orders went out under was deleted');
        } catch (TermsException $e) {
            self::assertSame(TermsException::ISSUED, $e->reason());
            self::assertSame(2, $e->ordersIssued());
        }

        self::assertSame(RemoveTermsLineage::ARCHIVED, (new RemoveTermsLineage($this->repo()))((int) $lineage->id, archive: true)['outcome']);
        $archived = $this->repo()->findTermsLineage((int) $lineage->id);
        self::assertNotNull($archived);
        self::assertTrue($archived->isRemoved());
        self::assertCount(2, $this->repo()->termsVersionsOf((int) $lineage->id), 'every edition stays readable');
    }

    /**
     * A supplier's choice and the store's are standing ones every future order inherits, so a set
     * still chosen there is neither deleted nor archived. The refusal names the suppliers.
     */
    public function testASetStillChosenByASupplierOrTheStoreIsNotRemoved(): void
    {
        $lineage = (new CreateTermsLineage($this->repo()))('Standard', '1. Net 30.');
        $this->repo()->selections = ['suppliers' => [4, 9], 'draftOrders' => [120]];

        try {
            (new RemoveTermsLineage($this->repo()))((int) $lineage->id);
            self::fail('a set still chosen was removed');
        } catch (TermsException $e) {
            self::assertSame(TermsException::STILL_SELECTED, $e->reason());
            self::assertSame(['suppliers' => [4, 9], 'store' => false], $e->selections());
        }
        self::assertNotNull($this->repo()->findTermsLineage((int) $lineage->id), 'and it is still there');
        self::assertSame([120], $this->repo()->selections['draftOrders'], 'nor was any draft released');

        $this->repo()->selections = ['suppliers' => [], 'draftOrders' => []];
        $this->assertRefused(TermsException::STILL_SELECTED, fn () => (new RemoveTermsLineage($this->repo()))((int) $lineage->id, archive: true, storeDefault: true));
    }

    /** A draft is still being written, so it is no obstacle: it is released and inherits from then on. */
    public function testTheDraftsFollowingASetAreReleasedWithIt(): void
    {
        $lineage = (new CreateTermsLineage($this->repo()))('Standard', '1. Net 30.');
        $this->repo()->selections = ['suppliers' => [], 'draftOrders' => [120, 121]];

        self::assertSame(
            ['outcome' => RemoveTermsLineage::DELETED, 'releasedDrafts' => [120, 121]],
            (new RemoveTermsLineage($this->repo()))((int) $lineage->id),
        );
        self::assertSame([], $this->repo()->selections['draftOrders'], 'and they follow it no more');
    }

    private function repo(): InMemoryTermsRepository
    {
        self::assertNotNull($this->memory);

        return $this->memory;
    }

    private function current(TermsLineage $lineage): TermsVersion
    {
        $current = $this->repo()->currentTermsVersions([(int) $lineage->id])[(int) $lineage->id] ?? null;
        self::assertNotNull($current, 'every set has a current edition');

        return $current;
    }

    private function assertRefused(string $reason, \Closure $action): void
    {
        try {
            $action();
        } catch (TermsException $e) {
            self::assertSame($reason, $e->reason());

            return;
        }
        self::fail(sprintf('expected a refusal because %s', $reason));
    }
}

/**
 * The repository in memory, holding the invariants the schema would: at most one current edition per
 * set, and a delete that takes a set's editions with it. The hash is a stand-in digest.
 */
final class InMemoryTermsRepository implements TermsRepository
{
    /** @var array<int, string> hash => text */
    public array $texts = [];
    /** @var array<int, int> hash => orders issued under it */
    public array $orders = [];
    /** @var array<int, TermsLineage> */
    private array $lineages = [];
    /** @var list<TermsVersion> */
    private array $versions = [];
    private int $nextId = 1;

    #[\Override]
    public function internTerms(string $body, ?string $publicRef = null): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', substr(hash('sha256', $body."\0".($publicRef ?? ''), true), 0, 8));
        $this->texts[$unpacked[1]] = $body;

        return $unpacked[1];
    }

    #[\Override]
    public function termsByHashes(array $contentHashes): array
    {
        $out = [];
        foreach ($contentHashes as $hash) {
            if (isset($this->texts[$hash])) {
                $out[$hash] = Terms::newWith(['body' => $this->texts[$hash], 'content_hash' => $hash]);
            }
        }

        return $out;
    }

    #[\Override]
    public function termsLineages(bool $includeRemoved = false): array
    {
        return array_values(array_filter($this->lineages, static fn (TermsLineage $l): bool => $includeRemoved || !$l->isRemoved()));
    }

    #[\Override]
    public function findTermsLineage(int $id): ?TermsLineage
    {
        return $this->lineages[$id] ?? null;
    }

    #[\Override]
    public function currentTermsVersions(array $lineageIds): array
    {
        $out = [];
        foreach ($this->versions as $v) {
            if (true === $v->is_current && \in_array($v->lineage_id, $lineageIds, true)) {
                $out[$v->lineage_id] = $v;
            }
        }

        return $out;
    }

    #[\Override]
    public function termsVersionsOf(int $lineageId): array
    {
        $of = array_values(array_filter($this->versions, static fn (TermsVersion $v): bool => $v->lineage_id === $lineageId));
        usort($of, static fn (TermsVersion $a, TermsVersion $b): int => $a->ordinal <=> $b->ordinal);

        return $of;
    }

    #[\Override]
    public function termsVersionsForLineages(array $lineageIds): array
    {
        $out = [];
        foreach ($lineageIds as $id) {
            $of = $this->termsVersionsOf($id);
            if ([] !== $of) {
                $out[$id] = $of;
            }
        }

        return $out;
    }

    #[\Override]
    public function orderCountsForTerms(array $contentHashes): array
    {
        return array_filter(array_intersect_key($this->orders, array_flip($contentHashes)), static fn (int $n): bool => $n > 0);
    }

    /** @var array{suppliers: list<int>, draftOrders: list<int>} */
    public array $selections = ['suppliers' => [], 'draftOrders' => []];

    #[\Override]
    public function termsLineageSelections(int $lineageId): array
    {
        return $this->selections;
    }

    #[\Override]
    public function createTermsLineage(TermsLineage $lineage, TermsVersion $first): TermsLineage
    {
        $lineage->id = $this->nextId++;
        $this->lineages[$lineage->id] = $lineage;
        $first->lineage_id = $lineage->id;
        $this->store($first);

        return $lineage;
    }

    #[\Override]
    public function saveTermsLineage(TermsLineage $lineage): TermsLineage
    {
        $this->lineages[(int) $lineage->id] = $lineage;

        return $lineage;
    }

    #[\Override]
    public function saveTermsVersion(TermsVersion $version, ?TermsVersion $retired = null): TermsVersion
    {
        $this->store($version);

        return $version;
    }

    private function store(TermsVersion $version): void
    {
        if (null === $version->id) {
            $version->id = $this->nextId++;
            $this->versions[] = $version;
        }
        $current = array_filter($this->versions, static fn (TermsVersion $v): bool => true === $v->is_current && $v->lineage_id === $version->lineage_id);
        if (\count($current) > 1) {
            throw new \LogicException('two current editions of one set');
        }

    }

    #[\Override]
    public function archiveTermsLineage(TermsLineage $lineage): array
    {
        $this->lineages[(int) $lineage->id] = $lineage;

        return $this->releaseDrafts();
    }

    #[\Override]
    public function deleteTermsLineage(TermsLineage $lineage): array
    {
        unset($this->lineages[(int) $lineage->id]);
        $this->versions = array_values(array_filter($this->versions, static fn (TermsVersion $v): bool => $v->lineage_id !== $lineage->id));

        return $this->releaseDrafts();
    }

    /** @return list<int> */
    private function releaseDrafts(): array
    {
        $released = $this->selections['draftOrders'];
        $this->selections['draftOrders'] = [];

        return $released;
    }
}
