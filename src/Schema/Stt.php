<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

/**
 * InvFlux's **native** `stt` (state) dimension values — the canonical set shipped by
 * InvFlux Essentials/Pro.
 *
 * These constants only *name* the native values for safe, refactor-friendly reference;
 * they do **not** close the dimension. The `stt` dimension is open: add-ons contribute
 * their own values (and flows) through the slot-space definition and the dimension
 * registry — exactly as the `loc` dimension already accepts plugin-registered values via
 * `addDimensionValues()`. The movement engine validates a value against the *registered*
 * values of a SlotSpace, never against this class, so an add-on defining e.g.
 * `MyAddonStt::PENDING = 'pnd'` and registering it works without touching core.
 *
 * States are **operational, never financial**:
 *  - `atp` available / promisable — contributes to available-to-promise
 *  - `res` tentative hold         — active cart/checkout, revocable, auto-expires
 *  - `ctd` committed              — confirmed order, will not auto-release
 *
 * @api
 */
final class Stt
{
    /** Available / promisable ("for sale") — contributes to available-to-promise. */
    public const ATP = 'atp';

    /** Tentative hold by an active cart/checkout — revocable, auto-expires. */
    public const RES = 'res';

    /** Committed to a confirmed order — will not auto-release. */
    public const CTD = 'ctd';

    /**
     * The native value set, in canonical order (default first).
     *
     * @var list<non-empty-string>
     */
    public const NATIVE = [self::ATP, self::RES, self::CTD];
}
