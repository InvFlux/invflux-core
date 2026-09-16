<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Tag;

/**
 * Who may change a {@see Tag}'s assignment (apply/remove) — a single ordinal axis
 * of the Pro governance layer. Stored on {@see Tag::$manage_authority}
 * (`TinyIntUnsigned`, {@see \Nandan108\Attrecord\Caster\EnumCaster}).
 *
 * The tiers are mutually exclusive by construction (unlike bitmask flags), which is
 * why this is an enum column rather than two flags:
 *
 *  - {@see self::Anyone}  — any actor with the surface's tag-assign permission
 *    (today's default). The {@see GovernanceFlag::LockRemoval} flag can still
 *    upgrade *removal* to require the capability while leaving *apply* open — the
 *    escalation pattern (anyone flags, only authority clears).
 *  - {@see self::Managed} — apply **and** remove require the `invflux_govern_tags`
 *    capability (managers/admins by default; rides future roles). A manager "owns"
 *    the tag end-to-end.
 *  - {@see self::System} — no human apply/remove; set only through the programmatic
 *    path (rule engine / add-ons via the repository, bypassing the manual guard).
 *
 * The add-restricted / remove-open direction is deliberately not modelled — its real
 * use-cases are status/task/approval concerns, not tags (see the doc's steelman).
 *
 * @api
 */
enum ManageAuthority: int
{
    case Anyone = 0;
    case Managed = 1;
    case System = 2;
}
