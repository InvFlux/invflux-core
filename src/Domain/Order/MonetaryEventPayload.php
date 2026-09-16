<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\Attrecord\JsonCastable;

/**
 * Canonical payload shape for {@see OrderEvent} rows whose `event_type` is monetary
 * (per {@see OrderEventType::isMonetary()}).
 *
 * Captures both the source amount (what the merchant or customer sees) and the
 * normalised "base" amount (the install's reporting currency) along with the FX rate
 * used at event-write-time. The base fields are populated unconditionally —
 * including the trivial `base_currency === currency` case — so historical
 * reconstruction can rely on them without inferring intent — one of the
 * must-not-defer write-time foundations.
 *
 * `extras` is a free-form map for event-type-specific keys (e.g.
 * `correction_ids`, `wc_refund_id`, `reason`, `error`, `line_items`). Keeping them
 * inside the VO rather than alongside it lets the polymorphic
 * {@see OrderEventPayloadCaster} return a single typed value per row.
 *
 * @api
 */
final class MonetaryEventPayload implements JsonCastable
{
    /**
     * @param string               $amount        DECIMAL-as-string in the event's source currency (e.g. "10.00")
     * @param string               $currency      ISO 4217 alpha-3, e.g. "USD". Must be exactly 3 chars.
     * @param string               $base_amount   DECIMAL-as-string in the install's reporting currency
     * @param string               $base_currency ISO 4217 alpha-3 of the install's reporting currency
     * @param string               $fx_rate_used  DECIMAL-as-string rate applied to convert `amount` → `base_amount` (e.g. "1.00000000")
     * @param array<string, mixed> $extras        Event-type-specific keys
     */
    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $base_amount,
        public readonly string $base_currency,
        public readonly string $fx_rate_used,
        public readonly array $extras = [],
    ) {
        if (3 !== \strlen($currency)) {
            throw new \InvalidArgumentException(\sprintf(
                'MonetaryEventPayload.currency must be ISO 4217 alpha-3 (3 chars); got %d (%s).',
                \strlen($currency),
                $currency,
            ));
        }
        if (3 !== \strlen($base_currency)) {
            throw new \InvalidArgumentException(\sprintf(
                'MonetaryEventPayload.base_currency must be ISO 4217 alpha-3 (3 chars); got %d (%s).',
                \strlen($base_currency),
                $base_currency,
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'amount'        => $this->amount,
            'currency'      => $this->currency,
            'base_amount'   => $this->base_amount,
            'base_currency' => $this->base_currency,
            'fx_rate_used'  => $this->fx_rate_used,
            'extras'        => (object) $this->extras,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[\Override]
    public static function fromJson(array $data): static
    {
        /** @psalm-var mixed $extras */
        $extras = $data['extras'] ?? [];
        /** @var array<string, mixed> $extrasArr */
        $extrasArr = \is_array($extras) ? $extras : [];

        return new self(
            amount: (string) ($data['amount'] ?? ''),
            currency: (string) ($data['currency'] ?? ''),
            base_amount: (string) ($data['base_amount'] ?? ''),
            base_currency: (string) ($data['base_currency'] ?? ''),
            fx_rate_used: (string) ($data['fx_rate_used'] ?? ''),
            extras: $extrasArr,
        );
    }
}
