<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\InvFlux\Domain\Procurement\PoEvent;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;
use Nandan108\InvFlux\Domain\Procurement\SupplierInvoice;
use Nandan108\InvFlux\Domain\Procurement\SupplierInvoiceLine;
use Nandan108\InvFlux\Exceptions\InvalidSupplierInvoiceException;

/**
 * Record what a supplier charged, and carry the two consequences onto the ordered lines.
 *
 * **The two cached fields are maintained differently, and the difference is the design.**
 * `qty_invoiced` accumulates — "how many units have we been billed for" is a running total, so it is
 * re-summed from every invoice line against that ordered line. `unit_cost_invoiced` is replaced —
 * "what does a unit cost now" is a current rate, and the latest document is the answer.
 *
 * Replacing rather than averaging is what keeps a multi-delivery order correct without anyone doing
 * arithmetic. Each goods receipt freezes the rate standing when it is posted, and the subject's
 * weighted-average cost blends across those frozen snapshots. So a part delivery billed at one price
 * and the remainder at another value correctly on their own; a blended figure on the order line
 * would value *both* deliveries at an average neither was bought at.
 *
 * **It records; it does not post.** No stock moves, no purchase-order status changes, no payable
 * opens. The order's own lifecycle cannot be reached from here at all — deliberately, because
 * financial facts are a projection of operational ones and never drive them.
 */
final class RecordSupplierInvoice
{
    public const EVENT_RECORDED = 'po.invoice_recorded';

    public function __construct(private readonly PurchaseOrderRepository $purchaseOrders)
    {
    }

    /**
     * The invoice arrives already valid — {@see SupplierInvoice} refuses to exist without a
     * reference and a currency, so defaulting the currency to the order's is the caller's job and
     * happens before construction. Nothing is invented here.
     *
     * @param list<SupplierInvoiceLine> $lines each carrying `po_line_id`, `qty` and `unit_cost`
     *
     * @throws InvalidSupplierInvoiceException when a line bills something this order does not contain
     */
    public function __invoke(
        PurchaseOrder $po,
        SupplierInvoice $invoice,
        array $lines,
        ?int $actorId = null,
    ): SupplierInvoice {
        if ([] === $lines) {
            throw InvalidSupplierInvoiceException::empty();
        }

        $invoice->po_id = (int) $po->id;
        $invoice->recorded_at = new \DateTimeImmutable();
        $invoice->recorded_by = $actorId;

        $orderLines = [];
        foreach ($this->purchaseOrders->linesForPurchaseOrder((int) $po->id) as $line) {
            $orderLines[(int) $line->id] = $line;
        }

        $billed = [];
        foreach ($lines as $line) {
            if (!isset($orderLines[$line->po_line_id])) {
                // Billing a line this order does not have is a dispute, not a line we can value: no
                // ordered line to attach the rate to, and no receipt that will consume it. Refused
                // here rather than half-recorded; the Pro match is what has somewhere to put it.
                throw InvalidSupplierInvoiceException::lineNotOnOrder($line->po_line_id);
            }
            $billed[$line->po_line_id][] = $line;
        }

        $priorLines = $this->purchaseOrders->supplierInvoiceLinesForOrderLines(array_keys($billed));

        $touched = [];
        foreach ($billed as $poLineId => $newLines) {
            $orderLine = $orderLines[$poLineId];
            $touched[] = $this->applyTo($orderLine, $newLines, $priorLines[$poLineId] ?? []);
        }

        $saved = $this->purchaseOrders->recordSupplierInvoice($invoice, $lines, $touched);

        $this->purchaseOrders->recordPoEvent(PoEvent::newWith([
            'po_id'      => (int) $po->id,
            'event_type' => self::EVENT_RECORDED,
            'actor_id'   => $actorId,
            'note'       => $invoice->reference,
            'payload'    => json_encode([
                'invoiceId' => $saved->id,
                'lineCount' => \count($lines),
                'currency'  => $invoice->currency,
            ], JSON_THROW_ON_ERROR),
        ]));

        return $saved;
    }

    /**
     * Fold one ordered line's newly-billed lines into its cached fields.
     *
     * The quantity is re-summed over prior *and* new documents rather than incremented, so the cache
     * is always a function of the invoices that justify it — an incremented counter drifts the moment
     * one is corrected or removed, and drifts silently.
     *
     * @param non-empty-list<SupplierInvoiceLine> $newLines
     * @param list<SupplierInvoiceLine>           $priorLines
     */
    private function applyTo(
        PurchaseOrderLine $orderLine,
        array $newLines,
        array $priorLines,
    ): PurchaseOrderLine {
        $qty = 0;
        foreach ([...$priorLines, ...$newLines] as $line) {
            $qty += $line->qty;
        }
        $orderLine->qty_invoiced = $qty;

        // The latest rate wins. Where one invoice bills a line twice — a correction inside the same
        // document — the last line stated is the one the supplier means.
        $latest = $newLines[array_key_last($newLines)];
        $orderLine->unit_cost_invoiced = $latest->unit_cost;

        return $orderLine;
    }
}
