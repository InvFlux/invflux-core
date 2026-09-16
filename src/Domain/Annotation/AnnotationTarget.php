<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Annotation;

/**
 * The resolved polymorphic target of an annotation / tag assignment: a ref-type id plus
 * exactly one of a BINARY(16) UUID id (orders) or an INT id (purchase orders) — the dual
 * encoding the ledger uses for mixed-id referents.
 *
 * The adapter resolves a ref-type *code* (`order`, `purchase_order`, …) to its id and builds
 * one of these; core services and repositories take it as an opaque target.
 *
 * @api
 */
final class AnnotationTarget
{
    private function __construct(
        public readonly int $refTypeId,
        public readonly ?string $refId,
        public readonly ?int $refIntId,
    ) {
    }

    /** A UUID-keyed target (e.g. an order): $binaryId is the 16-byte binary id. */
    public static function uuid(int $refTypeId, string $binaryId): self
    {
        return new self($refTypeId, $binaryId, null);
    }

    /** An INT-keyed target (e.g. a purchase order). */
    public static function int(int $refTypeId, int $id): self
    {
        return new self($refTypeId, null, $id);
    }

    /**
     * A stable string key for this target — `refType:hex(uuid)` or `refType:#int` — so callers
     * can key per-target result maps (e.g. bulk tag-assignment deltas) without colliding UUID and
     * INT referents across ref-types.
     */
    public function key(): string
    {
        return null !== $this->refId
            ? $this->refTypeId.':'.bin2hex($this->refId)
            : $this->refTypeId.':#'.(int) $this->refIntId;
    }
}
