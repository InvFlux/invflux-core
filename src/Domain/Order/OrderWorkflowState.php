<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * The headline "where does this order sit relative to the active dispatch
 * queue?" state.
 *
 * **Derived, never stored.** It is a precedence-ordered summary of the two
 * orthogonal, independently-stored *system* dimensions on {@see Order}:
 *
 *  - {@see Order::$hold_reason} — system gate (payment/capture/fraud)
 *  - {@see Order::$closed_at}   — terminal
 *
 * They can co-occur (a BACS-pending order that then closes); this enum collapses
 * them to one badge via {@see self::derive()} with precedence **Closed › OnHold ›
 * Active**. The wire sends this derived value *plus* the raw dimensions so the UI
 * can show every concurrent truth.
 *
 * The *manual* set-aside axis (parking) is deliberately **not** here — it became a
 * `SuppressActive` governance tag, rendered as its own chip (a separate manual
 *
 * Orthogonal to {@see OrderStatus} (the stage axis: an order can be `Staged`
 * and `OnHold` at once).
 *
 * @api
 */
enum OrderWorkflowState: int
{
    // The backing ints are non-persistent — this enum is *derived*, never stored;
    // the wire sends the case name. Parking is not a case here — it is a
    // `SuppressActive` governance tag; this enum carries only the two
    // system-driven, typed-column axes below.

    /** In the active dispatch queue (no system dimension set). */
    case Active = 0;

    /** System-held out of the queue pending a signal (payment/capture/fraud) — see {@see HoldReason}. */
    case OnHold = 1;

    /** Terminal — order has left the queue (shipped-complete / cancelled / failed / fully refunded). */
    case Closed = 2;

    /**
     * Collapse the two orthogonal *system* workflow dimensions to a single
     * headline state by display precedence: Closed › OnHold › Active.
     *
     * The stored data keeps every concurrent truth; this is only the badge.
     * Parking is not a dimension here — it is a `SuppressActive` tag, a
     * separate (manual) axis rendered as its own chip.
     */
    public static function derive(
        ?HoldReason $holdReason,
        ?\DateTimeImmutable $closedAt,
    ): self {
        if (null !== $closedAt) {
            return self::Closed;
        }
        if (null !== $holdReason) {
            return self::OnHold;
        }

        return self::Active;
    }
}
