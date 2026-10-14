<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\Attrecord\LockSet;
use Nandan108\Attrecord\RecordSet;
use Nandan108\InvFlux\Application\Costing\CorrectedAverage;
use Nandan108\InvFlux\Application\Costing\WeightedAverage;
use Nandan108\InvFlux\Contracts\Inventory\InventoryStore;
use Nandan108\InvFlux\Contracts\Inventory\UnbackedClaimReader;
use Nandan108\InvFlux\Domain\Procurement\GoodsReceipt;
use Nandan108\InvFlux\Domain\Procurement\PoEvent;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\ReceiptCorrection;
use Nandan108\InvFlux\Domain\Procurement\ReceiptCorrectionLine;
use Nandan108\InvFlux\Domain\Procurement\ReceiptLine;
use Nandan108\InvFlux\Domain\Procurement\SubjectCost;
use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\Exceptions\ReceiptCorrectionException;
use Nandan108\InvFlux\Idempotency\IdempotencyKey;
use Nandan108\InvFlux\Idempotency\IdempotentOutcome;
use Nandan108\InvFlux\Mutation\ActorReference;
use Nandan108\InvFlux\Mutation\EntityReference;
use Nandan108\InvFlux\Mutation\StorageBatchFlowRequest;
use Nandan108\InvFlux\Schema\Extension\SlotSpaceAssembler;
use Nandan108\InvFlux\Schema\Extension\StockFlowEvent;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;

/**
 * Correct a posted goods receipt that was recorded wrong: a quantity, the good/damaged split, a unit
 * cost, or the whole receipt.
 *
 * The receipt is never edited. A {@see ReceiptCorrection} records each changed line before → after,
 * and in the same transaction the stock and the average cost move as the mistake requires:
 *
 * - **Good units taken out** drain free stock first, then live holds, then commitments — through the
 *   write-off cascade the `goods_receipt.corrected_down` event binds. A correction may leave orders
 *   short; that is the true state, reached in one step. Only units no longer on hand are refused:
 *   those left, and are a return or a write-off rather than a receipt mistake.
 * - **Good units brought in** go through the write-in cascade like a receipt, backing what is
 *   already claimed first.
 * - **The average** is corrected by {@see WeightedAverage::correct()}: the receipt's units still here
 *   are revalued, the moved units leave or arrive at the corrected cost, and what the stock left
 *   cannot absorb is recorded as a cost variance rather than forced into the average. Shipped units
 *   keep the cost they left at.
 * - **Damaged units** are a count kept outside stock, so changing them moves nothing; the ordered
 *   line's damaged count follows.
 * - **Ordered lines** move their received and damaged counts by the line's differences, and each order
 *   the receipt answers gets a `receipt.corrected` event on its timeline. The order's status does not
 *   move: a received order left with units open is reopened or closed out by the buyer's choice.
 *
 * Locks: SubjectCost (32) → PurchaseOrderLine (34) → GoodsReceipt (35), then the inventory state the
 * movements take. Locking the receipt serializes corrections to it, so each one plans from the
 * figures the last one left.
 *
 * Platform-agnostic: the adapter supplies the landing location, the acting user, and decides who may
 * correct and who may state a cost.
 *
 * @api
 */
final class CorrectReceipt
{
    public function __construct(
        private readonly InventoryStore $inventory,
        private readonly SlotSpaceAssembler $slotSpaceAssembler,
        private readonly string $baseCurrency,
        private readonly ?UnbackedClaimReader $unbackedClaims = null,
    ) {
    }

    /**
     * The correction worked out against stock and costs as they stand, writing nothing — for a
     * dialog showing each product's average now → after before anyone commits.
     *
     * @param list<ReceiptLineChange> $changes
     * @param non-empty-string        $location where the receipt landed
     *
     * @throws ReceiptCorrectionException
     */
    public function preview(int $receiptId, array $changes, string $location): PlannedReceiptCorrection
    {
        $receipt = GoodsReceipt::getOne($receiptId) ?? throw ReceiptCorrectionException::receiptNotFound($receiptId);
        $lines = self::receiptLines($receiptId);
        $subjectIds = self::subjectIds($lines);
        $costAreaId = $this->inventory->dimensionValueId('loc', $location);

        $costs = [];
        if ([] !== $subjectIds) {
            foreach (SubjectCost::whereInTuples(
                ['subject_id', 'cost_area_id'],
                array_map(static fn (int $id): array => [$id, $costAreaId], $subjectIds),
            ) as $cost) {
                $costs[$cost->subject_id] = $cost;
            }
        }

        return self::plan(
            $receipt,
            $lines,
            self::currentFigures($lines),
            $changes,
            $this->onHand($subjectIds),
            $costs,
        );
    }

    /**
     * Record the correction, move the stock and correct the average, in one transaction.
     *
     * @param list<ReceiptLineChange> $changes
     * @param non-empty-string        $location       where the receipt landed
     * @param IdempotencyKey|null     $idempotencyKey when given, a replay returns the correction it already recorded
     *
     * @throws ReceiptCorrectionException
     */
    public function __invoke(
        int $receiptId,
        array $changes,
        ActorReference $actor,
        string $location,
        ?int $correctedBy = null,
        ?string $note = null,
        ?IdempotencyKey $idempotencyKey = null,
    ): ReceiptCorrection {
        $receipt = GoodsReceipt::getOne($receiptId) ?? throw ReceiptCorrectionException::receiptNotFound($receiptId);
        // A posted receipt's lines never change, so they can be read before the locks; what can
        // change — the corrections to them — is read after.
        $lines = self::receiptLines($receiptId);
        $subjectIds = self::subjectIds($lines);
        $costAreaId = $this->inventory->dimensionValueId('loc', $location);

        $poLineKeys = [];
        foreach ($lines as $line) {
            if (null !== $line->po_id) {
                $poLineKeys[$line->po_id.':'.$line->subject_id] = ['po_id' => $line->po_id, 'subject_id' => $line->subject_id];
            }
        }
        $connection = PurchaseOrderLine::connection();

        $correct = function () use ($receipt, $receiptId, $lines, $subjectIds, $costAreaId, $poLineKeys, $connection, $changes, $actor, $location, $correctedBy, $note): ReceiptCorrection {
            $locks = LockSet::acquire($connection, [
                SubjectCost::class       => array_map(
                    static fn (int $id): array => ['subject_id' => $id, 'cost_area_id' => $costAreaId],
                    $subjectIds,
                ),
                PurchaseOrderLine::class => array_values($poLineKeys),
                GoodsReceipt::class      => [$receiptId],
            ]);

            /** @var array<int, SubjectCost> $costs */
            $costs = [];
            foreach ($locks[SubjectCost::class] as $cost) {
                /** @var SubjectCost $cost */
                $costs[$cost->subject_id] = $cost;
            }

            $onHandBefore = $this->onHand($subjectIds);
            $plan = self::plan($receipt, $lines, self::currentFigures($lines), $changes, $onHandBefore, $costs);

            $correction = ReceiptCorrection::newWith([
                'receipt_id'   => $receiptId,
                'corrected_by' => $correctedBy,
                'note'         => null === $note || '' === trim($note) ? null : $note,
            ])->save();
            $correctionId = (int) $correction->id;

            (new RecordSet(self::correctionLines($correctionId, $plan)))->upsertAll();

            $this->moveStock($plan, $correctionId, $actor, $location);
            $this->assertStockMovedAsPlanned($plan);

            $updatedCosts = $this->correctedCosts($plan, $costs, $costAreaId);
            if ([] !== $updatedCosts) {
                (new RecordSet($updatedCosts))->upsertAll();
            }

            $poLines = self::correctedPoLines($plan, $locks[PurchaseOrderLine::class]);
            if ([] !== $poLines) {
                (new RecordSet($poLines))->upsertAll();
            }
            foreach (self::orderEvents($plan, $correctionId, $correctedBy) as $event) {
                $event->save();
            }

            return $correction;
        };

        if (null === $idempotencyKey) {
            return $this->inventory->transactional($correct);
        }

        $saved = null;
        $execution = $this->inventory->executeIdempotent(
            $idempotencyKey,
            function () use ($correct, &$saved): IdempotentOutcome {
                $saved = $this->inventory->transactional($correct);

                return IdempotentOutcome::terminal('receipt_corrected', ['correctionId' => (int) $saved->id]);
            },
        );
        if (null !== $saved) {
            return $saved;
        }

        // Replayed: the correction this key already recorded is the answer, and nothing moved again.
        /** @psalm-var mixed $replayedId */
        $replayedId = $execution->payload['correctionId'] ?? null;

        return (\is_int($replayedId) ? ReceiptCorrection::getOne($replayedId) : null)
            ?? throw ReceiptCorrectionException::receiptNotFound($receiptId);
    }

    /**
     * Work a correction out, writing nothing. Pure over its inputs, so a preview and the commit plan
     * the same way.
     *
     * A line's cost in the store base is its unit cost times the receipt's rate. A line with no cost
     * counts at the product's average, which is what an uncosted unit carries: removing one leaves the
     * average alone, and stating a cost for it revalues it from the average.
     *
     * @param array<int, ReceiptLine>                                      $lines   keyed by id
     * @param array<int, array{qty: int, damaged: int, unitCost: ?string}> $current each line's figures after earlier corrections
     * @param list<ReceiptLineChange>                                      $changes
     * @param array<int, int>                                              $onHand  per product, total on hand now
     * @param array<int, SubjectCost>                                      $costs   per product
     *
     * @throws ReceiptCorrectionException
     */
    public static function plan(
        GoodsReceipt $receipt,
        array $lines,
        array $current,
        array $changes,
        array $onHand,
        array $costs,
    ): PlannedReceiptCorrection {
        $receiptId = (int) $receipt->id;
        $fxRate = null === $receipt->fx_rate ? 1.0 : (float) $receipt->fx_rate;

        $after = $current;
        $planned = [];
        foreach ($changes as $change) {
            $line = $lines[$change->receiptLineId] ?? throw ReceiptCorrectionException::unknownLine($receiptId, $change->receiptLineId);
            if ($change->qty < 0 || $change->damaged < 0 || (null !== $change->unitCost && (float) $change->unitCost < 0.0)) {
                throw ReceiptCorrectionException::negativeFigure($change->receiptLineId);
            }
            $before = $current[$change->receiptLineId];
            $unitCost = null === $change->unitCost ? null : number_format((float) $change->unitCost, 4, '.', '');
            $after[$change->receiptLineId] = ['qty' => $change->qty, 'damaged' => $change->damaged, 'unitCost' => $unitCost];
            if ($before['qty'] === $change->qty && $before['damaged'] === $change->damaged && $before['unitCost'] === $unitCost) {
                continue;
            }
            $planned[] = new PlannedLineChange(
                receiptLineId: $change->receiptLineId,
                subjectId: $line->subject_id,
                poId: $line->po_id,
                qtyBefore: $before['qty'],
                qtyAfter: $change->qty,
                damagedBefore: $before['damaged'],
                damagedAfter: $change->damaged,
                unitCostBefore: $before['unitCost'],
                unitCostAfter: $unitCost,
            );
        }
        if ([] === $planned) {
            throw ReceiptCorrectionException::nothingChanged($receiptId);
        }

        // Per product: the changed lines' good units and their value in base, before and after.
        $bySubject = [];
        foreach ($planned as $change) {
            $s = $change->subjectId;
            $cost = $costs[$s] ?? null;
            $average = $cost?->weighted_avg_cost ?? $cost?->seed_cost;
            $row = $bySubject[$s] ?? ['qty1' => 0, 'value1' => 0.0, 'qty2' => 0, 'value2' => 0.0, 'costed2' => 0, 'costedValue2' => 0.0, 'average' => $average];
            $row['qty1'] += $change->qtyBefore;
            $row['value1'] += (float) $change->qtyBefore * self::baseCost($change->unitCostBefore, $fxRate, $average);
            $row['qty2'] += $change->qtyAfter;
            $row['value2'] += (float) $change->qtyAfter * self::baseCost($change->unitCostAfter, $fxRate, $average);
            if (null !== $change->unitCostAfter) {
                $row['costed2'] += $change->qtyAfter;
                $row['costedValue2'] += (float) $change->qtyAfter * (float) $change->unitCostAfter * $fxRate;
            }
            $bySubject[$s] = $row;
        }

        $products = [];
        foreach ($bySubject as $subjectId => $row) {
            $delta = $row['qty2'] - $row['qty1'];
            $valueChanged = abs($row['value2'] - $row['value1']) > 0.00005;
            if (0 === $delta && !$valueChanged) {
                continue; // a damaged-count change only: nothing moves, nothing is revalued
            }
            $have = $onHand[$subjectId] ?? 0;
            if ($delta < 0 && -$delta > $have) {
                throw ReceiptCorrectionException::notOnHand($subjectId, -$delta, $have);
            }

            $average = $row['average'];
            if (null === $average) {
                // Nothing to correct an average from. A correction that states a cost for units the
                // product never had a cost for establishes one, as a first costed receipt would.
                $result = WeightedAverage::correct($have, null, $row['qty1'], 0.0, $row['qty2'], 0.0);
                if ($row['costed2'] > 0) {
                    $result = new CorrectedAverage(
                        $result->onHand,
                        WeightedAverage::blend(0, null, $row['costed2'], $row['costedValue2']),
                        0.0,
                        true,
                    );
                }
            } else {
                $costBefore = $row['qty1'] > 0 ? $row['value1'] / (float) $row['qty1'] : null;
                $costAfter = $row['qty2'] > 0 ? $row['value2'] / (float) $row['qty2'] : null;
                $result = WeightedAverage::correct(
                    $have,
                    $average,
                    $row['qty1'],
                    $costBefore ?? $costAfter ?? 0.0,
                    $row['qty2'],
                    $costAfter ?? $costBefore ?? 0.0,
                );
            }
            $products[$subjectId] = new ProductCorrectionEffect($subjectId, $have, $delta, $average, $result);
        }

        $reverses = true;
        foreach ($after as $figures) {
            if ($figures['qty'] > 0 || $figures['damaged'] > 0) {
                $reverses = false;
                break;
            }
        }

        return new PlannedReceiptCorrection($receiptId, $planned, $products, $reverses);
    }

    /** A line's unit cost in base, or the product's average standing in for a line with none. */
    private static function baseCost(?string $unitCost, float $fxRate, ?string $average): float
    {
        if (null !== $unitCost) {
            return (float) $unitCost * $fxRate;
        }

        return null === $average ? 0.0 : (float) $average;
    }

    /** @return array<int, ReceiptLine> keyed by id */
    private static function receiptLines(int $receiptId): array
    {
        $lines = [];
        foreach (ReceiptLine::where('receipt_id', $receiptId) as $line) {
            $lines[(int) $line->id] = $line;
        }

        return $lines;
    }

    /**
     * @param array<int, ReceiptLine> $lines
     *
     * @return list<int>
     */
    private static function subjectIds(array $lines): array
    {
        return array_values(array_unique(array_map(static fn (ReceiptLine $l): int => $l->subject_id, $lines)));
    }

    /**
     * Each line's figures as they stand: the receipt's own, or what the latest correction to the line
     * left. One read for the whole receipt.
     *
     * @param array<int, ReceiptLine> $lines keyed by id
     *
     * @return array<int, array{qty: int, damaged: int, unitCost: ?string}>
     */
    public static function currentFigures(array $lines): array
    {
        $figures = [];
        foreach ($lines as $id => $line) {
            $figures[$id] = ['qty' => $line->qty, 'damaged' => $line->damaged_qty, 'unitCost' => $line->unit_cost_snapshot];
        }
        if ([] === $lines) {
            return $figures;
        }

        $corrected = ReceiptCorrectionLine::find(
            'receipt_line_id IN ('.implode(',', array_fill(0, \count($lines), '?')).')',
            array_keys($lines),
            'ORDER BY id',
        );
        foreach ($corrected as $c) {
            $figures[$c->receipt_line_id] = ['qty' => $c->qty_after, 'damaged' => $c->damaged_after, 'unitCost' => $c->unit_cost_after];
        }

        return $figures;
    }

    /**
     * @param list<int> $subjectIds
     *
     * @return array<int, int>
     */
    private function onHand(array $subjectIds): array
    {
        if ([] === $subjectIds) {
            return [];
        }

        return $this->inventory->onHandTotalsForSubjects(
            array_map(static fn (int $id): SubjectId => new SubjectId($id), $subjectIds),
        );
    }

    /** @return list<ReceiptCorrectionLine> */
    private static function correctionLines(int $correctionId, PlannedReceiptCorrection $plan): array
    {
        $rows = [];
        $varianceWritten = [];
        foreach ($plan->lines as $change) {
            $effect = $plan->products[$change->subjectId] ?? null;
            $variance = 0.0;
            if (null !== $effect && !isset($varianceWritten[$change->subjectId])) {
                $variance = $effect->after->variance;
                $varianceWritten[$change->subjectId] = true;
            }
            $rows[] = ReceiptCorrectionLine::newWith([
                'correction_id'    => $correctionId,
                'receipt_line_id'  => $change->receiptLineId,
                'subject_id'       => $change->subjectId,
                'qty_before'       => $change->qtyBefore,
                'qty_after'        => $change->qtyAfter,
                'damaged_before'   => $change->damagedBefore,
                'damaged_after'    => $change->damagedAfter,
                'unit_cost_before' => $change->unitCostBefore,
                'unit_cost_after'  => $change->unitCostAfter,
                'on_hand_before'   => $effect?->onHandBefore ?? 0,
                'cost_before'      => $effect?->costBefore,
                'cost_after'       => $effect?->after->cost ?? $effect?->costBefore,
                'cost_variance'    => number_format($variance, 4, '.', ''),
            ]);
        }

        return $rows;
    }

    /**
     * One batch per direction: units out through the write-off cascade, units in through the
     * write-in cascade. The correction is the reference on every ledger row.
     *
     * @param non-empty-string $location
     */
    private function moveStock(PlannedReceiptCorrection $plan, int $correctionId, ActorReference $actor, string $location): void
    {
        $down = [];
        $up = [];
        foreach ($plan->products as $subjectId => $effect) {
            if ($effect->delta < 0) {
                $down[$subjectId] = -$effect->delta;
            } elseif ($effect->delta > 0) {
                $up[$subjectId] = $effect->delta;
            }
        }

        $reference = new EntityReference(ReceiptCorrection::REF_TYPE, $correctionId);
        foreach ([
            [StockFlowEvent::GOODS_RECEIPT_CORRECTED_DOWN, $down, []],
            [StockFlowEvent::GOODS_RECEIPT_CORRECTED_UP, $up, $this->unbackedClaimCaps(array_keys($up))],
        ] as [$event, $quantities, $context]) {
            if ([] === $quantities) {
                continue;
            }
            $binding = $this->slotSpaceAssembler->bindings()->resolve($event)
                ?? throw new \LogicException(sprintf('No stock-flow binding is registered for event "%s".', $event));
            $this->inventory->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
                movementTypeOwnerKey: $binding->movementTypeOwnerKey,
                movementTypeCode: $binding->movementTypeCode,
                flow: $binding->flow,
                subjectIds: array_map(static fn (int $id): SubjectId => new SubjectId($id), array_keys($quantities)),
                params: [SlotSpaceFactory::PARAM_LOCATION => $location],
                reference: $reference,
                actor: $actor,
                executionContext: $context,
                quantitiesBySubjectId: $quantities,
            ));
        }
    }

    /**
     * Refuse — rolling everything back — when a product's on-hand did not land where the plan put
     * it. Read after the movements, under the locks they took: a figure that moved between the
     * plan's read and those locks shows up here, and a write-off that found fewer units than it was
     * asked for (it drains what is there and stops) is caught rather than recorded as whole.
     */
    private function assertStockMovedAsPlanned(PlannedReceiptCorrection $plan): void
    {
        $moving = array_filter($plan->products, static fn (ProductCorrectionEffect $e): bool => 0 !== $e->delta);
        if ([] === $moving) {
            return;
        }
        $now = $this->onHand(array_keys($moving));
        foreach ($moving as $subjectId => $effect) {
            if (($now[$subjectId] ?? 0) !== $effect->onHandBefore + $effect->delta) {
                throw ReceiptCorrectionException::stockMoved($subjectId);
            }
        }
    }

    /**
     * @param array<int, SubjectCost> $costs locked rows, by product
     *
     * @return list<SubjectCost>
     */
    private function correctedCosts(PlannedReceiptCorrection $plan, array $costs, int $costAreaId): array
    {
        $now = new \DateTimeImmutable();
        $rows = [];
        foreach ($plan->products as $subjectId => $effect) {
            $cost = $effect->after->cost;
            if (null === $cost || $cost === ($costs[$subjectId] ?? null)?->weighted_avg_cost) {
                continue;
            }
            $row = $costs[$subjectId]
                ?? SubjectCost::newWith(['subject_id' => $subjectId, 'cost_area_id' => $costAreaId]);
            $row->weighted_avg_cost = $cost;
            $row->cost_currency = $this->baseCurrency;
            $row->updated_at = $now;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * One `receipt.corrected` event per order the changed lines answer, so the order's timeline shows
     * the correction and opens the receipt. Payload: `{receiptId, correctionId, reverses, lines:
     * [{subjectId, qty, damaged}]}`, each line's change in good and damaged units.
     *
     * @return list<PoEvent>
     */
    private static function orderEvents(PlannedReceiptCorrection $plan, int $correctionId, ?int $actorId): array
    {
        $byOrder = [];
        foreach ($plan->lines as $change) {
            if (null !== $change->poId) {
                $byOrder[$change->poId][] = [
                    'subjectId' => $change->subjectId,
                    'qty'       => $change->qtyDelta(),
                    'damaged'   => $change->damagedDelta(),
                ];
            }
        }

        $events = [];
        foreach ($byOrder as $poId => $lines) {
            $events[] = PoEvent::newWith([
                'po_id'      => $poId,
                'event_type' => PoEvent::TYPE_RECEIPT_CORRECTED,
                'actor_id'   => $actorId,
                'payload'    => json_encode([
                    'receiptId'    => $plan->receiptId,
                    'correctionId' => $correctionId,
                    'reverses'     => $plan->reverses,
                    'lines'        => $lines,
                ], JSON_THROW_ON_ERROR),
            ]);
        }

        return $events;
    }

    /**
     * @param iterable<mixed> $locked the ordered lines the lock returned
     *
     * @return list<PurchaseOrderLine>
     */
    private static function correctedPoLines(PlannedReceiptCorrection $plan, iterable $locked): array
    {
        $byKey = [];
        foreach ($locked as $poLine) {
            if (!$poLine instanceof PurchaseOrderLine) {
                continue;
            }
            $byKey[$poLine->po_id.':'.$poLine->subject_id] = $poLine;
        }

        $changed = [];
        foreach ($plan->lines as $change) {
            if (null === $change->poId) {
                continue;
            }
            $key = $change->poId.':'.$change->subjectId;
            $poLine = $byKey[$key] ?? null;
            if (null === $poLine || (0 === $change->qtyDelta() && 0 === $change->damagedDelta())) {
                continue;
            }
            $poLine->qty_received = max(0, $poLine->qty_received + $change->qtyDelta());
            $poLine->qty_damaged = max(0, $poLine->qty_damaged + $change->damagedDelta());
            $changed[$key] = $poLine;
        }

        return array_values($changed);
    }

    /**
     * The write-in cascade's caps for the products a correction brings units in for — the same
     * claims a receipt backs first.
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
}
