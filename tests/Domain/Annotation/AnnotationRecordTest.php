<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Tests\Domain\Annotation;

use Nandan108\InvFlux\Domain\Annotation\Annotation;
use PHPUnit\Framework\TestCase;

final class AnnotationRecordTest extends TestCase
{
    /**
     * attrecord's save() emits every non-generated column, so any NOT NULL column left null is
     * written as an explicit NULL (the DB DEFAULT only fires on omission). beforeSave() must
     * therefore populate id, thread_id, occurred_at AND recorded_at — a missing recorded_at is
     * what 500'd the first live create.
     */
    public function testBeforeSavePopulatesAllNotNullTimestampsAndIds(): void
    {
        // Provide an explicit id so beforeSave skips minting (no RecordIdentity minter in unit
        // tests) — the timestamp defaulting under test is independent of id minting.
        $id = str_repeat("\x02", 16);
        $note = Annotation::newWith([
            'id'                 => $id,
            'target_ref_type_id' => 1,
            'target_ref_id'      => str_repeat("\x01", 16),
            'action'             => Annotation::ACTION_CREATED,
            'body'               => 'hello',
        ]);
        self::assertNull($note->recorded_at);

        $note->beforeSave();

        self::assertSame($id, $note->id);
        self::assertSame($note->id, $note->thread_id, 'thread_id defaults to the row id');
        self::assertNotNull($note->recorded_at);
        self::assertNotNull($note->occurred_at);
        self::assertSame(
            $note->recorded_at->format('U.u'),
            $note->occurred_at->format('U.u'),
            'occurred_at defaults to recorded_at',
        );
    }

    public function testBeforeSaveKeepsAnExplicitThreadAndOccurredAt(): void
    {
        $thread = str_repeat("\x09", 16);
        $occurred = new \DateTimeImmutable('2026-01-02T03:04:05.000000+00:00');
        $note = Annotation::newWith([
            'id'                 => str_repeat("\x0a", 16),
            'target_ref_type_id' => 1,
            'target_ref_id'      => str_repeat("\x01", 16),
            'thread_id'          => $thread,
            'version'            => 2,
            'action'             => Annotation::ACTION_EDITED,
            'body'               => 'edited',
            'occurred_at'        => $occurred,
        ]);

        $note->beforeSave();

        self::assertSame($thread, $note->thread_id);
        self::assertSame($occurred->format('U.u'), $note->occurred_at?->format('U.u'));
        self::assertNotNull($note->recorded_at);
    }
}
