<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\Attrecord\LockSet;
use Nandan108\Attrecord\RecordSet;
use Nandan108\InvFlux\Contracts\Inventory\InventoryStore;
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
 * afterwards by the batch movement, honouring the domain-first / inventory-second
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
    ) {
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

        // Null on a source-less intake, which counts against no ordered line — filtered out rather
        // than defaulted, so an empty list means "no ordered lines to touch" and nothing else.
        $poLineIds = array_values(array_unique(array_filter(array_map(
            static fn (ReceiptLine $line): ?int => $line->po_line_id,
            $lines,
        ), static fn (?int $id): bool => null !== $id)));

        // Every subject with units actually arriving. Drives the lock set, the movement and the WAC;
        // qty<=0 lines carry no movement and so no lock either.
        $subjectIds = array_values(array_unique(array_map(
            static fn (ReceiptLine $line): int => $line->subject_id,
            array_filter($lines, static fn (ReceiptLine $line): bool => $line->qty > 0),
        )));

        // The connection every Record + the InventoryStore are bound to at bootstrap — its
        // session owns the transaction opened just below, so LockSet's locks belong to it.
        $connection = PurchaseOrderLine::connection();

        $receive = function () use (
            $receipt,
            $lines,
            $actor,
            $location,
            $fxRate,
            $poLineIds,
            $subjectIds,
            $connection,
        ): GoodsReceipt {
            // 1. Domain-entity locks, tier-ordered by LockSet: SubjectCost (32) →
            //    PurchaseOrder (33) → PurchaseOrderLine (34). Inventory-state locks come
            //    later (step 4). A SubjectCost row that doesn't exist yet (first receipt of
            //    a subject) is simply not locked — it's created in step 5.
            //
            //    A source-less intake locks neither procurement tier: it answers no order, so
            //    there are no ordered lines to bump and no order to transition. Both tiers are
            //    still *declared* with an empty list rather than dropped from the set — LockSet
            //    skips an empty target without a query, and declaring them keeps one acquisition
            //    order for every caller, which is the property the tier ordering exists to give.
            //
            //    The order to lock is the receipt's source, and `$poLineIds` is what says whether
            //    to: a receipt line references an ordered line only when the receipt answers an
            //    order, so a non-empty list means `source_id` is a purchase-order id. That
            //    invariant is what lets this read the id without resolving the ref type — and it
            //    is why an add-on's own source (an advance shipping notice, whose lines reference
            //    its own) locks nothing here and takes its own locks in its own tier.
            $locks = LockSet::acquire($connection, [
                SubjectCost::class       => $subjectIds,
                PurchaseOrder::class     => [] === $poLineIds ? [] : [(int) $receipt->source_id],
                PurchaseOrderLine::class => $poLineIds,
            ]);

            /** @var array<int, SubjectCost> $existingCosts */
            $existingCosts = [];
            foreach ($locks[SubjectCost::class] as $cost) {
                /** @var SubjectCost $cost */
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

            // 4. Post the write-in for every received subject in ONE batch flow
            //    (`nil → {warehouse}.atp`, ref = the goods_receipt). `write_in` is all-create, so
            //    executeBatchFlowFromStorage runs it without reading prior state. The receiving
            //    warehouse rides in as the flow's `location` param; no `deficit` is supplied, so the
            //    cascade's ctd step caps at 0 and the whole flow collapses to `create(atp)`.
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
                    quantitiesBySubjectId: $receivedQtyBySubject,
                ));
            }

            // 5. Recompute WAC (0g) for every costed subject in ONE batch read + ONE bulk
            //    save. On-hand is read AFTER the movement — under the inventory-state locks
            //    just taken — and the pre-receipt on-hand derived by subtracting the
            //    received units, so the weight is race-free without a second lock tier.
            $costs = $this->recomputeWeightedAverageCosts(
                $subjectIds,
                $receivedQtyBySubject,
                $costedQtyBySubject,
                $costedValueBySubject,
                $existingCosts,
            );
            if ([] !== $costs) {
                (new RecordSet($costs))->upsertAll();
            }

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
     * Refuse a source-less intake whose reason cannot be honoured — before anything is locked.
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
     * @param list<ReceiptLine> $lines
     *
     * @return array{array<int, int>, array<int, int>, array<int, float>}
     */
    private static function aggregateBySubject(array $lines, float $fxRate): array
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
     * @param array<int, int>         $receivedQtyBySubject
     * @param array<int, int>         $costedQtyBySubject
     * @param array<int, float>       $costedValueBySubject
     * @param array<int, SubjectCost> $existingCosts
     *
     * @return list<SubjectCost>
     */
    private function recomputeWeightedAverageCosts(
        array $subjectIds,
        array $receivedQtyBySubject,
        array $costedQtyBySubject,
        array $costedValueBySubject,
        array $existingCosts,
    ): array {
        $subjectIdObjs = array_map(static fn (int $id): SubjectId => new SubjectId($id), $subjectIds);
        $onHandAfter = $this->inventory->onHandTotalsForSubjects($subjectIdObjs);

        $now = new \DateTimeImmutable();
        $costs = [];
        foreach ($subjectIds as $subjectId) {
            $costedQty = $costedQtyBySubject[$subjectId] ?? 0;
            if ($costedQty <= 0) {
                continue; // no priced units this receipt → nothing to weight
            }
            $costedValue = $costedValueBySubject[$subjectId] ?? 0.0;
            $receivedQty = $receivedQtyBySubject[$subjectId] ?? 0;
            $onHandBefore = max(0, ($onHandAfter[$subjectId] ?? $receivedQty) - $receivedQty);

            $cost = $existingCosts[$subjectId] ?? SubjectCost::newWith(['subject_id' => $subjectId]);
            // Weight the existing on-hand against the WAC if set, else the merchant-seeded
            // seed_cost (so a valuation seed blends into the *first* receipt instead of being
            // silently discarded). With neither, the receipt cost establishes the WAC.
            $priorCost = $cost->weighted_avg_cost ?? $cost->seed_cost;
            $cost->weighted_avg_cost = self::weightedAverageCost($onHandBefore, $priorCost, $costedQty, $costedValue);
            // Ground the WAC in its currency (base). A cost amount without a currency silently changes
            // meaning if the store base ever changes; storing it makes that a detectable migration.
            $cost->cost_currency = $this->baseCurrency;
            $cost->updated_at = $now;
            $costs[] = $cost;
        }

        return $costs;
    }

    /**
     * Weighted-average cost (4-decimal string) after a costed receipt:
     * `WAC = (onHandBefore·priorCost + costedValue) / (onHandBefore + costedQty)`.
     *
     * `$priorCost` is the cost to weight the prior on-hand against (the live WAC, or a seeded
     * seed_cost baseline). With no prior cost or no prior stock to weight against, the receipt's
     * own unit cost (`costedValue / costedQty`) establishes the WAC. `$costedQty` must be > 0 (the
     * caller skips uncosted subjects). Float math + 4-decimal formatting (the codebase has no bcmath);
     * good enough for Essentials valuation, revisit if exact decimal accumulation is needed.
     */
    public static function weightedAverageCost(int $onHandBefore, ?string $priorCost, int $costedQty, float $costedValue): string
    {
        if (null === $priorCost || $onHandBefore <= 0) {
            return number_format($costedValue / (float) $costedQty, 4, '.', '');
        }

        $weighted = ((float) $onHandBefore * (float) $priorCost + $costedValue)
            / (float) ($onHandBefore + $costedQty);

        return number_format($weighted, 4, '.', '');
    }
}
