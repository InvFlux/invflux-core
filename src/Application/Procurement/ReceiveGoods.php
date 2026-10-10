<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\Attrecord\LockSet;
use Nandan108\Attrecord\RawSql;
use Nandan108\Attrecord\RecordSet;
use Nandan108\InvFlux\Application\Costing\WeightedAverage;
use Nandan108\InvFlux\Contracts\Inventory\InventoryStore;
use Nandan108\InvFlux\Contracts\Inventory\UnbackedClaimReader;
use Nandan108\InvFlux\Domain\Procurement\CostSource;
use Nandan108\InvFlux\Domain\Procurement\GoodsReceipt;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;
use Nandan108\InvFlux\Domain\Procurement\ReceiptLine;
use Nandan108\InvFlux\Domain\Procurement\SubjectCost;
use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\Exceptions\ReceiptCostPolicyException;
use Nandan108\InvFlux\Idempotency\IdempotencyKey;
use Nandan108\InvFlux\Idempotency\IdempotentOutcome;
use Nandan108\InvFlux\Mutation\ActorReference;
use Nandan108\InvFlux\Mutation\EntityReference;
use Nandan108\InvFlux\Mutation\StorageBatchFlowRequest;
use Nandan108\InvFlux\Schema\Extension\EventBinding;
use Nandan108\InvFlux\Schema\Extension\SlotSpaceAssembler;
use Nandan108\InvFlux\Schema\Extension\StockFlowEvent;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;

/**
 * Receive goods: record the receipt (receipt + lines + qty_received bump) and post the resulting
 * stock movement per subject — a write-in `nil → {warehouse}.atp`, with the goods_receipt carried on
 * the ledger row (an INT-keyed referent → `ref_int_id`, the proximate source doc for the cost join)
 * — plus the weighted-average-cost recompute (foreign line costs converted to base at the receipt's
 * FX rate), all in **one transaction**.
 *
 * The goods usually answer a purchase order, but need not answer anything: an opening balance, a
 * delivery nobody ordered, units made in-house. Those run the same way, under their own movement
 * type, and state their cost through the receipt's
 * {@see \Nandan108\InvFlux\Domain\Procurement\ReceiptReason} rather than through an order — enforced
 * here, because a receipt that brought stock in without a valuation would leave the books carrying
 * units they cannot price.
 *
 * Atomicity + locking: the receipt persist, the inventory movement, and the WAC update
 * commit (or roll back) together inside a single `InventoryStore::transactional()`. Domain
 * entities are locked first via {@see LockSet::acquire()} — SubjectCost (tier 32) →
 * PurchaseOrder (33) → PurchaseOrderLine (34) — and the inventory-state locks are taken
 * afterwards by the batch movement, honoring the domain-first / inventory-second
 * lock order. LockSet runs on the same session that owns the transaction (the shared
 * request session that every Record and the InventoryStore are bound to at bootstrap), so
 * its `FOR UPDATE` locks are held for the transaction's duration.
 *
 * Bulk by construction: receipt lines, PO-line `qty_received` bumps, the per-subject stock
 * movement, and the per-subject WAC writes are each one set-based operation — never a
 * per-line DB loop.
 *
 * Platform-agnostic (depends only on contracts + attrecord): the adapter wires the concrete
 * stores, supplies the movement-type owner key and the acting user. The Essentials path writes
 * directly into for-sale; the Pro `sup.*` lifecycle is separate, deferred flows.
 *
 * @api
 */
final class ReceiveGoods
{
    /**
     * The store-base currency the WAC is denominated in — stamped onto `SubjectCost.cost_currency` so the
     * cost basis is grounded (never a bare amount). Supplied by the adapter (core is
     * currency-config-agnostic).
     */
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly InventoryStore $inventory,
        private readonly SlotSpaceAssembler $slotSpaceAssembler,
        private readonly string $baseCurrency,
        // Optional on purpose: an adapter that tracks no orders has nothing to answer with, and a
        // receipt then lands entirely on availability.
        private readonly ?UnbackedClaimReader $unbackedClaims = null,
    ) {
    }

    /**
     * The write-in cascade's two caps for these subjects, as the execution context it reads them from.
     *
     * Read inside the receipt's transaction but not locked against: a checkout that settles between
     * this read and the movement leaves the split one order stale, which the hourly shortfall pass
     * corrects. What it cannot do is over-commit — every cap is a ceiling on units that are being
     * created regardless, so a stale figure only changes which state they land in.
     *
     * @param list<int> $subjectIds
     *
     * @return array<string, array<int, int>>
     */
    private function unbackedClaimCaps(array $subjectIds): array
    {
        if (null === $this->unbackedClaims || [] === $subjectIds) {
            return [];
        }

        $committed = [];
        $reserved = [];
        foreach ($this->unbackedClaims->unbackedClaims($subjectIds) as $subjectId => $claims) {
            if ($claims['committed'] > 0) {
                $committed[$subjectId] = $claims['committed'];
            }
            if ($claims['reserved'] > 0) {
                $reserved[$subjectId] = $claims['reserved'];
            }
        }

        return array_filter([
            SlotSpaceFactory::CONTEXT_DEFICITS          => $committed,
            SlotSpaceFactory::CONTEXT_RESERVED_DEFICITS => $reserved,
        ]);
    }

    /**
     * The flow and movement type bound to one event.
     *
     * Refuses rather than defaults: a missing binding means the base contributor never ran, and
     * receiving stock under a guessed movement type is worse than not receiving it.
     *
     * @psalm-param non-empty-string $event
     */
    private function binding(string $event): EventBinding
    {
        return $this->slotSpaceAssembler->bindings()->resolve($event)
            ?? throw new \LogicException(sprintf('No stock-flow binding is registered for event "%s".', $event));
    }

    /**
     * Whether this key already completed — a pure read that creates no claim.
     *
     * For callers whose *own* preconditions would otherwise reject a replay before it reaches the
     * idempotent path. A goods receipt is the standing case: the first submission moves the order out
     * of reception, so a retry of that same submission fails the "is a receiving session open" check
     * and the operator is told nothing happened — when in fact their delivery is on the books. Asking
     * this first turns that into the replay it is.
     */
    public function alreadyCompleted(IdempotencyKey $key): bool
    {
        return null !== $this->inventory->idempotentOutcome($key);
    }

    /**
     * The movement type is not a parameter: it comes from the binding this receipt's event
     * resolves to, owner key and all. A caller naming one would be answering a question the
     * binding already answers, and answering it differently the moment an add-on rebinds.
     *
     * @param list<ReceiptLine>                     $lines           receipt_id is assigned by the repository
     * @param non-empty-string                      $location        where the goods land; resolve it via SlotDefaultResolver rather than naming a value
     * @param IdempotencyKey|null                   $idempotencyKey  when given, the whole receipt runs behind this
     *                                                               key and a replay returns without moving stock again
     * @param (\Closure(GoodsReceipt): string)|null $settleRemainder ran **inside** this receipt's
     *                                                               transaction, after the receipt is persisted;
     *                                                               returns the outcome code recorded under the key
     */
    public function __invoke(
        GoodsReceipt $receipt,
        array $lines,
        ActorReference $actor,
        string $location,
        ?IdempotencyKey $idempotencyKey = null,
        ?\Closure $settleRemainder = null,
    ): GoodsReceipt {
        // The FX rate this delivery capitalized at — units of store-base per 1 supplier-currency unit.
        // Applied at the WAC boundary so `weighted_avg_cost` is always in base while `unit_cost_snapshot`
        // stays in the supplier's currency (audit). Null ⇒ 1.0 (PO currency == store base).
        $fxRate = null === $receipt->fx_rate ? 1.0 : (float) $receipt->fx_rate;

        // What a source-less intake's reason obliges its lines to say about cost, refused here
        // rather than at the movement: the two failing cases are failures of the *submission*, and
        // both are cheaper to answer before anything is locked. The cases that can still be
        // satisfied are filled in below, inside the transaction, where the standing valuations they
        // may draw on are already locked.
        self::assertCostIsStatable($receipt, $lines);

        // The ordered lines this receipt counts against, each named by its whole key. A line with
        // no order (a source-less intake, or another backing) counts against none and is left out,
        // so an empty list means "no ordered lines to touch" and nothing else.
        /** @var array<string, array{po_id: int, subject_id: int}> $poLineKeys */
        $poLineKeys = [];
        foreach ($lines as $line) {
            if (null !== $line->po_id) {
                $poLineKeys[$line->po_id.':'.$line->subject_id] = ['po_id' => $line->po_id, 'subject_id' => $line->subject_id];
            }
        }
        $poLineKeys = array_values($poLineKeys);
        // The orders those lines belong to — read from the lines, not from the receipt's source,
        // because a receipt backed by a shipment notice can count against several orders at once.
        $poIds = array_values(array_unique(array_map(static fn (array $key): int => $key['po_id'], $poLineKeys)));

        // Every subject with units actually arriving. Drives the lock set, the movement and the WAC;
        // qty<=0 lines carry no movement and so no lock either.
        $subjectIds = array_values(array_unique(array_map(
            static fn (ReceiptLine $line): int => $line->subject_id,
            array_filter($lines, static fn (ReceiptLine $line): bool => $line->qty > 0),
        )));

        // The cost area this receipt's figures are denominated in, resolved from the landing
        // location — and resolved HERE, before the transaction, because a composite key makes
        // naming the rows to lock a caller obligation discharged before the lock phase. No part of
        // a key may be derived from anything that happens after locking begins, and the whole
        // SubjectCost key is (subject, area).
        //
        // Location *is* the area while every addressable location is its own valuation area, which
        // is the case for as long as there is one of them. The seam where that stops being an
        // identity is a hierarchy: a bin is physical-only and can never be a cost area, so a
        // receipt landing at one must walk up to the nearest ancestor that bears valuation, and
        // this call becomes that walk. What does NOT change is where the question is asked — the
        // receipt's own location answers it either way, rather than a default standing in for it.
        $costAreaId = $this->inventory->dimensionValueId('loc', $location);

        // The connection every Record + the InventoryStore are bound to at bootstrap — its
        // session owns the transaction opened just below, so LockSet's locks belong to it.
        $connection = PurchaseOrderLine::connection();

        $receive = function () use (
            $receipt,
            $lines,
            $actor,
            $location,
            $fxRate,
            $poLineKeys,
            $poIds,
            $subjectIds,
            $costAreaId,
            $connection,
        ): GoodsReceipt {
            // 1. Domain-entity locks, tier-ordered by LockSet: SubjectCost (32) →
            //    PurchaseOrder (33) → PurchaseOrderLine (34). Inventory-state locks come
            //    later (step 4). A SubjectCost row that doesn't exist yet (first receipt of
            //    a subject into this area) is simply not locked — it's created in step 5.
            //
            //    A source-less intake locks neither procurement tier: it answers no order, so
            //    there are no ordered lines to bump and no order to transition. Both tiers are
            //    still *declared* with an empty list rather than dropped from the set — LockSet
            //    skips an empty target without a query, and declaring them keeps one acquisition
            //    order for every caller, which is the property the tier ordering exists to give.
            //
            //    The orders to lock are the ones the receipt's lines name, which is also what says
            //    whether to lock any: a receipt line names an order only when a purchase order backs
            //    it. Reading them from the lines rather than the receipt's source is what keeps this
            //    right when the backing is not itself an order (a shipment notice covering several);
            //    that backing's own rows are its owner's to lock, in its own tier.
            // A SubjectCost row is named by its whole key, so the cost area resolved above rides
            // into the lock rather than being applied afterwards. That is what makes the scoping
            // structural instead of a filter: a figure for the same subject in another area is a
            // different row, not a row to be discarded on the way back.
            $locks = LockSet::acquire($connection, [
                SubjectCost::class       => array_map(
                    static fn (int $id): array => ['subject_id' => $id, 'cost_area_id' => $costAreaId],
                    $subjectIds,
                ),
                PurchaseOrder::class     => $poIds,
                PurchaseOrderLine::class => $poLineKeys,
            ]);

            /** @var array<int, SubjectCost> $existingCosts */
            $existingCosts = [];
            foreach ($locks[SubjectCost::class] as $cost) {
                /** @var SubjectCost $cost */
                // Keyed by subject alone even though the row's key is a pair — correct because the
                // lock named one area, so the set cannot hold two rows for one subject. The
                // tripwire stays: it is cheap, and it is what would catch a future caller that
                // receives into several areas at once and reaches this loop expecting the old
                // shape. Without it that caller gets whichever row the lock returned last, and a
                // WAC weighted against the wrong area — wrong, silent, and downstream of
                // everything that could notice.
                if (isset($existingCosts[$cost->subject_id])) {
                    throw new \LogicException(sprintf(
                        'SubjectCost returned more than one cost area for subject %d; '
                        .'this collection must be keyed on the full primary key.',
                        $cost->subject_id,
                    ));
                }
                $existingCosts[$cost->subject_id] = $cost;
            }

            // 2. Settle what the reason says each unstated line cost, then aggregate per subject:
            //    total received units (drive the write-in movement and the on-hand math) and the
            //    costed value/qty (drive the WAC). Costed value is in BASE currency
            //    (unit_cost_snapshot × fxRate), so the WAC it feeds is base-denominated.
            //
            //    The fill happens here, under the locks, because the standing valuation it may read
            //    is on the very SubjectCost rows the WAC recompute is about to rewrite — reading it
            //    outside would be reading a number another receipt could still change.
            self::applyReasonCost($receipt, $lines, $existingCosts);
            [$receivedQtyBySubject, $costedQtyBySubject, $costedValueBySubject]
                = self::aggregateBySubject($lines, $fxRate);

            // 3. Persist the receipt + its lines and bump every PO line's qty_received
            //    (bulk inside recordReceipt).
            $saved = $this->purchaseOrders->recordReceipt($receipt, $lines);

            // 4. Post the write-in for every received subject in ONE batch flow (ref = the
            //    goods_receipt). `write_in` is all-create, so executeBatchFlowFromStorage runs it
            //    without reading prior state. The receiving warehouse rides in as the flow's
            //    `location` param.
            //
            //    The units back what is already claimed before they are promised to anyone new:
            //    committed orders that are short, then live checkouts holding quantity nothing
            //    backs, then availability. Landing everything on availability instead — and booking
            //    the backorders in a later pass — leaves a window in which the units a paid order
            //    was waiting for are on sale to the next customer. How much each subject owes is
            //    the order domain's to say, per subject, so it rides in the execution context; with
            //    nobody to ask, every cap is 0 and the flow collapses to `create(atp)`.
            //    The ref points at the PROXIMATE source doc (this receipt), not the PO — a clean
            //    1:1 cost join for the valued-movement stream (ledger → goods_receipt →
            //    receipt_lines.unit_cost_snapshot); grouping by the ordering document stays one
            //    join away via the receipt's source ref.
            //
            //    Which movement type is the receipt's own answer to why the stock is here: goods
            //    against an ordering document, or an intake that answers none. Both post the same
            //    physical flow, and the ledger has to be able to tell them apart afterwards.
            if ([] !== $receivedQtyBySubject) {
                // Which event this is, is the receipt's own answer: goods against an ordering
                // document, or an intake that answers none. Two events rather than one, because
                // the two are separately routable — an inspection gate belongs on what a supplier
                // delivered, not on stock the merchant found in their own stockroom.
                $binding = $this->binding($receipt->hasSource()
                    ? StockFlowEvent::GOODS_RECEIPT_COUNTED
                    : StockFlowEvent::STOCK_INTAKE_COUNTED);

                $this->inventory->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
                    movementTypeOwnerKey: $binding->movementTypeOwnerKey,
                    movementTypeCode: $binding->movementTypeCode,
                    flow: $binding->flow,
                    subjectIds: array_map(static fn (int $id): SubjectId => new SubjectId($id), $subjectIds),
                    params: [SlotSpaceFactory::PARAM_LOCATION => $location],
                    reference: new EntityReference('goods_receipt', (int) $saved->id),
                    actor: $actor,
                    executionContext: $this->unbackedClaimCaps(array_keys($receivedQtyBySubject)),
                    quantitiesBySubjectId: $receivedQtyBySubject,
                ));
            }

            // 5. Recompute WAC (0g) for every costed subject in ONE batch read + ONE bulk
            //    save. On-hand is read AFTER the movement — under the inventory-state locks
            //    just taken — and the pre-receipt on-hand derived by subtracting the
            //    received units, so the weight is race-free without a second lock tier.
            [$costs, $before] = $this->recomputeWeightedAverageCosts(
                $subjectIds,
                $costAreaId,
                $receivedQtyBySubject,
                $costedQtyBySubject,
                $costedValueBySubject,
                $existingCosts,
            );
            if ([] !== $costs) {
                (new RecordSet($costs))->upsertAll();
            }

            // 6. Stamp what the weighting read onto the receipt's lines, so the receipt can say what
            //    it left behind. Known only now — the on-hand is read after the movement, under its
            //    locks — so it is written after the lines, in one statement for the whole receipt.
            self::stampSnapshot((int) $saved->id, $before);

            return $saved;
        };

        // Without a key this is the plain receipt it always was. With one, the whole thing — the
        // receipt, the movement, the WAC, and whatever the caller settles on top — runs behind that
        // key, so a retried submission replays instead of moving stock a second time.
        //
        // The claim is written by the same `transactional()` that wraps the work, and this store's
        // transactions are depth-counted, so the nested one below joins it rather than opening a
        // second. Claim and work therefore commit together: a failure anywhere rolls back both, and
        // the retry that follows is *supposed* to re-run.
        if (null === $idempotencyKey) {
            $saved = $this->inventory->transactional($receive);
            if (null !== $settleRemainder) {
                $settleRemainder($saved);
            }

            return $saved;
        }

        $saved = null;
        $execution = $this->inventory->executeIdempotent(
            $idempotencyKey,
            function () use ($receive, $settleRemainder, &$saved): IdempotentOutcome {
                $received = $this->inventory->transactional($receive);
                $saved = $received;

                // Settling runs *inside* this transaction on purpose: the delivery and what it
                // resolves about the order are one fact, so there is no window in which stock has
                // moved and the order still says it is expecting it.
                $outcome = null === $settleRemainder ? 'receipt_logged' : $settleRemainder($received);

                return IdempotentOutcome::terminal($outcome, ['receiptId' => (int) $received->id]);
            },
        );

        if (null !== $saved) {
            return $saved;
        }

        // Replayed: the closure never ran, so the receipt this key already created is the answer.
        // Nothing moved this time round, which is the entire point.
        /** @psalm-var mixed $replayedId */
        $replayedId = $execution->payload['receiptId'] ?? null;
        $existing = \is_int($replayedId) ? GoodsReceipt::getOne($replayedId) : null;

        return $existing ?? $receipt;
    }

    /**
     * Refuse a source-less intake whose reason cannot be honored — before anything is locked.
     *
     * Two of the four cost sources can fail, and they fail for opposite reasons. {@see CostSource::Entered}
     * fails because only the operator holds the number and they did not give it; {@see CostSource::Derived}
     * fails because the number is computed from somewhere this install cannot address. Neither has a
     * safe fallback: standing in the product's seed valuation would record a cost that is not what this
     * arrival cost, and the resulting weighted average would be wrong in a way no later reading can
     * detect. The other two settle in {@see applyReasonCost()}.
     *
     * Public so a caller taking the submission — a REST controller, an importer — can ask the same
     * question up front and answer it in its own vocabulary, rather than catching a thrown domain
     * exception after the fact. Calling it is an optimisation, never a substitute: this runs again
     * on the way in, so a caller that skips it is refused rather than trusted.
     *
     * @param list<ReceiptLine> $lines
     *
     * @throws ReceiptCostPolicyException
     */
    public static function assertCostIsStatable(GoodsReceipt $receipt, array $lines): void
    {
        $reason = $receipt->reason;
        if (null === $reason) {
            // Either the receipt answers a document — and then its cost comes from that document
            // rather than from any policy here — or it is malformed, which the record refuses on save.
            return;
        }

        $costSource = $reason->costSource();
        if (CostSource::Derived === $costSource) {
            throw ReceiptCostPolicyException::costCannotBeDerived($reason);
        }
        if (CostSource::Entered !== $costSource) {
            return;
        }

        foreach ($lines as $line) {
            if ($line->qty > 0 && null === $line->unit_cost_snapshot) {
                throw ReceiptCostPolicyException::costMustBeStated($reason, $line->subject_id);
            }
        }
    }

    /**
     * Fill in what a source-less intake's reason says its unstated lines cost.
     *
     * Only the two sources that *have* an answer reach here — {@see assertCostIsStatable()} has already
     * refused the rest — and a line that states its own cost is never overwritten: the reason decides
     * what happens in the absence of a figure, not in spite of one.
     *
     * - {@see CostSource::Free} writes a literal zero rather than leaving the line uncosted. The two
     *   are not the same: an uncosted line drops out of the weighted average entirely, whereas a
     *   sample or a donation genuinely did cost nothing and genuinely does lower what the stock on
     *   hand is worth per unit. Recording the fact is the point.
     * - {@see CostSource::SeedFallback} stands the product's seed valuation in, and leaves the line
     *   uncosted when there is none — "we do not know what these cost" is the truthful outcome for
     *   units found on a shelf, and a fabricated figure would be worse than an absent one.
     *
     * The seed valuation is already denominated in the store base, so this path assumes the identity
     * FX rate. That holds because the reasons routed to it — stock found on hand, goods back outside
     * the returns process — are arrivals nobody invoiced, and so carry no supplier currency to convert
     * from. A reason added later that can be foreign would need to be converted, not filled.
     *
     * @param list<ReceiptLine>       $lines
     * @param array<int, SubjectCost> $existingCosts locked rows, keyed by subject
     */
    private static function applyReasonCost(GoodsReceipt $receipt, array $lines, array $existingCosts): void
    {
        $reason = $receipt->reason;
        if (null === $reason) {
            return;
        }

        $costSource = $reason->costSource();
        foreach ($lines as $line) {
            if ($line->qty <= 0 || null !== $line->unit_cost_snapshot) {
                continue;
            }
            $line->unit_cost_snapshot = match ($costSource) {
                CostSource::Free         => '0.0000',
                CostSource::SeedFallback => ($existingCosts[$line->subject_id] ?? null)?->seed_cost,
                default                  => null,
            };
        }
    }

    /**
     * Reduce the receipt's lines to the three per-subject totals the movement and the WAC run on:
     * units received, units carrying a cost, and what those units are worth in the store base.
     *
     * `qty <= 0` lines are not a receipt of anything and carry no movement. A line with no unit cost
     * still moves stock but is left out of both costed totals, so it neither establishes nor dilutes
     * the weighted average — the subject simply keeps the cost basis it had.
     *
     * Public, with {@see WeightedAverage::blend()}, so a reader showing what a receipt left behind
     * (from `ReceiptLine::$on_hand_before` / `$cost_before`) aggregates exactly as the posting did.
     *
     * @param list<ReceiptLine> $lines
     *
     * @return array{array<int, int>, array<int, int>, array<int, float>}
     */
    public static function aggregateBySubject(array $lines, float $fxRate): array
    {
        $receivedQtyBySubject = [];   // subject_id => total received qty
        $costedQtyBySubject = [];     // subject_id => received qty carrying a unit cost
        $costedValueBySubject = [];   // subject_id => Σ(qty · unitCost · fxRate) in base currency

        foreach ($lines as $line) {
            if ($line->qty <= 0) {
                continue;
            }
            $subjectId = $line->subject_id;
            $receivedQtyBySubject[$subjectId] = ($receivedQtyBySubject[$subjectId] ?? 0) + $line->qty;
            if (null !== $line->unit_cost_snapshot) {
                $costedQtyBySubject[$subjectId] = ($costedQtyBySubject[$subjectId] ?? 0) + $line->qty;
                $costedValueBySubject[$subjectId] = ($costedValueBySubject[$subjectId] ?? 0.0)
                    + (float) $line->qty * (float) $line->unit_cost_snapshot * $fxRate;
            }
        }

        return [$receivedQtyBySubject, $costedQtyBySubject, $costedValueBySubject];
    }

    /**
     * Build the updated/created SubjectCost rows for a receipt — never saving per row; the
     * caller bulk-saves the returned list.
     *
     * `WAC = (onHandBefore·oldWac + Σ(recvQty·unitCost)) / (onHandBefore + Σ recvQty)`,
     * computed per subject from the receipt's costed lines (`onHandBefore` derived from the
     * post-movement on-hand minus the units just received). Subjects whose lines carry no
     * `unit_cost_snapshot` are skipped. With no prior WAC or no prior stock to weight
     * against, the receipt cost establishes the WAC. `seed_cost` is the merchant-set
     * valuation baseline — left untouched here (the receipt machine owns WAC, not the
     * baseline; a null seed_cost means "merchant never set one"). Float math +
     * 4-decimal formatting (the codebase has no bcmath); good enough for Essentials valuation,
     * revisit if exact decimal accumulation is needed.
     *
     * @param list<int>               $subjectIds
     * @param int                     $costAreaId           the area every row written here is denominated in — the
     *                                                      other half of the key, so a created row cannot omit it
     * @param array<int, int>         $receivedQtyBySubject
     * @param array<int, int>         $costedQtyBySubject
     * @param array<int, float>       $costedValueBySubject
     * @param array<int, SubjectCost> $existingCosts
     *
     * Returns the rows to save, and per received subject the on-hand before (unclamped) and the
     * cost weighted against
     *
     * @return array{0: list<SubjectCost>, 1: array<int, array{0: int, 1: ?string}>}
     */
    private function recomputeWeightedAverageCosts(
        array $subjectIds,
        int $costAreaId,
        array $receivedQtyBySubject,
        array $costedQtyBySubject,
        array $costedValueBySubject,
        array $existingCosts,
    ): array {
        $subjectIdObjs = array_map(static fn (int $id): SubjectId => new SubjectId($id), $subjectIds);
        $onHandAfter = $this->inventory->onHandTotalsForSubjects($subjectIdObjs);

        $now = new \DateTimeImmutable();
        $costs = [];
        $before = [];
        foreach ($subjectIds as $subjectId) {
            $receivedQty = $receivedQtyBySubject[$subjectId] ?? 0;
            // Unclamped for the record; WeightedAverage::blend() treats a short position as nothing
            // to weight, which is the clamp the average itself needs.
            $onHandBefore = ($onHandAfter[$subjectId] ?? $receivedQty) - $receivedQty;
            $existing = $existingCosts[$subjectId] ?? null;
            // Weight the existing on-hand against the WAC if set, else the merchant-seeded
            // seed_cost (so a valuation seed blends into the *first* receipt instead of being
            // silently discarded). With neither, the receipt cost establishes the WAC.
            $priorCost = $existing?->weighted_avg_cost ?? $existing?->seed_cost;
            $before[$subjectId] = [$onHandBefore, $priorCost];

            $costedQty = $costedQtyBySubject[$subjectId] ?? 0;
            if ($costedQty <= 0) {
                continue; // no priced units this receipt → nothing to weight
            }
            $costedValue = $costedValueBySubject[$subjectId] ?? 0.0;

            $cost = $existing
                ?? SubjectCost::newWith(['subject_id' => $subjectId, 'cost_area_id' => $costAreaId]);
            $cost->weighted_avg_cost = WeightedAverage::blend($onHandBefore, $priorCost, $costedQty, $costedValue);
            // Ground the WAC in its currency (base). A cost amount without a currency silently changes
            // meaning if the store base ever changes; storing it makes that a detectable migration.
            $cost->cost_currency = $this->baseCurrency;
            $cost->updated_at = $now;
            $costs[] = $cost;
        }

        return [$costs, $before];
    }

    /**
     * Write each received product's on-hand before and weighted-against cost onto the receipt's
     * lines: one `UPDATE … SET col = CASE subject_id …` scoped to the receipt, never a statement per
     * product.
     *
     * @param array<int, array{0: int, 1: ?string}> $before
     */
    private static function stampSnapshot(int $receiptId, array $before): void
    {
        if ([] === $before) {
            return;
        }
        $onHandCase = '';
        $costCase = '';
        $onHandParams = [];
        $costParams = [];
        foreach ($before as $subjectId => [$onHand, $cost]) {
            $onHandCase .= ' WHEN ? THEN ?';
            $costCase .= ' WHEN ? THEN ?';
            array_push($onHandParams, $subjectId, $onHand);
            array_push($costParams, $subjectId, $cost);
        }

        ReceiptLine::updateWhere([
            'on_hand_before' => new RawSql('CASE subject_id'.$onHandCase.' END', $onHandParams),
            'cost_before'    => new RawSql('CASE subject_id'.$costCase.' END', $costParams),
        ], 'receipt_id = ?', [$receiptId]);
    }
}
