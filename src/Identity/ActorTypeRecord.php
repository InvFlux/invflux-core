<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Identity;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Actor-type registry — categorizes the principals that emit events (admin user,
 * system process, plugin trigger, etc.).
 *
 * Seeded at install with the InvFlux core defaults (`admin`, `system`, `plugin`).
 * Additional types may be registered at runtime by plugins via
 * {@see \Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore::registerActorTypes()}.
 *
 * @api
 */
#[Table(name: 'invflux_actor_types')]
final class ActorTypeRecord extends Record
{
    #[Column(ColumnType::TinyIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    #[UniqueKey('uniq_actor_type_code')]
    public string $code = '';

    #[Column(ColumnType::VarChar, length: 64)]
    public string $name = '';

    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $description = null;

    #[Column(ColumnType::Bool, default: true)]
    public bool $active = true;
}
