<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Settings;

/**
 * Read model for one `invflux_settings` row.
 *
 * Exposes both the **effective** replication policy (what the future
 * federation layer reads) AND the inputs that produced it (`policyLocked`,
 * `merchantChoice`) so an admin UI can render "InvFlux-locked" / "you
 * overrode the default" hints. Consumers that just want the value should
 * call `SettingsStore::getValue()` instead.
 *
 * @api
 */
final class SettingValue
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
        public readonly ReplicationPolicy $effectivePolicy,
        public readonly bool $policyLocked,
        public readonly ?ReplicationPolicy $merchantChoice,
    ) {
    }
}
