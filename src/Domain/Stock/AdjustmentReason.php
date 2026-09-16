<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Stock;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Configurable **reason-code catalog** for stock adjustments — a merchant can add their own
 * reason vocabulary without a schema change, while the **GL/export mapping never breaks**: every
 * code — seeded or merchant-added — **must pin to one fixed core {@see self::GL_CLASSES}** value.
 *
 * Why a catalog (not an enum): an enum is core-fixed, so merchants couldn't extend it, and an
 * enum-*column* → catalog-*FK* is a real migration once installs exist — so it's done at greenfield.
 * See docs/arch-erp-parity.
 *
 * `gl_class` is where the adjustment **GL treatment** lives (permanently): adjustments all share one
 * slot cascade across many financial outcomes, so GL is orthogonal to the movement type and belongs on
 * the reason — the deliberate exception to "movement_type ↔ one GL treatment". See
 * docs/arch-slot-dimensions.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 * @psalm-suppress UnusedClass Persisted via attrecord by the adapter + table emitted + seeded by
 *                             storage's install — both outside core's analysis scope.
 */
#[Table(name: 'invflux_adjustment_reasons')]
final class AdjustmentReason extends Record
{
    /**
     * The **fixed** core GL-treatment classes a reason may pin to. Merchant codes are free-form
     * *labels*, but every one resolves to exactly one of these — so the accounting export maps a
     * reason → GL account without core knowing the merchant's custom codes:
     *
     * - `shrinkage_loss` — unexplained loss (theft / miscount down) → shrinkage expense
     * - `scrap_writeoff` — damaged / unfit → scrap / obsolescence
     * - `deliberate_writeoff` — intentional removal (demo, internal use, charity) / catch-all out
     * - `gain` — found / recount-up / catch-all in → inventory gain
     * - `opening` — initial stock upload / opening balance
     */
    public const GL_CLASSES = ['shrinkage_loss', 'scrap_writeoff', 'deliberate_writeoff', 'gain', 'opening'];

    /** Delta sign this reason applies to: `-1` = write-off (stock leaves), `+1` = write-in (stock appears). */
    public const SIGN_NEGATIVE = -1;
    public const SIGN_POSITIVE = 1;

    #[Column(ColumnType::SmallIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /** Stable machine code (e.g. `missing`, `damaged`, `found`). Merchant codes join the same namespace. */
    #[Column(ColumnType::VarChar, length: 32)]
    #[UniqueKey('uniq_adjustment_reason_code')]
    public string $code = '';

    /** Human label shown in the sign-aware picker; translatable at the UI layer. */
    #[Column(ColumnType::VarChar, length: 64)]
    public string $label = '';

    /** {@see self::SIGN_NEGATIVE} / {@see self::SIGN_POSITIVE} — which delta direction this reason serves. */
    #[Column(ColumnType::TinyInt)]
    public int $sign = 0;

    /** Fixed core GL-treatment class ({@see self::GL_CLASSES}) — the load-bearing export mapping. */
    #[Column(ColumnType::Enum, enumValues: self::GL_CLASSES)]
    public string $gl_class = '';

    /** Whether a free-text reason is mandatory when this code is chosen (the "other" catch-alls set it). */
    #[Column(ColumnType::Bool, default: false)]
    public bool $requires_note = false;

    /** Seeded core default (undeletable) vs a merchant-added code. */
    #[Column(ColumnType::Bool, default: false)]
    public bool $is_builtin = false;

    /** Soft-disable: a retired code stays for historical rows but drops out of the picker. */
    #[Column(ColumnType::Bool, default: true)]
    public bool $active = true;
}
