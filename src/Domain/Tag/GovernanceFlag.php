<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Tag;

/**
 * Behavioural powers a {@see Tag} definition can carry beyond plain labelling —
 * the **Pro governance layer** (labelling stays Essentials; labels *with teeth* are the
 * upgrade). Stored as a bitmask on {@see Tag::$governance_flags} via
 * {@see \Nandan108\Attrecord\Caster\BitmaskCaster}; every case is a distinct
 * positive power of two.
 *
 * `Tag` is a **scoped, shared** record, so this is a shared namespace of tag
 * behaviours — each consuming surface honours the bits it implements:
 *
 *  - {@see self::RequireNoteOnAdd} and {@see self::PromotedAffordance} are
 *    **universal** ("require a note", "give this tag a prominent affordance") —
 *    meaningful for any tagged document; a future subject/supplier/PO tag surface
 *    inherits them for free.
 *  - {@see self::SuppressActive} is **dispatch-honoured today**: it drops the
 *    carrying order out of the active queue. Other entities already own their
 *    "hide from active" axis as first-class lifecycle/system state (supplier
 *    `enabled`, subject published, PO close), so they neither need nor should
 *    reuse it — the neutral name does not claim universality.
 *
 * @api
 */
enum GovernanceFlag: int
{
    /**
     * Applying this tag requires an accompanying annotation (note).
     *
     * Named for the direction it governs, because the other direction is a real and separate case —
     * see {@see self::RequireNoteOnRemove}.
     */
    case RequireNoteOnAdd = 1;

    /**
     * Removing this tag requires an accompanying annotation (note).
     *
     * The counterpart to {@see self::RequireNoteOnAdd}, and usually wanted *instead of* it rather
     * than alongside: a tag that signals a problem ("address incomplete", "awaiting customer") is
     * cheap to apply and meaningful to remove, because taking it off asserts the problem is
     * resolved. Forcing an essay to raise the flag would be friction; forcing one to lower it is the
     * record of how it was settled.
     *
     * Two flags rather than one broadened one for exactly that reason — and because widening the
     * other would retroactively demand removal notes from every tag that requires one on apply.
     *
     * Orthogonal to {@see self::LockRemoval}, and they compose: that one asks *who may* take the tag
     * off, this one asks that they *say why*.
     */
    case RequireNoteOnRemove = 2;

    /** The carrying entity leaves its active listing (orders: the dispatch queue). */
    case SuppressActive = 4;

    /** The tag earns a prominent toggle affordance, not just a menu item. */
    case PromotedAffordance = 8;

    /** Hidden from the queue's quick tag pickers (right-click + bulk) — still usable
     *  on the order detail (with full context) and in the queue filter. */
    case HidePicker = 16;

    /** Removal requires the `invflux_govern_tags` capability even when anyone may
     *  apply it — the "escalation lock": alarms are cheap to raise, accountable to
     *  clear. Only meaningful when {@see ManageAuthority::Anyone}
     *  (the `Managed` / `System` tiers already gate removal).
     */
    case LockRemoval = 32;
}
