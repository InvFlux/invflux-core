<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Diagnostics;

/**
 * Normalized outcome status for a diagnostic check run.
 *
 * @api
 */
enum DiagnosticStatus: string
{
    case Ok = 'ok';
    case AutoRepaired = 'auto_repaired';
    case Unresolvable = 'unresolvable';
}
