<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Application\Procurement\ReapOrphanedParties;
use PHPUnit\Framework\TestCase;

/**
 * The policy half of the reaper, which is the half that can be wrong without failing.
 *
 * Whether a row is referenced is answered by the database — the foreign-key catalogue, then
 * `RESTRICT` — and is covered by the storage package's integration tests. What is pinned here is the
 * *grace period*, because getting it wrong deletes a party a request is about to point at, and
 * nothing about that failure is visible until a document has no address.
 */
final class ReapOrphanedPartiesTest extends TestCase
{
    /**
     * Interning happens *before* the transaction that writes the pointer, so a just-interned party is
     * legitimately unreferenced for a moment. `RESTRICT` cannot help — it guards committed
     * references, not one in flight — so age is the only thing standing between the reaper and a
     * document that loses its address between two statements.
     *
     * Sixty seconds against a write path bounded by a fifty-second lock timeout. If this constant is
     * ever lowered below that bound, the margin is gone.
     */
    public function testTheGracePeriodOutlastsTheLockTimeoutItGuardsAgainst(): void
    {
        self::assertGreaterThan(
            50,
            ReapOrphanedParties::GRACE_SECONDS,
            'the grace period must exceed innodb_lock_wait_timeout, or a write that waited out a '
            .'lock can have its freshly-interned party reaped before it commits its pointer',
        );
    }

    /**
     * Reaping nothing must cost nothing — no catalogue read, no statement. The save path calls this
     * with the outgoing key of every edit, and a supplier created rather than edited has none.
     */
    public function testAnEmptyKeySetIsANoOp(): void
    {
        $reaper = new ReapOrphanedParties();

        self::assertSame(0, $reaper->forKeys([]));
        self::assertSame(0, $reaper->deleteIfUnreferenced([]));
    }

    /**
     * A nullable pointer column yields null for a row that never had one, and the save path passes
     * what it read rather than filtering first. Nulls are not keys and must not reach a query.
     */
    public function testNullPointersAreNotTreatedAsKeys(): void
    {
        self::assertSame(0, (new ReapOrphanedParties())->forKeys([null, null]));
    }
}
