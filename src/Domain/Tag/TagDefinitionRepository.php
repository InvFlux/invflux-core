<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Tag;

/**
 * CRUD for the tag **vocabulary** (`invflux_tags`) — polymorphic over `scope`
 * (`order` today; `purchase_order`, `supplier`, … as those entities gain tags).
 * The *assignment* set is a separate concern ({@see TagAssignmentRepository}); this
 * interface owns only the definitions.
 *
 * **Retire, never hard-delete.** {@see archive()} sets `archived_at`; there is no
 * `delete`. The append-only annotation stream references a tag by id forever, so a
 * definition must stay resolvable: {@see all()} hides retired tags (pickers/lists),
 * but {@see find()} and {@see byIds()} still return them so historical `tag_actions`
 * deltas render. `UNIQUE(scope, slug)` still binds a retired slug, so a retired
 * `order/urgent` blocks re-creating the same slug until it is un-retired or renamed.
 *
 * @api
 */
interface TagDefinitionRepository
{
    /**
     * Active (non-retired) tag definitions in a scope, name-ordered.
     *
     * @return list<Tag>
     */
    public function all(string $scope): array;

    /** A single tag by id, retired or not (so audit trails always resolve). */
    public function find(int $id): ?Tag;

    /**
     * Tag definitions for the given ids, retired or not, keyed by id — the bulk
     * resolver for rendering annotation `tag_actions` deltas. No N+1.
     *
     * @param list<int> $ids
     *
     * @return array<int, Tag>
     */
    public function byIds(array $ids): array;

    /** A tag by (scope, slug), retired or not — the create-time uniqueness probe. */
    public function findBySlug(string $scope, string $slug): ?Tag;

    /** Create or update a tag definition; returns it with its assigned id. */
    public function save(Tag $tag): Tag;

    /**
     * Retired tag definitions in a scope, name-ordered — the complement of {@see all()}.
     *
     * Separate from `all()` rather than a flag on it, because the two answer different questions
     * and almost every caller wants only the first: a retired tag is out of the pickers, out of the
     * queue's governance aggregate, and kept only so the orders still carrying it stay readable.
     *
     * @return list<Tag>
     */
    public function archived(string $scope): array;

    /** Retire a tag (sets `archived_at`); a no-op if already retired or absent. Never deletes. */
    public function archive(int $id): void;

    /**
     * Bring a retired tag back (clears `archived_at`); a no-op if already live or absent.
     *
     * The inverse of {@see archive()}, and the reason re-creating a tag whose name is taken by a
     * retired one is offered as a restore: the definition still holds its colour, its governance
     * fields and — through the assignments, which retiring never touched — its history. Minting a
     * second definition under the same name would split that history across two ids nothing can
     * later merge.
     */
    public function restore(int $id): void;
}
