<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Tag;

use Nandan108\InvFlux\Domain\Annotation\AnnotationTarget;

/**
 * The polymorphic tag-assignment **set** — the source of truth for "what tags does this
 * document have". Mutations return the *actual* delta (idempotent: assigning an already-set
 * tag or removing an absent one is a no-op and is not reported), so the caller can record an
 * annotation carrying exactly what changed.
 *
 * @api
 */
interface TagAssignmentRepository
{
    /**
     * Attach $tagIds to $target, skipping any already attached.
     *
     * @param list<int> $tagIds
     *
     * @return list<int> the tag ids actually added
     */
    public function assign(AnnotationTarget $target, array $tagIds, ?int $actorId): array;

    /**
     * Attach $tagIds to every one of $targets in one bulk unit of work (one read of the
     * existing pairs, one insert of the genuinely-new ones) — never a per-target loop.
     * Returns the *actual* per-target delta so the caller records one annotation per target
     * that changed.
     *
     * @param list<AnnotationTarget> $targets
     * @param list<int>              $tagIds
     *
     * @return array<string, list<int>> keyed by target key ({@see AnnotationTarget::key()}) → tag ids added
     */
    public function assignMany(array $targets, array $tagIds, ?int $actorId): array;

    /**
     * Detach $tagIds from $target, skipping any not attached.
     *
     * @param list<int> $tagIds
     *
     * @return list<int> the tag ids actually removed
     */
    public function unassign(AnnotationTarget $target, array $tagIds): array;

    /**
     * @return list<int> tag ids currently attached to $target
     */
    public function tagIdsForTarget(AnnotationTarget $target): array;

    /**
     * Bulk read for list surfaces (e.g. the dispatch queue), keyed by lowercase-hex target id.
     *
     * @param list<string> $binaryTargetIds
     *
     * @return array<string, list<Tag>>
     */
    public function tagsForUuidTargets(int $refTypeId, array $binaryTargetIds): array;

    /** Drop every assignment on $target — the app-level cascade replacement (deletion hook). */
    public function purgeForTarget(AnnotationTarget $target): void;
}
