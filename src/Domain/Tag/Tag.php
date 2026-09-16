<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Tag;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Caster\BitmaskCaster;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;

/**
 * A label that can be attached to a domain entity — the shared taxonomy behind
 * the order-tags primitive (define + assign), built **entity-agnostic** so the
 * same table + management surface serve every Record-backed taggable.
 *
 * `scope` namespaces a tag to one entity type (`order` today; `supplier`,
 * `purchase_order`, `location` as those gain tagging) — `UNIQUE(scope, slug)`
 * lets `order/urgent` and `supplier/urgent` coexist, and lets each entity's
 * picker show only its own tags. The *assignment* is the **polymorphic**
 * `invflux_tag_assignments` set (`TagAssignment`), so every taggable shares one
 * mechanism and every change is recorded as an annotation delta. Scope is an
 * open string (add-ons may introduce their own), not a closed enum.
 *
 * **Retire, never hard-delete.** The append-only annotation stream references
 * a tag by id forever (its `tag_actions` deltas), so a definition must always
 * stay resolvable. "Deleting" a tag sets {@see self::$archived_at} — it drops
 * out of pickers/filters but `find(id)` still resolves it for the audit trail.
 *
 * Admin-minted, low-cardinality → a plain auto-increment `id`. `slug` is the
 * stable machine key (derived from the name on create, or fixed for
 * system-seeded tags); `name` is the display label; `color` an optional
 * `#RRGGBB` chip colour.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_tags')]
#[LockTier(26)]
#[UniqueKey('uniq_scope_slug', columns: ['scope', 'slug'])]
final class Tag extends Record
{
    /** Order-tags scope — the first (Essentials) consumer. */
    public const SCOPE_ORDER = 'order';

    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /** Entity-type namespace this tag belongs to (e.g. {@see self::SCOPE_ORDER}). */
    #[Column(ColumnType::VarChar, length: 32)]
    public string $scope = self::SCOPE_ORDER;

    /** Stable machine key, unique within the scope. */
    #[Column(ColumnType::VarChar, length: 64)]
    public string $slug = '';

    /** Display label. */
    #[Column(ColumnType::VarChar, length: 128)]
    public string $name = '';

    /** Index (0..23) into the fixed {@see TagColorPalette} (Gmail-modeled). Default = light grey. */
    #[Column(ColumnType::TinyIntUnsigned, default: TagColorPalette::DEFAULT_ID)]
    public int $color_id = TagColorPalette::DEFAULT_ID;

    /**
     * Pro governance behaviours this tag carries (bitmask; empty = a plain,
     * Essentials descriptive tag). A shared namespace across scopes — each surface
     * honours the bits it implements. See {@see GovernanceFlag}.
     *
     * @var list<GovernanceFlag>
     */
    #[Column(ColumnType::BigIntUnsigned, default: 0)]
    #[BitmaskCaster(GovernanceFlag::class)]
    public array $governance_flags = [];

    /**
     * Signed ordinal sort-weight for the carrying entity's default listing
     * (dispatch-honoured today): positive **promotes** (Urgent floats to the top
     * of the queue), negative **demotes** (Backburner sinks below the late ones),
     * `0` = neutral. An order's effective priority is `MAX(priority)` across its
     * tags. Priority is a separate axis from {@see GovernanceFlag::SuppressActive}
     * (membership) — a suppressed order is out of the queue regardless of priority.
     */
    #[Column(ColumnType::TinyInt, default: 0)]
    public int $priority = 0;

    /**
     * Who may apply/remove this tag — the access axis (Anyone / Managed / System).
     */
    #[Column(ColumnType::TinyIntUnsigned, default: ManageAuthority::Anyone)]
    #[EnumCaster(ManageAuthority::class)]
    public ManageAuthority $manage_authority = ManageAuthority::Anyone;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $created_by_actor_id = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $updated_at = null;

    /**
     * When this tag was retired, or null while active. A retired tag is hidden
     * from pickers/filters/lists but stays resolvable by id so historical
     * annotation deltas render — we never hard-delete the vocabulary.
     */
    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $archived_at = null;

    /** Whether this tag has been retired (hidden from pickers, kept for audit). */
    public function isArchived(): bool
    {
        return null !== $this->archived_at;
    }

    /** Whether this tag carries any Pro governance behaviour (a flag, a non-neutral priority, or restricted access). */
    public function isGovernance(): bool
    {
        return [] !== $this->governance_flags
            || 0 !== $this->priority
            || ManageAuthority::Anyone !== $this->manage_authority;
    }

    /** Whether this tag's governance flags include the given behaviour. */
    public function hasFlag(GovernanceFlag $flag): bool
    {
        return \in_array($flag, $this->governance_flags, true);
    }

    #[\Override]
    public function beforeSave(): void
    {
        $now = new \DateTimeImmutable();
        if (null === $this->created_at) {
            $this->created_at = $now;
        }
        $this->updated_at = $now;
    }

    #[\Override]
    public function validate(): void
    {
        if ('' === trim($this->scope)) {
            throw new RecordValidationException('Tag.scope must not be empty.', ['field' => 'scope']);
        }
        if ('' === trim($this->slug)) {
            throw new RecordValidationException('Tag.slug must not be empty.', ['field' => 'slug']);
        }
        if ('' === trim($this->name)) {
            throw new RecordValidationException('Tag.name must not be empty.', ['field' => 'name']);
        }
        if (!TagColorPalette::isValidId($this->color_id)) {
            throw new RecordValidationException(
                \sprintf('Tag.color_id %d is out of palette range (0..%d).', $this->color_id, TagColorPalette::COUNT - 1),
                ['field' => 'color_id'],
            );
        }
        // priority is a signed TINYINT; guard the range so an out-of-bounds value
        // fails cleanly here rather than being truncated/rejected by the driver.
        if ($this->priority < -128 || $this->priority > 127) {
            throw new RecordValidationException(
                \sprintf('Tag.priority %d is out of range (-128..127).', $this->priority),
                ['field' => 'priority'],
            );
        }
    }
}
