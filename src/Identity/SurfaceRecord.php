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
 * Surface — a specific origin point for events (e.g. the workbench admin page,
 * the WooCommerce REST entrypoint, the corrections engine). Surfaces form a tree
 * via `parent_id`, letting one integration's surfaces nest under a single root
 * surface (e.g. `plugin:woocommerce → system:checkout`).
 *
 * Resolved on demand via
 * {@see \Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore::resolveSurfaceId()}.
 *
 * @api
 */
#[Table(name: 'invflux_surfaces')]
final class SurfaceRecord extends Record
{
    #[Column(ColumnType::SmallIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::TinyIntUnsigned)]
    #[UniqueKey('uniq_surface')]
    public int $surface_type_id = 0;

    #[Column(ColumnType::VarChar, length: 191)]
    #[UniqueKey('uniq_surface')]
    public string $surface_ref = '';

    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $parent_id = null;

    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $name = null;

    #[Column(ColumnType::Bool, default: true)]
    public bool $active = true;

    #[Column(ColumnType::Json, nullable: true)]
    public ?string $metadata_json = null;

    #[Relation(
        RelationType::ManyToOne,
        class: SurfaceTypeRecord::class,
        foreignKey: 'surface_type_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?SurfaceTypeRecord $surfaceType = null;

    #[Relation(
        RelationType::ManyToOne,
        class: self::class,
        foreignKey: 'parent_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?SurfaceRecord $parent = null;
}
