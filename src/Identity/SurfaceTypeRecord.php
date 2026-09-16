<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Identity;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Surface-type registry — categorizes the surfaces where events originate
 * (admin page, API, CLI, plugin, system, import, scheduled job, …).
 *
 * Seeded at install with the InvFlux core defaults. Additional types may be
 * registered at runtime by plugins via
 * {@see \Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore::registerSurfaceTypes()}.
 *
 * @api
 */
#[Table(name: 'invflux_surface_types')]
final class SurfaceTypeRecord extends Record
{
    #[Column(ColumnType::TinyIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    #[UniqueKey('uniq_surface_type_code')]
    public string $code = '';

    #[Column(ColumnType::VarChar, length: 64)]
    public string $name = '';

    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $description = null;
}
