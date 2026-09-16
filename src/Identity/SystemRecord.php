<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Identity;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * System registry — the external (or platform-internal) namespace an identifier
 * belongs to. Host platforms register their own slug (e.g. `woo`); the
 * InvFlux-minted namespace ({@see INVFLUX_SLUG}) is seeded at install and holds
 * everything that is *not* a host's own vocabulary: supplier SKUs and barcodes
 * scoped per supplier actor, and the host-neutral commercial identifiers (`sku`,
 * `gtin`, the barcode family, `mpn`, `asin`).
 *
 * The split is load-bearing rather than tidy. `SubjectIdentifier`'s unique key leads
 * with `system_id`, so an identifier claimed under a host's slug is scoped to that
 * host — which is right for a post id and wrong for a SKU. A SKU is the join key two
 * catalogues are matched on: migrating a store between hosts, or admitting a second
 * storefront to one ledger, both work by finding the subject that already carries the
 * incoming SKU. Under a host slug there is nothing to find.
 *
 * Additional systems may be registered at runtime via
 * {@see \Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore::registerSystem()}.
 *
 * `SubjectIdentifier.system_id` carries a FK into this table's `id`.
 *
 * @api
 */
#[Table(name: 'invflux_systems')]
final class SystemRecord extends Record
{
    /**
     * The InvFlux-minted namespace — host-neutral identifiers and supplier-scoped codes.
     *
     * Unlike a host slug, this one never varies by install or storefront: it is the shared
     * namespace that makes a SKU comparable across them.
     */
    public const INVFLUX_SLUG = 'invflux';

    #[Column(ColumnType::SmallIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 100)]
    #[UniqueKey('uniq_system_slug')]
    public string $slug = '';

    #[Column(ColumnType::VarChar, length: 150)]
    public string $name = '';

    /** Owning plugin slug, when the system is contributed by an add-on. */
    #[Column(ColumnType::VarChar, length: 100, nullable: true)]
    public ?string $plugin = null;
}
