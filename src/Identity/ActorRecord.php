<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Identity;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Record;

/**
 * Actor — a principal that emits events (a back-office user, a system process,
 * a plugin trigger). Resolved on demand via
 * {@see \Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore::resolveActorId()}.
 *
 * Each actor is uniquely identified by the `(actor_type_id, actor_ref)` pair.
 * The `uuid` column carries the UUIDv7 federation identity assigned when the
 * actor row is first created.
 *
 * @api
 */
#[Table(name: 'invflux_actors')]
final class ActorRecord extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::TinyIntUnsigned)]
    #[UniqueKey('unique_actor')]
    public int $actor_type_id = 0;

    #[Column(ColumnType::VarChar, length: 64)]
    #[UniqueKey('unique_actor')]
    public string $actor_ref = '';

    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    public ?string $uuid = null;

    #[Relation(
        RelationType::ManyToOne,
        class: ActorTypeRecord::class,
        foreignKey: 'actor_type_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?ActorTypeRecord $actorType = null;
}
