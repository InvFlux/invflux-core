<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\License;

/**
 * Whether an {@see Entitlement} is the base-ladder license (Essentials/Pro) or a
 * purchased add-on. A blob always carries
 * exactly one `Base` entitlement plus zero or more `Addon`s.
 *
 * @api
 */
enum EntitlementKind: string
{
    case Base = 'base';
    case Addon = 'addon';
}
