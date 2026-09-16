<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Annotation;

use Nandan108\InvFlux\Contracts\Inventory\TransactionalStore;
use Nandan108\InvFlux\Domain\Annotation\Annotation;
use Nandan108\InvFlux\Domain\Annotation\AnnotationRepository;
use Nandan108\InvFlux\Domain\Annotation\AnnotationTarget;
use Nandan108\InvFlux\Domain\Tag\TagAssignmentRepository;

/**
 * Platform-agnostic orchestration for annotations + their tag deltas. Every write is
 * append-only (an edit/delete is a new version) and every tag mutation is recorded as an
 * annotation carrying the *actual* delta — the two happen in one transaction so the audit
 * spine can never diverge from the tag set.
 *
 * Ownership: an edit/delete may only be appended by the thread's original author, unless the
 * caller `$canModerate`. Resolving the current WP user → actor id and the moderation
 * capability live in the adapter; this service takes a resolved `?int $actorId` + bool.
 *
 * @api
 */
final class AnnotationService
{
    public function __construct(
        private readonly AnnotationRepository $annotations,
        private readonly TagAssignmentRepository $tags,
        private readonly TransactionalStore $store,
    ) {
    }

    /**
     * Open a new note thread on $target, optionally applying a tag delta in the same act.
     *
     * @param list<int> $tagsToAdd
     * @param list<int> $tagsToRemove
     */
    public function addNote(
        AnnotationTarget $target,
        ?string $body,
        array $tagsToAdd,
        array $tagsToRemove,
        ?int $actorId,
    ): Annotation {
        $body = $this->normalizeBody($body);

        /** @var Annotation */
        return $this->store->transactional(function () use ($target, $body, $tagsToAdd, $tagsToRemove, $actorId): Annotation {
            $delta = $this->applyTagDelta($target, $tagsToAdd, $tagsToRemove, $actorId);
            if (null === $body && null === $delta) {
                throw AnnotationException::invalid('An annotation needs note text or a tag change.');
            }

            return $this->annotations->append(Annotation::newWith([
                'target_ref_type_id' => $target->refTypeId,
                'target_ref_id'      => $target->refId,
                'target_ref_int_id'  => $target->refIntId,
                'version'            => 1,
                'action'             => Annotation::ACTION_CREATED,
                'body'               => $body,
                'tag_actions'        => $delta,
                'author_actor_id'    => $actorId,
            ]));
        });
    }

    /**
     * Append the next version of a note thread (owner or moderator only).
     *
     * @param list<int> $tagsToAdd
     * @param list<int> $tagsToRemove
     */
    public function editNote(
        string $threadId,
        ?string $body,
        array $tagsToAdd,
        array $tagsToRemove,
        ?int $actorId,
        bool $canModerate,
    ): Annotation {
        $body = $this->normalizeBody($body);

        /** @var Annotation */
        return $this->store->transactional(function () use ($threadId, $body, $tagsToAdd, $tagsToRemove, $actorId, $canModerate): Annotation {
            $latest = $this->requireEditableThread($threadId, $actorId, $canModerate);
            $target = $this->targetOf($latest);
            $delta = $this->applyTagDelta($target, $tagsToAdd, $tagsToRemove, $actorId);
            if (null === $body && null === $delta) {
                throw AnnotationException::invalid('An edit needs new note text or a tag change.');
            }

            return $this->annotations->append(Annotation::newWith([
                'target_ref_type_id' => $target->refTypeId,
                'target_ref_id'      => $target->refId,
                'target_ref_int_id'  => $target->refIntId,
                'thread_id'          => $threadId,
                'version'            => $latest->version + 1,
                'action'             => Annotation::ACTION_EDITED,
                'body'               => $body,
                'tag_actions'        => $delta,
                'author_actor_id'    => $actorId,
            ]));
        });
    }

    /** Soft-delete a note thread by appending a `deleted` marker (owner or moderator only). */
    public function deleteNote(string $threadId, ?int $actorId, bool $canModerate): Annotation
    {
        /** @var Annotation */
        return $this->store->transactional(function () use ($threadId, $actorId, $canModerate): Annotation {
            $latest = $this->requireEditableThread($threadId, $actorId, $canModerate);
            $target = $this->targetOf($latest);

            return $this->annotations->append(Annotation::newWith([
                'target_ref_type_id' => $target->refTypeId,
                'target_ref_id'      => $target->refId,
                'target_ref_int_id'  => $target->refIntId,
                'thread_id'          => $threadId,
                'version'            => $latest->version + 1,
                'action'             => Annotation::ACTION_DELETED,
                'body'               => null,
                'tag_actions'        => null,
                'author_actor_id'    => $actorId,
            ]));
        });
    }

    /**
     * Record a direct/bulk tag change (no note text) as an empty-body annotation. Returns
     * null when nothing actually changed (idempotent).
     *
     * @param list<int> $tagsToAdd
     * @param list<int> $tagsToRemove
     */
    public function recordTagChange(
        AnnotationTarget $target,
        array $tagsToAdd,
        array $tagsToRemove,
        ?int $actorId,
    ): ?Annotation {
        /** @var Annotation|null */
        return $this->store->transactional(function () use ($target, $tagsToAdd, $tagsToRemove, $actorId): ?Annotation {
            $delta = $this->applyTagDelta($target, $tagsToAdd, $tagsToRemove, $actorId);
            if (null === $delta) {
                return null;
            }

            return $this->annotations->append(Annotation::newWith([
                'target_ref_type_id' => $target->refTypeId,
                'target_ref_id'      => $target->refId,
                'target_ref_int_id'  => $target->refIntId,
                'version'            => 1,
                'action'             => Annotation::ACTION_CREATED,
                'body'               => null,
                'tag_actions'        => $delta,
                'author_actor_id'    => $actorId,
            ]));
        });
    }

    /**
     * Record the *same* tag delta on many targets in one transaction (the bulk-tag path):
     * bulk-apply the assignment across all targets, then append one delta annotation per target
     * that actually changed. Targets with no net change get no annotation. Returns the appended
     * annotations. No note text — this is the direct/bulk source of §5.
     *
     * @param list<AnnotationTarget> $targets
     * @param list<int>              $tagsToAdd
     * @param list<int>              $tagsToRemove
     *
     * @return list<Annotation>
     */
    public function recordTagChangeMany(array $targets, array $tagsToAdd, array $tagsToRemove, ?int $actorId): array
    {
        if ([] === $targets) {
            return [];
        }

        /** @var list<Annotation> */
        return $this->store->transactional(function () use ($targets, $tagsToAdd, $tagsToRemove, $actorId): array {
            $addedByTarget = [] === $tagsToAdd ? [] : $this->tags->assignMany($targets, $tagsToAdd, $actorId);
            $out = [];
            foreach ($targets as $target) {
                $added = $addedByTarget[$target->key()] ?? [];
                $removed = [] === $tagsToRemove ? [] : $this->tags->unassign($target, $tagsToRemove);
                if ([] === $added && [] === $removed) {
                    continue;
                }
                $delta = [];
                if ([] !== $added) {
                    $delta['added'] = $added;
                }
                if ([] !== $removed) {
                    $delta['removed'] = $removed;
                }
                $out[] = $this->annotations->append(Annotation::newWith([
                    'target_ref_type_id' => $target->refTypeId,
                    'target_ref_id'      => $target->refId,
                    'target_ref_int_id'  => $target->refIntId,
                    'version'            => 1,
                    'action'             => Annotation::ACTION_CREATED,
                    'body'               => null,
                    'tag_actions'        => $delta,
                    'author_actor_id'    => $actorId,
                ]));
            }

            return $out;
        });
    }

    /**
     * Raw annotation rows for a target (all threads, all versions). The read model folds to
     * current threads for the panel and interleaves with the entity's events for the timeline.
     *
     * @return list<Annotation>
     */
    public function history(AnnotationTarget $target, int $limit = 500): array
    {
        return $this->annotations->forTarget($target, $limit);
    }

    /**
     * Apply the tag delta and return exactly what changed, or null if nothing did.
     *
     * @param list<int> $add
     * @param list<int> $remove
     *
     * @return array{added?: list<int>, removed?: list<int>}|null
     */
    private function applyTagDelta(AnnotationTarget $target, array $add, array $remove, ?int $actorId): ?array
    {
        $added = [] === $add ? [] : $this->tags->assign($target, $add, $actorId);
        $removed = [] === $remove ? [] : $this->tags->unassign($target, $remove);
        if ([] === $added && [] === $removed) {
            return null;
        }
        $delta = [];
        if ([] !== $added) {
            $delta['added'] = $added;
        }
        if ([] !== $removed) {
            $delta['removed'] = $removed;
        }

        return $delta;
    }

    /** Load a thread, enforce edit rights, and return its latest (highest-version) row. */
    private function requireEditableThread(string $threadId, ?int $actorId, bool $canModerate): Annotation
    {
        $rows = $this->annotations->forThread($threadId);
        if ([] === $rows) {
            throw AnnotationException::notFound('Note thread not found.');
        }
        $owner = $rows[0]->author_actor_id; // v1 author (forThread is ascending by version)
        if (!$canModerate && (null === $actorId || $actorId !== $owner)) {
            throw AnnotationException::denied('You can only edit your own notes.');
        }
        $latest = $rows[\count($rows) - 1];
        if (Annotation::ACTION_DELETED === $latest->action) {
            throw AnnotationException::invalid('This note has been deleted.');
        }

        return $latest;
    }

    private function targetOf(Annotation $a): AnnotationTarget
    {
        return null !== $a->target_ref_id
            ? AnnotationTarget::uuid($a->target_ref_type_id, $a->target_ref_id)
            : AnnotationTarget::int($a->target_ref_type_id, (int) $a->target_ref_int_id);
    }

    private function normalizeBody(?string $body): ?string
    {
        if (null === $body) {
            return null;
        }
        $trimmed = trim($body);

        return '' === $trimmed ? null : $trimmed;
    }
}
