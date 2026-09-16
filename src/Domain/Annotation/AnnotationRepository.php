<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Annotation;

/**
 * Append-only persistence for {@see Annotation} rows. An "edit" or "delete" is an
 * {@see append()} of a new version — this interface never updates a row in place.
 *
 * @api
 */
interface AnnotationRepository
{
    /** Persist one annotation row (mints id/thread/timestamps in beforeSave). */
    public function append(Annotation $annotation): Annotation;

    /**
     * Every annotation row for one target — all threads, all versions — ordered by
     * `occurred_at, id`. The service folds this to current notes + history; the timeline
     * interleaves it with the entity's events.
     *
     * @return list<Annotation>
     */
    public function forTarget(AnnotationTarget $target, int $limit = 500): array;

    /**
     * All rows of one thread, ascending `version`. Used to resolve a thread's owner and to
     * append the next version.
     *
     * @return list<Annotation>
     */
    public function forThread(string $threadId): array;

    /**
     * Bulk note summary per UUID target, for a list surface that shows "this one has notes":
     * keyed by lowercase hex target id → the live note count plus the newest few notes
     * themselves. Targets with no notes are **absent** from the result rather than present as 0.
     *
     * A thread counts when it is not deleted *and* some version of it has a body. Both halves
     * matter: a pure tag change is a single-version body-less thread of its own, so counting
     * threads alone would report notes on any target that has merely been tagged — and the badge
     * is meant to mark the rare one someone wrote about. An edit that only moved tags leaves the
     * newest version body-less, so the question is whether the thread *ever* carried text, not
     * whether its latest row does; the returned row is then the newest version that has one.
     *
     * **The count is of all of them, not of the returned few**, and callers depend on that: it is
     * what lets a preview say how many more are coming instead of falling silent after the ones it
     * was given. Returning only what fits would make a truncated list indistinguishable from a
     * complete one.
     *
     * `$recentPerTarget` bounds only the bodies carried back — pass 0 to count without them.
     *
     * @param list<string> $binaryTargetIds
     *
     * @return array<string, array{count: int, recent: list<Annotation>}> `recent` newest-first
     */
    public function liveNotesForUuidTargets(int $refTypeId, array $binaryTargetIds, int $recentPerTarget = 3): array;
}
