<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Purchase-order lifecycle status — the graph of legal transitions lives on
 * {@see PurchaseOrderLifecycle}. Int-backed so the value is a stable, sortable code in the
 * `pos.status` TINYINT column and legible in raw-SQL status filters (`->value`).
 *
 * The integer codes are **odd by design**, leaving every even value free: a new intermediate
 * state can be inserted between two existing ones without renumbering, so an Essentials install and
 * an install carrying extra states still agree on what each integer means. Lifecycle order is
 * defined by the transition map, never by the integer — the ascending values here are a
 * convenience, not a contract.
 *
 * Renumbering is a **breaking data change**: `pos.status` stores the integer, and the enum
 * hydrates through `from()`, which throws on a value that is no longer a case. Any future
 * renumber needs a data step remapping stored values, not just an edit here.
 *
 * @api
 */
enum PoStatus: int
{
    /** Draft — being prepared, not yet submitted to the supplier. */
    case InPrep = 1;

    /**
     * Awaiting approval — when the requisitioner and the approver are different people. A review-workflow
     * state: schema-present from Essentials (schema-parity), but its lifecycle edges are contributed by the
     * review add-on, so a base install never reaches it.
     */
    case InReview = 3;

    /**
     * Blessed by the approver — content frozen, awaiting dispatch. Companion to {@see InReview}: a
     * review-workflow state whose edges the review add-on contributes; inert on a base install.
     */
    case Approved = 5;

    /** Submitted to the supplier; content frozen, awaiting dispatch. */
    case Submitted = 7;

    /**
     * Supplier confirmed the order (order acknowledgement) — an optional waypoint between submission and
     * dispatch. The manual flip is base behaviour; feeding it from OA/EDI/ASN ingestion is an add-on.
     */
    case Acknowledged = 9;

    /** Supplier dispatched — goods in transit to us. */
    case InTransit = 11;

    /** A reception session is open (goods being counted in). */
    case InReception = 13;

    /**
     * Multi-delivery resting state — "got some, awaiting more". Base behaviour: receiving one delivery of
     * a split shipment and parking the PO until the next is recording what physically arrived, so the whole
     * cycle is seeded on {@see PurchaseOrderLifecycle} — the entry edge (in_reception → partially_received),
     * the reopen exit (→ in_reception for the next delivery) and the finalize exit (→ received, a final
     * receipt or a close-short). What an add-on layers on top is not this state but the advance-shipment-
     * notice document each receipt reconciles against.
     */
    case PartiallyReceived = 15;

    /** Fully received — terminal happy-path state. */
    case Received = 17;

    /** Cancelled before goods were received. */
    case Cancelled = 19;

    /**
     * Stable, kebab serialization name — the status's identity on the wire. The API and SPA speak these
     * slugs, never the backing int, so the client can't drift when the integer codes are renumbered.
     * Platform-agnostic: any adapter (WooCommerce, PrestaShop, …) uses the same slugs.
     */
    public function slug(): string
    {
        return match ($this) {
            self::InPrep            => 'in_prep',
            self::InReview          => 'in_review',
            self::Approved          => 'approved',
            self::Submitted         => 'submitted',
            self::Acknowledged      => 'acknowledged',
            self::InTransit         => 'in_transit',
            self::InReception       => 'in_reception',
            self::PartiallyReceived => 'partially_received',
            self::Received          => 'received',
            self::Cancelled         => 'cancelled',
        };
    }

    /** Resolve a status from its {@see slug()}; null for an unknown slug. */
    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Whether a PO in this status can still be carrying stock towards us — the "on order" question.
     *
     * The boundary is **the order is with the supplier and not finished arriving**. Everything
     * before {@see Submitted} is ours alone: a draft, a review or an approval is not stock on the
     * way, because the supplier has not been told. Everything from {@see Submitted} to
     * {@see PartiallyReceived} is: including the half-arrived resting state, whose remainder is
     * still genuinely inbound, and including {@see Acknowledged}, which is the *more* certain
     * version of a status already inside the set.
     *
     * **This answers status only.** How much is still coming is `po_lines.qty_open`, a generated
     * column (ordered − received), so a caller asks both: `status IN (open) AND qty_open > 0`. That
     * split is why {@see Received} needs no special handling — a fully-received PO has no open
     * quantity — and why {@see Cancelled} genuinely needs excluding here, since a PO abandoned
     * before delivery keeps a real `qty_open` forever.
     *
     * **Nor does it answer whether the order is still in the working set.** Filing one away is not
     * a status (see {@see PurchaseOrder::$archived_at}), so this enum cannot exclude it and the
     * caller must: `status IN (open) AND qty_open > 0 AND archived_at IS NULL`. All three clauses
     * carry weight, and a caller that drops the last one counts filed-away orders as on their way.
     */
    public function isOpenForInbound(): bool
    {
        return match ($this) {
            self::Submitted, self::Acknowledged, self::InTransit,
            self::InReception, self::PartiallyReceived => true,
            self::InPrep, self::InReview, self::Approved,
            self::Received, self::Cancelled => false,
        };
    }

    /**
     * The {@see isOpenForInbound()} set as backing ints, for a raw-SQL `IN (…)` list.
     *
     * Exists so a query never hand-writes the membership. Every site that did drifted: three of
     * them carried a set that dropped `acknowledged` and `partially_received`, and each read as
     * plausible on its own — which is exactly what a duplicated list buys you. Derived from the
     * cases, so a status added later joins the set by answering {@see isOpenForInbound()}, and no
     * query needs revisiting.
     *
     * @return list<int>
     */
    public static function openForInboundValues(): array
    {
        return array_values(array_map(
            static fn (self $s): int => $s->value,
            array_filter(self::cases(), static fn (self $s): bool => $s->isOpenForInbound()),
        ));
    }

    /**
     * The whole "still coming to us" predicate for a raw-SQL `WHERE`, for the PO table at `$alias`.
     *
     * **Prefer this over {@see openForInboundValues()} wherever the query can reach `archived_at`.**
     * The status set alone is only half the question — an order filed out of the working lists is
     * not inbound whatever its status says — and the missing half is invisible at the call site,
     * because a `status IN (…)` list reads as a complete answer. This returns both clauses so there
     * is nothing to remember.
     *
     * The alias is the caller's, not guessed: these queries join `invflux_pos` under whatever short
     * name they already use.
     */
    public static function openForInboundSql(string $alias): string
    {
        return sprintf(
            '%s.archived_at IS NULL AND %s.status IN (%s)',
            $alias,
            $alias,
            implode(', ', self::openForInboundValues()),
        );
    }
}
