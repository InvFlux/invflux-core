<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Attribute\UpdatedAt;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Domain\Procurement\DocumentParty;
use Nandan108\InvFlux\Identity\RecordIdentity;

/**
 * One order projected from an external source system (Woo, Shopify, ERP-direct, …)
 * into InvFlux.
 *
 * The surrogate `id` is internal; the `(source_system, external_id)` pair is the
 * natural key — UNIQUE-constrained to prevent duplicate projection of the same
 * external order. Repository implementations live in storage adapters
 * (`invflux-storage-mysql`, future Postgres, …); this domain type is shared.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_orders')]
#[LockTier(20)]
#[Index('idx_queue', columns: ['status', 'est_dispatch', 'id'])]
final class Order extends Record
{
    /** 16-byte binary UUIDv7 — minted on save via RecordIdentity. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    #[Column(ColumnType::VarChar, length: 16)]
    #[UniqueKey('uniq_external')]
    public string $source_system = '';

    #[Column(ColumnType::VarChar, length: 64)]
    #[UniqueKey('uniq_external')]
    public string $external_id = '';

    /** Dispatch-workbench lifecycle status (see {@see OrderStatus}). */
    #[Column(ColumnType::TinyIntUnsigned, default: OrderStatus::Untouched)]
    #[EnumCaster(OrderStatus::class)]
    #[Index('idx_status')]
    public OrderStatus $status = OrderStatus::Untouched;

    // Subject-level concerns are not cached per order: read paths JOIN
    // through `invflux_subject_stock_concerns` and aggregate with
    // `COALESCE(BIT_OR(c.bits), 0)`.

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $line_count = 0;

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $staged_count = 0;

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $shipped_count = 0;

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $unprocessed_corrections = 0;

    /**
     * Count of unsettled `refund.scheduled(mode=manual)` refunds on this order —
     * refunds owed on a non-refundable gateway that await an operator's out-of-band
     * settlement. Denormalised counter (sibling of {@see $unprocessed_corrections}):
     * `+1` when a manual schedule is emitted, `-1` when it's settled
     * (`correction.refund_confirmed`) or `refund.cancelled`. Drives the dispatch
     * queue's "Pending manual refunds" filter.
     */
    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $pending_manual_refunds = 0;

    /**
     * Expected dispatch time (EDT) — the merchant-side accountability
     * timestamp for shipping this order. "Late" is **derived** from this at
     * read/render time (`est_dispatch < now`), never materialised: the queue
     * sorts by `est_dispatch` and the dispatch SPA paints the EDT red once it's
     * in the past. There is no stored `late_days` counter (it would only ever
     * be a stale copy of this column + the clock).
     *
     * Write rules (precedence, highest first):
     *
     *  1. Merchant override (manual edit, future settings UI).
     *  2. Rule-engine action ("Pro / Scale rules write this when their
     *     conditions match").
     *  3. Baseline default: `COALESCE(paid_at, created_at) + 24h`
     *     applied at projection time. Pre-pro-rules world; documented
     *     as a placeholder until per-store SLAs ship.
     *
     * Nullable because not every code path has run a defaulting pass;
     * the dispatch SPA renders `—` when null and the late computation
     * treats the order as on-time. This is the degenerate root of the wider
     * delivery-promise design surface.
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 6)]
    #[Index('idx_est_dispatch')]
    public ?\DateTimeImmutable $est_dispatch = null;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $customer_id = null;

    #[Column(ColumnType::VarChar, length: 191, default: '')]
    public string $customer_name = '';

    #[Column(ColumnType::VarChar, length: 191, default: '')]
    public string $customer_email = '';

    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $shipping_address_hash = null;

    /**
     * The bill-to and ship-to parties **as this order stated them**, each a {@see DocumentParty}
     * content hash.
     *
     * The host freezes its own copy of an address on its order, and for a WooCommerce order that
     * copy is perfectly good. These exist because a document InvFlux *issues* — a delivery note, a
     * packing slip, a return authorisation — cannot have its parties live in the host: a channel
     * order arrives with a ship-to the host never holds, and an address read live rewrites every
     * document that ever stated it the moment a customer moves house.
     *
     * Interned, so a repeat customer's twelve orders share one row and their thirteenth after
     * moving mints a second, leaving the first twelve saying what they said.
     *
     * {@see $customer_name} and {@see $customer_email} stay alongside deliberately. They are not a
     * duplicate of these — they are the dispatch queue's search haystack, read per row over the
     * replicated working set, and a join per row is the wrong trade for a label.
     */
    #[Column(ColumnType::BigInt, nullable: true)]
    public ?int $billing_party_id = null;

    #[Column(ColumnType::BigInt, nullable: true)]
    public ?int $shipping_party_id = null;

    // ── Workflow dimensions (orthogonal, system-driven, independently stored) ──
    // Queue membership is not a single stored enum. Two independent nullable
    // conditions each answer one *system* question about why an order is out of the
    // active queue; the headline {@see OrderWorkflowState} is *derived* from them via
    // {@see workflowState()} (never stored), and the active-queue floor is the
    // generated {@see $queue_active} below. The *manual* set-aside axis (parking)
    // lives outside this record — it is a `SuppressActive` governance tag.

    /**
     * Why the order is *system-held* out of the queue (payment pending, capture
     * unresolved, fraud review), or null when not held. The system-gate axis —
     * set/cleared by signals, not by a worker. See {@see HoldReason}.
     */
    #[Column(ColumnType::TinyIntUnsigned, nullable: true)]
    #[EnumCaster(HoldReason::class)]
    public ?HoldReason $hold_reason = null;

    /**
     * When the order reached a terminal state (out of the queue, done): WC
     * `completed` / `cancelled` / `failed` / fully-`refunded`. `null` = still
     * open. Set *after* any refund/return stock handling; cleared if the order
     * leaves the terminal WC status (not a one-way trap).
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 6)]
    public ?\DateTimeImmutable $closed_at = null;

    /**
     * Active-queue membership, **database-generated + indexed** — true iff no
     * *system* workflow dimension is set (hold / close). This is the one place a
     * generated column earns its keep: it turns most of the queue floor into a
     * single indexable predicate (`queue_active = 1`) instead of a NULL-check scan.
     * The expression is deterministic (pure NULL checks), so `STORED` is legal and
     * indexable. Read-only at the application layer — the DB computes it.
     *
     * The *manual* suppress axis (a `SuppressActive` tag) can't live here — a
     * generated column can't reference another table — so the queue read ANDs a
     */
    #[Column(
        ColumnType::TinyIntUnsigned,
        generatedAs: '(`hold_reason` IS NULL AND `closed_at` IS NULL)',
        generatedMode: GeneratedColumnMode::Stored,
    )]
    #[Index('idx_queue_active')]
    public int $queue_active = 1;

    #[Column(ColumnType::DateTime, nullable: true, precision: 6)]
    #[CreatedAt]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, nullable: true, precision: 6)]
    #[UpdatedAt]
    public ?\DateTimeImmutable $updated_at = null;

    /**
     * The derived headline workflow state — Closed › OnHold › Active — over the
     * two orthogonal *system* dimensions. Never a stored column; call this rather
     * than expecting a stored `workflow_state` field — there is none, the state is
     * derived. Parking is not reflected here — it is a `SuppressActive` tag (a
     * separate manual axis).
     */
    public function workflowState(): OrderWorkflowState
    {
        return OrderWorkflowState::derive($this->hold_reason, $this->closed_at);
    }

    #[\Override]
    public function beforeSave(): void
    {
        // created_at / updated_at are auto-managed by #[CreatedAt] / #[UpdatedAt] in save()/upsertAll().
        if (null === $this->id) {
            $this->id = RecordIdentity::mintId();
        }
    }

    /**
     * `RESTRICT` like every other pointer at a party: a row an order still states is not an orphan,
     * and the engine refuses to reap it atomically rather than leaving a check-then-act gap.
     */
    #[Relation(
        RelationType::ManyToOne,
        class: DocumentParty::class,
        foreignKey: 'billing_party_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?DocumentParty $billingParty = null;

    #[Relation(
        RelationType::ManyToOne,
        class: DocumentParty::class,
        foreignKey: 'shipping_party_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?DocumentParty $shippingParty = null;

    #[\Override]
    public function validate(): void
    {
        if ('' === $this->source_system) {
            throw new RecordValidationException(
                'Order.source_system must be a non-empty string.',
                ['field' => 'source_system'],
            );
        }
        if ('' === $this->external_id) {
            throw new RecordValidationException(
                'Order.external_id must be a non-empty string.',
                ['field' => 'external_id', 'source_system' => $this->source_system],
            );
        }
        // status is enum-typed (EnumCaster) and the workflow dimensions are nullable enum/datetime
        // columns — the type system guarantees valid values, so no runtime check is needed here.
    }
}
