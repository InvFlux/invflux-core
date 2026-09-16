<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Domain\Shipment\Shipment;
use Nandan108\InvFlux\Identity\RecordIdentity;

/**
 * One payment received against a customer order — how much, in what currency, by what method,
 * when, and on what reference.
 *
 * **A record, never a stock state.** Stock reacts to an order's status, not to money. What a payment
 * decides — whether the order is paid — reaches the stock only through the status the order is then
 * given.
 *
 * **Voided, never deleted.** A payment recorded by mistake is voided: {@see void()} writes who voided
 * it, when and why, and those three fields are the only ones written after the payment is recorded.
 * The payments of an order, voided ones included, are its payment history.
 *
 * **Where it came from** is {@see $source}: a registered value ({@see PaymentSources}), so an add-on
 * that collects money through a channel of its own records under its own source.
 *
 * **Two currencies.** {@see $amount} is what arrived, in {@see $currency}. When that is not the
 * order's currency, {@see $fx_rate} converts it, and {@see $amount_order_ccy} — the figure every sum
 * reads — is derived from the two on save; with no rate it equals the amount.
 *
 * **Lock tier 48** — a sibling of {@see OrderCharge} (47) under the same order.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_order_payments')]
#[LockTier(48)]
final class OrderPayment extends Record
{
    /** 16-byte binary UUIDv7 — minted on save via RecordIdentity. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    /** 16-byte binary UUIDv7 FK to invflux_orders.id. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $order_id = null;

    /** What arrived, in {@see $currency}. Always positive: money going back out is a refund. */
    #[Column(ColumnType::Decimal, precision: 10, scale: 2, default: '0.00')]
    public string $amount = '0.00';

    /** ISO 4217 code of {@see $amount}. */
    #[Column(ColumnType::VarChar, length: 3, default: '')]
    public string $currency = '';

    /**
     * Units of the order's currency per one unit of {@see $currency}, entered when the two differ;
     * null when they do not.
     */
    #[Column(ColumnType::Decimal, precision: 18, scale: 8, nullable: true)]
    public ?string $fx_rate = null;

    /** The amount in the order's currency — derived on save from {@see $amount} and {@see $fx_rate}. */
    #[Column(ColumnType::Decimal, precision: 10, scale: 2, default: '0.00')]
    public string $amount_order_ccy = '0.00';

    /** The payment method: the gateway's id in the source system, or a code for a manual method. */
    #[Column(ColumnType::VarChar, length: 64, default: '')]
    public string $method = '';

    /** Where the record came from — a code registered with {@see PaymentSources}. */
    #[Column(ColumnType::VarChar, length: PaymentSources::MAX_LENGTH, default: '')]
    public string $source = '';

    /**
     * The shipment the payment was collected against, when collection is per parcel (cash on
     * delivery). Null for a payment against the order as a whole.
     */
    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    public ?string $shipment_id = null;

    /** The gateway's transaction id, or the transfer, cheque or remittance reference. */
    #[Column(ColumnType::VarChar, length: 191, nullable: true)]
    public ?string $transaction_ref = null;

    /** When the money arrived — as the gateway reports it, or as the operator entered it. */
    #[Column(ColumnType::DateTime, nullable: true, precision: 6)]
    public ?\DateTimeImmutable $received_at = null;

    /**
     * What this payment settled beyond what arrived, in the order's currency: positive when less
     * arrived than was due and the rest was accepted as settled, negative when more arrived. Null
     * when the payment was exact. A difference always carries {@see $difference_reason}.
     */
    #[Column(ColumnType::Decimal, precision: 10, scale: 2, nullable: true)]
    public ?string $difference = null;

    /** Why a difference was accepted — `bank_fee`, `rounding`, `fx`, … */
    #[Column(ColumnType::VarChar, length: 32, nullable: true)]
    public ?string $difference_reason = null;

    /** Actor id of whoever recorded it; 0 for the system. */
    #[Column(ColumnType::BigIntUnsigned, default: 0)]
    public int $recorded_by = 0;

    #[Column(ColumnType::DateTime, nullable: true, precision: 6)]
    public ?\DateTimeImmutable $recorded_at = null;

    #[Column(ColumnType::Text, nullable: true)]
    public ?string $note = null;

    #[Column(ColumnType::DateTime, nullable: true, precision: 6)]
    public ?\DateTimeImmutable $voided_at = null;

    /** Actor id of whoever voided it. */
    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $voided_by = null;

    #[Column(ColumnType::VarChar, length: 500, nullable: true)]
    public ?string $void_reason = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Order::class,
        foreignKey: 'order_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?Order $order = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Shipment::class,
        foreignKey: 'shipment_id',
        onDelete: ForeignKeyAction::SetNull,
    )]
    public ?Shipment $shipment = null;

    public function isVoided(): bool
    {
        return null !== $this->voided_at;
    }

    /**
     * Void the payment: it stops counting towards what the order has been paid, and stays on record.
     *
     * @throws RecordValidationException when no reason is given
     * @throws \LogicException           when the payment is already voided
     */
    public function void(int $actorId, string $reason, \DateTimeImmutable $at): void
    {
        if ($this->isVoided()) {
            throw new \LogicException('This payment is already voided.');
        }

        $reason = trim($reason);
        if ('' === $reason) {
            throw new RecordValidationException('OrderPayment.void_reason must say why the payment is voided.', ['field' => 'void_reason']);
        }

        $this->voided_at = $at;
        $this->voided_by = $actorId;
        $this->void_reason = $reason;
    }

    /** {@see $amount} in the order's currency: converted at {@see $fx_rate} when there is one. */
    public function convertedAmount(): string
    {
        $cents = self::toCents($this->amount);
        if (null !== $this->fx_rate) {
            $cents = (int) round((float) $cents * (float) $this->fx_rate);
        }

        return self::fromCents($cents);
    }

    /**
     * What the given payments add up to in the order's currency, voided ones left out.
     *
     * @param list<OrderPayment> $payments
     */
    public static function paidTotal(array $payments): string
    {
        $cents = 0;
        foreach ($payments as $payment) {
            if (!$payment->isVoided()) {
                $cents += self::toCents($payment->amount_order_ccy);
            }
        }

        return self::fromCents($cents);
    }

    /**
     * How much of the order this payment settles, in the order's currency: what arrived, plus a
     * shortfall accepted as settled, less an excess ({@see $difference}).
     */
    public function settledAmount(): string
    {
        return self::fromCents(self::toCents($this->amount_order_ccy) + self::toCents($this->difference ?? '0'));
    }

    /**
     * How much of the order the given payments settle, voided ones left out — what an order still
     * owes is its total less this, not less {@see paidTotal()}: a bank's fee accepted as settled is
     * owed by nobody.
     *
     * @param list<OrderPayment> $payments
     */
    public static function settledTotal(array $payments): string
    {
        $cents = 0;
        foreach ($payments as $payment) {
            if (!$payment->isVoided()) {
                $cents += self::toCents($payment->settledAmount());
            }
        }

        return self::fromCents($cents);
    }

    #[\Override]
    public function beforeSave(): void
    {
        if (null === $this->id) {
            $this->id = RecordIdentity::mintId();
        }
        if (null === $this->recorded_at) {
            $this->recorded_at = new \DateTimeImmutable();
        }
        $this->amount_order_ccy = $this->convertedAmount();
    }

    #[\Override]
    public function validate(): void
    {
        if (null === $this->order_id || 16 !== \strlen($this->order_id)) {
            throw new RecordValidationException(
                'OrderPayment.order_id must be a 16-byte binary UUIDv7 referencing invflux_orders.id.',
                ['field' => 'order_id'],
            );
        }

        if (!is_numeric($this->amount) || (float) $this->amount <= 0.0) {
            throw new RecordValidationException(
                \sprintf('OrderPayment.amount must be a positive decimal amount, got "%s".', $this->amount),
                ['field' => 'amount'],
            );
        }

        if (1 !== preg_match('/^[A-Z]{3}$/', $this->currency)) {
            throw new RecordValidationException(
                \sprintf('OrderPayment.currency must be an ISO 4217 code, got "%s".', $this->currency),
                ['field' => 'currency'],
            );
        }

        if (null !== $this->fx_rate && (!is_numeric($this->fx_rate) || (float) $this->fx_rate <= 0.0)) {
            throw new RecordValidationException(
                \sprintf('OrderPayment.fx_rate must be a positive rate, got "%s".', $this->fx_rate),
                ['field' => 'fx_rate'],
            );
        }

        if ('' === $this->method) {
            throw new RecordValidationException('OrderPayment.method must name the payment method.', ['field' => 'method']);
        }

        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $this->source)) {
            throw new RecordValidationException(
                \sprintf('OrderPayment.source must be a registered payment source, got "%s".', $this->source),
                ['field' => 'source'],
            );
        }

        if (null !== $this->shipment_id && 16 !== \strlen($this->shipment_id)) {
            throw new RecordValidationException(
                'OrderPayment.shipment_id must be a 16-byte binary UUIDv7 referencing invflux_shipments.id.',
                ['field' => 'shipment_id'],
            );
        }

        if (null === $this->received_at) {
            throw new RecordValidationException('OrderPayment.received_at must say when the money arrived.', ['field' => 'received_at']);
        }

        if (null !== $this->difference) {
            if (!is_numeric($this->difference)) {
                throw new RecordValidationException(
                    \sprintf('OrderPayment.difference must be a decimal amount, got "%s".', $this->difference),
                    ['field' => 'difference'],
                );
            }
            if (0 !== self::toCents($this->difference) && (null === $this->difference_reason || '' === $this->difference_reason)) {
                throw new RecordValidationException(
                    'OrderPayment.difference_reason must say why a payment difference was accepted.',
                    ['field' => 'difference_reason'],
                );
            }
        }

        $voidFields = [null !== $this->voided_at, null !== $this->voided_by, null !== $this->void_reason && '' !== $this->void_reason];
        if (\in_array(true, $voidFields, true) && \in_array(false, $voidFields, true)) {
            throw new RecordValidationException(
                'OrderPayment.voided_at, voided_by and void_reason are set together or not at all.',
                ['field' => 'voided_at'],
            );
        }
    }

    private static function toCents(string $amount): int
    {
        return (int) round((float) $amount * 100.0);
    }

    private static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
