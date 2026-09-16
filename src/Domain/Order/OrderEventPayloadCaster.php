<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\Attrecord\Attribute\Cast;
use Nandan108\Attrecord\Schema\ColumnDefinition;

/**
 * Discriminated payload caster for {@see OrderEvent}.
 *
 * `OrderEvent.payload` is polymorphic by `event_type`: monetary event types
 * (per {@see OrderEventType::isMonetary()}) carry a {@see MonetaryEventPayload}
 * value-object; non-monetary event types carry a free-form associative array.
 *
 * On hydrate (`fromDb`), the caster reads the sibling `event_type` column from
 * `$row` and dispatches accordingly. On write (`toDb`), the caster JSON-encodes
 * whatever the property holds — the value already knows its own shape (the VO via
 * `jsonSerialize()`, plain arrays directly).
 *
 * This is the documented attrecord "discriminated payload" custom-caster
 * pattern.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class OrderEventPayloadCaster extends Cast
{
    /** @throws \JsonException */
    #[\Override]
    public function fromDb(mixed $raw, array $row, ColumnDefinition $col): mixed
    {
        /** @psalm-var mixed $decoded */
        $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return $decoded;
        }

        $eventType = $row['event_type'] ?? null;
        $type = \is_string($eventType) ? OrderEventType::tryFrom($eventType) : null;
        if (null !== $type && $type->isMonetary()) {
            return MonetaryEventPayload::fromJson($decoded);
        }

        return $decoded;
    }

    /** @throws \JsonException */
    #[\Override]
    public function toDb(mixed $value, ColumnDefinition $col): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
