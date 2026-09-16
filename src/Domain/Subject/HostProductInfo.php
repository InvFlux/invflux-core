<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * Display-oriented read model of the host-platform product behind a subject.
 *
 * The inverse of subject resolution: where a {@see \Nandan108\InvFlux\Contracts\Inventory\SubjectRegistrar}
 * maps an external identifier to a {@see SubjectId}, a
 * {@see \Nandan108\InvFlux\Contracts\Host\HostProductResolver} maps a subject back to the host
 * product's presentable fields (name / SKU / GTIN / image) for lists and pickers.
 *
 * `exists` distinguishes a subject whose host product is still live from one whose product
 * was deleted on the host (stale subject) — callers render a placeholder for the latter
 * rather than a blank row. `hostRef` carries the host's native id (e.g. a WooCommerce post
 * id) as an escape hatch for callers that need native fields beyond this DTO.
 *
 * @api
 */
final class HostProductInfo
{
    public function __construct(
        public readonly int $subjectId,
        public readonly bool $exists,
        public readonly ?string $name = null,
        public readonly ?string $sku = null,
        public readonly ?string $gtin = null,
        public readonly ?string $imageUrl = null,
        public readonly int | string | null $hostRef = null,
    ) {
    }

    /** A best-effort human label, degrading name → SKU → host ref → subject id. */
    public function label(): string
    {
        if (null !== $this->name && '' !== $this->name) {
            return $this->name;
        }
        if (null !== $this->sku && '' !== $this->sku) {
            return $this->sku;
        }
        if (null !== $this->hostRef && '' !== (string) $this->hostRef) {
            return '#'.$this->hostRef;
        }

        return 'subject '.$this->subjectId;
    }

    /** Placeholder for a subject whose host product no longer exists. */
    public static function missing(int $subjectId): self
    {
        return new self(subjectId: $subjectId, exists: false);
    }
}
