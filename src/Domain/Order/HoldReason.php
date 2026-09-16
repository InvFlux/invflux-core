<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Why an order is *system-held* out of the active dispatch queue.
 *
 * One of the two orthogonal *system* workflow dimensions on {@see Order} — the
 * system-gate axis (a hold the order cannot proceed past until a signal lifts
 * it), distinct from the terminal axis ({@see Order::$closed_at}). Stored as a
 * nullable `TinyIntUnsigned` via {@see \Nandan108\Attrecord\Caster\EnumCaster};
 * `null` means "not held". The headline {@see OrderWorkflowState} is derived from
 * both dimensions, never stored. (Manual set-aside is a separate axis — a
 * `SuppressActive` governance tag, not a hold.)
 *
 * The set starts minimal and grows as each gate's flow is wired. Three are written
 * today. Two ride the host's own status transitions into and out of a status the
 * merchant maps as a hold: {@see self::PaymentPending} for the ordinary deferred
 * gateway, and {@see self::CaptureUnresolved} where a partial-capture flow put the
 * order there and is still awaiting resolution. {@see self::HostSetAside} is the
 * exception — no status transition produces it, because the signal behind it is not
 * a status. The remaining two are declared so the model — and the wire — stay stable
 * before their producers land; nothing in the host distinguishes them yet, and a
 * label nothing can lift would be worse than none.
 *
 * @api
 */
enum HoldReason: int
{
    /** Awaiting payment on a deferred gateway (BACS / cheque / bank transfer). */
    case PaymentPending = 1;

    /** A partial/failed capture (LPSC) awaits merchant resolution before the order can proceed. */
    case CaptureUnresolved = 2;

    /** Flagged for fraud review; held until cleared. */
    case FraudReview = 3;

    /** Held for an explicit merchant decision (manual gate, no automatic signal to lift it). */
    case MerchantReview = 4;

    /**
     * The host's own record of the order has been put aside — trashed, in WordPress terms — and
     * the merchant has asked for that to park the order rather than close it.
     *
     * A shelf, not a decision: the commitment is intact and no money question has been answered,
     * which is exactly what a hold expresses and a closure would not. The signal carries no
     * commercial meaning of its own — the host does not register it as an order status — so a
     * merchant who reads it as "this order is off" chooses the cancel route instead, and this
     * reason never appears.
     *
     * Unlike its siblings, the signal that lifts it is the host's restore, not a payment or a
     * review outcome.
     */
    case HostSetAside = 5;
}
