<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * A correction to a posted goods receipt that cannot be recorded as asked.
 *
 * Each refusal carries a machine `code` and the figures a caller needs to say why in its own words —
 * which product, how many were asked for, how many are still here — rather than parsing a message.
 *
 * @api
 */
final class ReceiptCorrectionException extends InvFluxException
{
    public const RECEIPT_NOT_FOUND = 'receipt_not_found';
    public const UNKNOWN_LINE = 'unknown_line';
    public const NEGATIVE_FIGURE = 'negative_figure';
    public const NOTHING_CHANGED = 'nothing_changed';
    public const NOT_ON_HAND = 'not_on_hand';
    public const STOCK_MOVED = 'stock_moved';

    private function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly ?int $subjectId = null,
        public readonly ?int $requested = null,
        public readonly ?int $available = null,
    ) {
        parent::__construct($message);
    }

    public static function receiptNotFound(int $receiptId): self
    {
        return new self(self::RECEIPT_NOT_FOUND, sprintf('Goods receipt %d does not exist.', $receiptId));
    }

    public static function unknownLine(int $receiptId, int $lineId): self
    {
        return new self(self::UNKNOWN_LINE, sprintf('Line %d is not a line of goods receipt %d.', $lineId, $receiptId));
    }

    public static function negativeFigure(int $lineId): self
    {
        return new self(self::NEGATIVE_FIGURE, sprintf('Receipt line %d: quantities and unit cost cannot be negative.', $lineId));
    }

    public static function nothingChanged(int $receiptId): self
    {
        return new self(self::NOTHING_CHANGED, sprintf('The correction to goods receipt %d changes nothing.', $receiptId));
    }

    /**
     * More units would be taken out than the product has on hand: the rest have left (shipped, or
     * gone some other way), and units that left are a return or a write-off, not a receipt mistake.
     */
    public static function notOnHand(int $subjectId, int $requested, int $available): self
    {
        return new self(
            self::NOT_ON_HAND,
            sprintf('Only %d of the %d units to remove for subject %d are still on hand.', max($available, 0), $requested, $subjectId),
            $subjectId,
            $requested,
            $available,
        );
    }

    /**
     * Stock changed between the read the correction was planned on and its movement. Nothing was
     * recorded; planning again from fresh figures is the answer.
     */
    public static function stockMoved(int $subjectId): self
    {
        return new self(
            self::STOCK_MOVED,
            sprintf('Stock for subject %d changed while the correction was being recorded; nothing was recorded.', $subjectId),
            $subjectId,
        );
    }
}
