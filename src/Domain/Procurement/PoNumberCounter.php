<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;

/**
 * Per-series PO-number counter (table `invflux_po_number_counters`), keyed by
 * `series_key`. Multi-series from day one; Essentials uses the single series `'default'`, Pro
 * adds date-reset / per-supplier / per-warehouse series.
 *
 * The repository's PO-create flow `SELECT … FOR UPDATE`s this row inside the create txn,
 * formats the number via the active {@see PoNumberScheme}, then increments `next_value`
 * (and stamps `updated_at`).
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_po_number_counters', primaryKey: 'series_key')]
final class PoNumberCounter extends Record
{
    public const DEFAULT_SERIES = 'default';

    #[Column(ColumnType::VarChar, length: 64)]
    public string $series_key = self::DEFAULT_SERIES;

    #[Column(ColumnType::IntUnsigned, default: 1)]
    public int $next_value = 1;

    /** Writer-set (the increment txn stamps it); nullable — see [[attrecord-provided-pk-insert]]. */
    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $updated_at = null;

    #[\Override]
    public function validate(): void
    {
        if ('' === trim($this->series_key)) {
            throw new RecordValidationException(
                'PoNumberCounter.series_key must be a non-empty string.',
                ['field' => 'series_key'],
            );
        }
    }
}
