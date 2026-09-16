<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * The core-owned vocabulary of subject-level stock concerns — one case per bit of
 * {@see SubjectStockConcern::$bits}. This enum is the single source of truth for the bit values;
 * {@see StockConcernBits} derives its integer constants from it (for the raw-int SQL predicates that
 * read the column directly), and {@see SubjectStockConcern} carries the decoded set via
 * `#[BitmaskCaster]`.
 *
 * **The vocabulary is core-owned.** Every concern is a property of the subject that applies
 * identically to every order line referencing it. Supplementary third-party / adapter-specific flags
 * are **not** bits here — they are carried by tags, so the concern bit space stays a closed,
 * single-owner set (a bitmask caster requires one static enum to own all bits). Detailed per-concern
 * semantics and the tier at which each is *populated* live in {@see StockConcernBits}.
 *
 * @api
 */
enum StockConcern: int
{
    /** Global stock deficit: `ctd_qty < sum(qty_outstanding)`. Populated at Essentials. */
    case Deficit = 0x01;

    /** The resolved source-system identifier is no longer live (product trashed / SKU inactive). Populated at Essentials. */
    case SubjectInactive = 0x02;

    /** Merchant-set quality / recall hold; ship blocked. Populated at Pro. */
    case QualityHold = 0x04;

    /** Every batch covering outstanding demand is past expiry; ship blocked. Populated at Pro. */
    case BatchExpired = 0x08;

    /** A covering batch falls within the expiry-risk window; ship OK, route FEFO. Populated at Pro. */
    case BatchExpiryRisk = 0x10;

    /** Multi-warehouse redistribution needed (globally satisfiable, a location can't cover). Populated at Scale. */
    case LocAtRisk = 0x20;

    /**
     * Fold a set of concerns into the stored integer bitmask (order- and duplicate-independent).
     *
     * @param iterable<self> $concerns
     */
    public static function mask(iterable $concerns): int
    {
        $mask = 0;
        foreach ($concerns as $concern) {
            $mask |= $concern->value;
        }

        return $mask;
    }

    /**
     * Decompose a stored bitmask into its concerns, in declaration order. Bits with no matching case
     * are ignored (the vocabulary is closed, so this only guards against stale/foreign data).
     *
     * @return list<self>
     */
    public static function fromMask(int $mask): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $concern): bool => ($mask & $concern->value) === $concern->value,
        ));
    }
}
