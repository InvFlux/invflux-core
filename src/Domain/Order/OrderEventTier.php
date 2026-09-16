<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Coarse classification of every {@see OrderEventType}.
 *
 * The 4-tier taxonomy lets readers (timeline UIs, GL projectors, reporting) group events
 * without enumerating each type. The tier of a given type is set by
 * {@see OrderEventType::tier()} and enforced at write time in
 * {@see OrderEvent::beforeSave()} / {@see OrderEvent::validate()}.
 *
 * The full taxonomy rationale and the per-type assignments are documented
 * with the event-tier design.
 *
 * @api
 */
enum OrderEventTier: string
{
    /**
     * Order lifecycle transitions — `order.created`, `order.locked`, `shipment.sent`,
     * `correction.created`, etc. The "what state is this order in" timeline.
     */
    case Lifecycle = 'lifecycle';

    /**
     * Resolutions / approvals / merchant or system decisions that authorise downstream
     * Execution-tier events. Examples: `lpsc.resolved`, future `cs.approved`,
     * `return.approved`. Decision events are the natural `parent_event_id` target for
     * {@see OrderCorrection}.
     */
    case Decision = 'decision';

    /**
     * Concrete monetary or stock effects of a decision — `refund.failed`,
     * `refund.cancelled`, future `gl.posted`. Execution-tier events carry monetary
     * payloads and FX context.
     */
    case Execution = 'execution';

    /**
     * Side-band annotations that don't change the order's state machine —
     * `note.added`, `email.sent/suppressed`, `stock_issue.reported`, future
     * `alert.raised`. Visible on the timeline; ignored by GL/state projectors.
     */
    case Ancillary = 'ancillary';
}
