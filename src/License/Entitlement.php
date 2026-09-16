<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\License;

use Nandan108\InvFlux\Exceptions\LicenseException;

/**
 * One independently-licensed grant inside a {@see LicenseBlob} — the base licence
 * every install holds, or a purchased add-on.
 *
 * Every grant names a **SKU**, base and add-on alike. There is no tier: an install
 * holds a set of entitlements, and "which tier is this" is not a question the
 * mechanism asks — what used to be the paid tier is one add-on SKU among several,
 * distinguished only by how many other SKUs require it.
 *
 * Each entitlement runs the §3.4 license-state machine on its OWN subscription:
 * a lapsed add-on degrades only its own keys while the base stays active. The
 * per-feature decision here is the single-manifest logic; {@see LicenseBlob}
 * ORs it across every entitlement so "resolve by feature key, not SKU" falls out.
 *
 * @api
 */
final class Entitlement
{
    /**
     * The SKU every install holds — the platform itself, and the blob's one Base grant.
     *
     * The key is the product's name, not its price. "Costs nothing" is a fact about one moment in
     * the catalogue; a SKU is a permanent identity that ends up on every blob, every licence row
     * and every prerequisite edge, and the day the platform is bundled into something paid, a key
     * spelling `free` would be a lie nobody can cheaply retract.
     */
    public const BASE_SKU = 'essentials';

    public function __construct(
        public readonly EntitlementKind $kind,
        public readonly string $sku,
        public readonly LicenseState $state,
        public readonly FeatureManifest $manifest,
        public readonly ?int $paidThrough,
        public readonly ?int $graceUntil,
        public readonly ?string $grandfatheredPrice,
        public readonly ?int $quantity,            // metered add-ons only; else null
    ) {
    }

    public function isBase(): bool
    {
        return EntitlementKind::Base === $this->kind;
    }

    /**
     * May `$featureKey` be used in `$mode` under THIS entitlement right now?
     *
     * Manifest-granting states ({@see LicenseState::entitlesManifest()}): membership
     * suffices. Revoked: the key must be in the manifest AND its {@see RevokeBehavior}
     * must permit the mode (read-only → Read only; blocked/hidden → neither).
     */
    public function permits(string $featureKey, AccessMode $mode): bool
    {
        if ($this->state->entitlesManifest()) {
            return $this->manifest->allows($featureKey);
        }

        if (!$this->manifest->allows($featureKey)) {
            return false;
        }

        return match ($this->manifest->revokeBehavior($featureKey)) {
            RevokeBehavior::ReadOnly                        => AccessMode::Read === $mode,
            RevokeBehavior::Blocked, RevokeBehavior::Hidden => false,
        };
    }

    /** A copy coerced to {@see LicenseState::Revoked} (offline stale-tolerance exhausted). */
    public function asRevoked(): self
    {
        if (LicenseState::Revoked === $this->state) {
            return $this;
        }

        return new self(
            kind: $this->kind,
            sku: $this->sku,
            state: LicenseState::Revoked,
            manifest: $this->manifest,
            paidThrough: $this->paidThrough,
            graceUntil: $this->graceUntil,
            grandfatheredPrice: $this->grandfatheredPrice,
            quantity: $this->quantity,
        );
    }

    /**
     * Parse one entitlement fragment from a verified blob payload.
     *
     * @param array<string, mixed> $e            the entitlement object
     * @param int                  $blobIssuedAt fallback issuance for the manifest snapshot
     *
     * @throws LicenseException on a missing/ill-typed required field
     */
    public static function fromArray(array $e, int $blobIssuedAt): self
    {
        /** @psalm-var mixed $kindRaw */
        $kindRaw = $e['kind'] ?? null;
        $kind = is_string($kindRaw) ? EntitlementKind::tryFrom($kindRaw) : null;
        if (null === $kind) {
            throw LicenseException::malformed('entitlement kind');
        }

        $state = LicenseState::tryFrom(self::str($e, 'state')) ?? throw LicenseException::malformed('entitlement state');

        $sku = self::str($e, 'sku');

        // The manifest snapshot travels inline with each entitlement (§5.3), frozen
        // from the catalogue composition for this same SKU — so it needs no identity
        // of its own beyond the one its entitlement already carries.
        $manifest = FeatureManifest::fromArray([
            'version'              => $e['manifest_version'] ?? null,
            'sku'                  => $sku,
            'features'             => $e['features'] ?? [],
            'feature_revoke_class' => $e['feature_revoke_class'] ?? [],
            'issued_at'            => $blobIssuedAt,
        ]);

        return new self(
            kind: $kind,
            sku: $sku,
            state: $state,
            manifest: $manifest,
            paidThrough: self::nullableInt($e, 'paid_through'),
            graceUntil: self::nullableInt($e, 'grace_until'),
            grandfatheredPrice: self::nullableStr($e, 'grandfathered_price'),
            quantity: self::nullableInt($e, 'quantity'),
        );
    }

    /**
     * @param array<string, mixed> $p
     *
     * @throws LicenseException
     */
    private static function str(array $p, string $key): string
    {
        /** @psalm-var mixed $v */
        $v = $p[$key] ?? null;

        return is_string($v) && '' !== $v ? $v : throw LicenseException::malformed($key);
    }

    /** @param array<string, mixed> $p */
    private static function nullableInt(array $p, string $key): ?int
    {
        /** @psalm-var mixed $v */
        $v = $p[$key] ?? null;

        return is_int($v) ? $v : null;
    }

    /** @param array<string, mixed> $p */
    private static function nullableStr(array $p, string $key): ?string
    {
        /** @psalm-var mixed $v */
        $v = $p[$key] ?? null;

        return is_string($v) && '' !== $v ? $v : null;
    }
}
