<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Identity;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Identifier-type registry — categorizes the external identifiers that can be
 * claimed on a {@see \Nandan108\InvFlux\Domain\Subject\SubjectIdentifier}
 * (SKU, GTIN/barcode families, WooCommerce post IDs, supplier SKUs, …).
 *
 * Seeded at install with the InvFlux core defaults. Additional types may be
 * registered at runtime via
 * {@see \Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore::registerIdentifierType()}.
 *
 * `SubjectIdentifier.type_id` carries a FK into this table's `id`.
 *
 * @api
 */
#[Table(name: 'invflux_identifier_types')]
final class IdentifierTypeRecord extends Record
{
    #[Column(ColumnType::SmallIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 50)]
    #[UniqueKey('uniq_identifier_type_code')]
    public string $code = '';

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';

    /** Coarse grouping for admin surfaces (e.g. 'internal', 'barcode', 'manufacturer', 'marketplace'). */
    #[Column(ColumnType::VarChar, length: 50, nullable: true)]
    public ?string $category = null;
}
