<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Tests\Application\Annotation;

use Nandan108\InvFlux\Application\Annotation\AnnotationException;
use Nandan108\InvFlux\Application\Annotation\AnnotationService;
use Nandan108\InvFlux\Contracts\Inventory\TransactionalStore;
use Nandan108\InvFlux\Domain\Annotation\Annotation;
use Nandan108\InvFlux\Domain\Annotation\AnnotationRepository;
use Nandan108\InvFlux\Domain\Annotation\AnnotationTarget;
use Nandan108\InvFlux\Domain\Tag\TagAssignmentRepository;
use PHPUnit\Framework\TestCase;

final class AnnotationServiceTest extends TestCase
{
    private const OWNER = 7;
    private const OTHER = 9;

    private function target(): AnnotationTarget
    {
        return AnnotationTarget::uuid(1, str_repeat("\x01", 16));
    }

    private function service(FakeAnnotationRepo $ann, FakeTagRepo $tags): AnnotationService
    {
        return new AnnotationService($ann, $tags, new PassthroughStore());
    }

    public function testAddNoteCreatesVersionOneAndAppliesTagDelta(): void
    {
        $ann = new FakeAnnotationRepo();
        $tags = new FakeTagRepo();
        $note = $this->service($ann, $tags)->addNote($this->target(), 'waiting on restock', [10, 20], [], self::OWNER);

        self::assertSame('created', $note->action);
        self::assertSame(1, $note->version);
        self::assertSame('waiting on restock', $note->body);
        self::assertSame(['added' => [10, 20]], $note->tag_actions);
        self::assertNotNull($note->thread_id);
        self::assertSame([10, 20], $tags->attached);
    }

    public function testAddNoteWithNeitherBodyNorRealTagChangeThrows(): void
    {
        $tags = new FakeTagRepo();
        $tags->attached = [5]; // 5 already attached → no real delta
        $this->expectException(AnnotationException::class);
        $this->service(new FakeAnnotationRepo(), $tags)->addNote($this->target(), '   ', [5], [], self::OWNER);
    }

    public function testEditByOwnerAppendsNextVersion(): void
    {
        $ann = new FakeAnnotationRepo();
        $svc = $this->service($ann, new FakeTagRepo());
        $v1 = $svc->addNote($this->target(), 'first', [], [], self::OWNER);
        $v2 = $svc->editNote((string) $v1->thread_id, 'corrected', [], [], self::OWNER, false);

        self::assertSame(2, $v2->version);
        self::assertSame('edited', $v2->action);
        self::assertSame('corrected', $v2->body);
        self::assertSame($v1->thread_id, $v2->thread_id);
    }

    public function testEditByNonOwnerIsDenied(): void
    {
        $ann = new FakeAnnotationRepo();
        $svc = $this->service($ann, new FakeTagRepo());
        $v1 = $svc->addNote($this->target(), 'mine', [], [], self::OWNER);

        $this->expectException(AnnotationException::class);
        $this->expectExceptionMessageMatches('/your own notes/');
        $svc->editNote((string) $v1->thread_id, 'hijack', [], [], self::OTHER, false);
    }

    public function testModeratorCanEditAnyThread(): void
    {
        $ann = new FakeAnnotationRepo();
        $svc = $this->service($ann, new FakeTagRepo());
        $v1 = $svc->addNote($this->target(), 'mine', [], [], self::OWNER);
        $v2 = $svc->editNote((string) $v1->thread_id, 'moderated', [], [], self::OTHER, true);
        self::assertSame(2, $v2->version);
    }

    public function testDeleteAppendsDeletedMarkerAndBlocksFurtherEdits(): void
    {
        $ann = new FakeAnnotationRepo();
        $svc = $this->service($ann, new FakeTagRepo());
        $v1 = $svc->addNote($this->target(), 'temp', [], [], self::OWNER);
        $del = $svc->deleteNote((string) $v1->thread_id, self::OWNER, false);
        self::assertSame('deleted', $del->action);

        $this->expectException(AnnotationException::class);
        $svc->editNote((string) $v1->thread_id, 'resurrect', [], [], self::OWNER, false);
    }

    public function testRecordTagChangeWritesEmptyBodyAnnotationAndNullOnNoChange(): void
    {
        $ann = new FakeAnnotationRepo();
        $tags = new FakeTagRepo();
        $svc = $this->service($ann, $tags);

        $rec = $svc->recordTagChange($this->target(), [3], [], null);
        self::assertNotNull($rec);
        self::assertNull($rec->body);
        self::assertSame(['added' => [3]], $rec->tag_actions);

        // re-adding the same tag is a no-op → no annotation
        self::assertNull($svc->recordTagChange($this->target(), [3], [], null));
    }
}

/** In-memory {@see AnnotationRepository}; mimics beforeSave's id/thread minting. */
final class FakeAnnotationRepo implements AnnotationRepository
{
    /** @var list<Annotation> */
    public array $rows = [];
    private int $seq = 0;

    #[\Override]
    public function append(Annotation $annotation): Annotation
    {
        if (null === $annotation->id) {
            $annotation->id = str_pad((string) ++$this->seq, 16, "\0", STR_PAD_LEFT);
        }
        if (null === $annotation->thread_id) {
            $annotation->thread_id = $annotation->id;
        }
        $this->rows[] = $annotation;

        return $annotation;
    }

    #[\Override]
    public function forTarget(AnnotationTarget $target, int $limit = 500): array
    {
        return $this->rows;
    }

    #[\Override]
    public function forThread(string $threadId): array
    {
        $out = array_values(array_filter($this->rows, static fn (Annotation $a): bool => $a->thread_id === $threadId));
        usort($out, static fn (Annotation $a, Annotation $b): int => $a->version <=> $b->version);

        return $out;
    }

    #[\Override]
    public function liveNotesForUuidTargets(int $refTypeId, array $binaryTargetIds, int $recentPerTarget = 3): array
    {
        return [];
    }
}

/** In-memory {@see TagAssignmentRepository} tracking one target's attached set. */
final class FakeTagRepo implements TagAssignmentRepository
{
    /** @var list<int> */
    public array $attached = [];

    #[\Override]
    public function assign(AnnotationTarget $target, array $tagIds, ?int $actorId): array
    {
        $added = [];
        foreach ($tagIds as $id) {
            if (!in_array($id, $this->attached, true)) {
                $this->attached[] = $id;
                $added[] = $id;
            }
        }

        return $added;
    }

    #[\Override]
    public function assignMany(array $targets, array $tagIds, ?int $actorId): array
    {
        $delta = [];
        foreach ($targets as $target) {
            $added = $this->assign($target, $tagIds, $actorId);
            if ([] !== $added) {
                $delta[$target->key()] = $added;
            }
        }

        return $delta;
    }

    #[\Override]
    public function unassign(AnnotationTarget $target, array $tagIds): array
    {
        $removed = [];
        foreach ($tagIds as $id) {
            $k = array_search($id, $this->attached, true);
            if (false !== $k) {
                unset($this->attached[$k]);
                $removed[] = $id;
            }
        }
        $this->attached = array_values($this->attached);

        return $removed;
    }

    #[\Override]
    public function tagIdsForTarget(AnnotationTarget $target): array
    {
        return $this->attached;
    }

    #[\Override]
    public function tagsForUuidTargets(int $refTypeId, array $binaryTargetIds): array
    {
        return [];
    }

    #[\Override]
    public function purgeForTarget(AnnotationTarget $target): void
    {
        $this->attached = [];
    }
}

/** Runs the closure inline — no real transaction needed for the unit under test. */
final class PassthroughStore implements TransactionalStore
{
    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        return $operation();
    }

    #[\Override]
    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
        return $callback();
    }
}
