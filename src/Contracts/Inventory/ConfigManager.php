<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Inventory;

/**
 * Manage installation-level storage configuration.
 *
 * @api
 */
interface ConfigManager
{
    /** Return the currently configured storage quantity scale. */
    public function quantityScale(): int;

    /** Set the storage quantity scale before any persisted data exists. */
    public function setQuantityScale(int $scale): void;

    /** Migrate all persisted quantities to a new storage scale. */
    public function migrateQuantityScale(int $targetScale): void;
}
