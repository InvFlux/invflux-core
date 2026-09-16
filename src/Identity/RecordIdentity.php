<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Identity;

/**
 * Static minter accessor for Records with application-minted UUIDv7 PKs.
 *
 * Records in the hot-path-mintable class (orders, order_events, corrections,
 * etc.) call `RecordIdentity::mintId()` in their `beforeSave()` hook to assign
 * a fresh `id` when none was set by the caller.
 *
 * The minter is configured once during bootstrap, typically pointing at the
 * container-resolved `UuidV7Minter`:
 *
 *   RecordIdentity::setMinter(fn () => $container->get(UuidV7Minter::class)->mint());
 *
 * Static state is justified the same way attrecord's `Record::setConnection()`
 * is — bootstrap-once configuration that every Record reaches without dependency
 * injection. Tests can substitute their own minter:
 *
 *   RecordIdentity::setMinter(fn () => random_bytes(16));
 *
 * @api
 */
final class RecordIdentity
{
    /** @var (callable(): string)|null */
    private static $minter = null;

    /** @param callable(): string $minter Returns a 16-byte binary UUID per call. */
    public static function setMinter(callable $minter): void
    {
        self::$minter = $minter;
    }

    /** @return string 16-byte binary UUID */
    public static function mintId(): string
    {
        $minter = self::$minter;
        if (null === $minter) {
            throw new \LogicException(
                'RecordIdentity minter is not configured. '
                .'Call RecordIdentity::setMinter() during bootstrap.',
            );
        }

        return $minter();
    }

    /** Reset the minter — for test teardown. */
    public static function reset(): void
    {
        self::$minter = null;
    }
}
