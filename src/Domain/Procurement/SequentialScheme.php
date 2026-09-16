<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * The Essentials PO-number scheme: `{prefix}{seq}` from a single series, with a
 * settings-configured prefix, start value, and optional zero-padding. The only scheme
 * registered/selectable at Essentials tier.
 *
 * @api
 */
final class SequentialScheme implements PoNumberScheme
{
    public function __construct(
        private readonly string $prefix = '',
        private readonly int $startValue = 1,
        private readonly int $padding = 0,
        private readonly string $series = PoNumberCounter::DEFAULT_SERIES,
    ) {
    }

    #[\Override]
    public function seriesKey(): string
    {
        return $this->series;
    }

    #[\Override]
    public function startValue(): int
    {
        return $this->startValue;
    }

    #[\Override]
    public function format(int $sequence): string
    {
        $seq = $this->padding > 0
            ? str_pad((string) $sequence, $this->padding, '0', STR_PAD_LEFT)
            : (string) $sequence;

        return $this->prefix.$seq;
    }
}
