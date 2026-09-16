<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\License;

use Nandan108\InvFlux\Exceptions\LicenseException;

/**
 * An immutable, grandfathered snapshot of one SKU's feature composition.
 *
 * The grandfather model requires `allows()` to evaluate against the
 * composition captured **at license issuance**, not against the SKU's current
 * composition. This value object is that snapshot:
 * the license server freezes the canonical catalogue for a SKU into a
 * versioned manifest, ships it inside the signed blob, and the plugin
 * answers membership from it.
 *
 * The plugin never *computes* grandfather — it receives a frozen list and
 * answers membership + revoke behavior. All policy lives server-side.
 *
 * The v0 {@see EssentialsOnlyLicenseGate} stub in the
 * adapter is the degenerate case of this VO: "manifest = current Essentials
 * catalogue, state = active".
 *
 * @api
 */
final class FeatureManifest
{
    /**
     * @param list<string>                  $featureKeys keys allowed by this snapshot
     * @param array<string, RevokeBehavior> $revokeClass per-key revoke behavior; missing keys default to Blocked
     */
    public function __construct(
        public readonly int $version,
        public readonly string $sku,
        public readonly array $featureKeys,
        public readonly array $revokeClass = [],
        public readonly int $issuedAt = 0,
    ) {
    }

    /**
     * Whether `$featureKey` is a member of this snapshot.
     *
     * Membership alone; the license-state / revoke gating is applied by
     * {@see LicenseBlob::permits()}, not here.
     */
    public function allows(string $featureKey): bool
    {
        return in_array($featureKey, $this->featureKeys, true);
    }

    /**
     * Revoke behavior for `$featureKey`, defaulting to {@see RevokeBehavior::Blocked}
     * (fail closed) for unclassified keys.
     */
    public function revokeBehavior(string $featureKey): RevokeBehavior
    {
        return $this->revokeClass[$featureKey] ?? RevokeBehavior::Blocked;
    }

    /**
     * Build a manifest from the decoded blob-payload fragment.
     *
     * @param array<string, mixed> $data the `{version, sku, features, feature_revoke_class}` fragment
     *
     * @throws LicenseException on a missing/ill-typed field
     */
    public static function fromArray(array $data): self
    {
        $version = $data['version'] ?? null;
        $sku = $data['sku'] ?? null;
        $features = $data['features'] ?? null;
        if (!is_int($version) || !is_string($sku) || '' === $sku || !is_array($features)) {
            throw LicenseException::malformed('manifest version/sku/features');
        }

        $keys = [];
        foreach ($features as $k) {
            if (!is_string($k)) {
                throw LicenseException::malformed('manifest feature key');
            }
            $keys[] = $k;
        }

        $revokeClass = [];
        /** @psalm-var mixed $rawRevoke */
        $rawRevoke = $data['feature_revoke_class'] ?? [];
        if (is_array($rawRevoke)) {
            /** @psalm-var mixed $behavior */
            foreach ($rawRevoke as $key => $behavior) {
                if (is_string($key) && is_string($behavior) && null !== ($rb = RevokeBehavior::tryFrom($behavior))) {
                    $revokeClass[$key] = $rb;
                }
            }
        }

        /** @psalm-var mixed $issuedAt */
        $issuedAt = $data['issued_at'] ?? 0;

        return new self(
            version: $version,
            sku: $sku,
            featureKeys: $keys,
            revokeClass: $revokeClass,
            issuedAt: is_int($issuedAt) ? $issuedAt : 0,
        );
    }
}
