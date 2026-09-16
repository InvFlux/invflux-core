<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Identity;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Reference-type registry — categorizes the kinds of entities a ledger row or
 * order event may point at (customer order, purchase order, shipment, return,
 * supplier return, transfer, stock take, on-hand correction, configuration
 * change, dimension-value lifecycle, plugin setting, …).
 *
 * Seeded at install with the InvFlux core defaults. Additional types may be
 * registered at runtime by plugins introducing new workflows via
 * {@see \Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore::registerRefTypes()}.
 *
 * @api
 */
#[Table(name: 'invflux_ref_types')]
final class RefTypeRecord extends Record
{
    #[Column(ColumnType::SmallIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    #[UniqueKey('uniq_ref_type_code')]
    public string $code = '';

    #[Column(ColumnType::VarChar, length: 64)]
    public string $name = '';

    /**
     * Which generic ledger id column a row referencing this type lands in:
     * `uuid` → `inventory_ledger.ref_id` (BINARY(16), hot-path minted UUIDv7);
     * `int` → `ref_int_id` (INT UNSIGNED, admin-minted auto-increment docs like POs).
     *
     * Dev-facing metadata + an insert-time validation hint — the ledger write routes by
     * the supplied id's value type, and read queries hardcode the JOIN column.
     */
    #[Column(ColumnType::Enum, enumValues: ['uuid', 'int'], default: 'uuid')]
    public string $identity_class = 'uuid';
}
