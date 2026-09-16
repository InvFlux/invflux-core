<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\License;

use Nandan108\InvFlux\Exceptions\LicenseException;

/**
 * Immutable, already-verified view of a signed license blob.
 *
 * Produced by {@see BlobVerifier::verify()} once the Ed25519 signature has been
 * checked against the raw payload bytes. By the time a `LicenseBlob` exists, its
 * contents are trusted; the remaining job is to answer the entitlement question
 * via {@see self::permits()} and to expose the freshness clocks the refresh
 * scheduler reasons about.
 *
 * The blob carries a set of independently-licensed {@see Entitlement}s — one
 * `Base` (Essentials/Pro) plus zero or more add-ons — and resolves a feature key as an
 * OR across them ("resolve by feature key, not SKU"). The wire format is
 * defined alongside the licensing and add-on entitlement contracts.
 *
 * @api
 */
final class LicenseBlob
{
    /**
     * @param non-empty-list<Entitlement> $entitlements at least one `Base`
     * @param non-empty-string            $kid          the signing-key id the blob verified against
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly string $licenseId,
        public readonly string $activationId,
        public readonly string $siteUrl,
        public readonly int $issuedAt,
        public readonly int $notAfter,
        public readonly EnvClass $envClass,
        public readonly ?string $seatWarning,
        public readonly string $kid,
        public readonly array $entitlements,
    ) {
    }

    /**
     * The entitlement decision: may `$featureKey` be used in `$mode` right now?
     * True if ANY entitlement whose state entitles grants it — the base tier or
     * any active add-on. Pure; the {@see LicenseGate} concrete layers staleness /
     * Essentials fallback on top.
     */
    public function permits(string $featureKey, AccessMode $mode): bool
    {
        foreach ($this->entitlements as $entitlement) {
            if ($entitlement->permits($featureKey, $mode)) {
                return true;
            }
        }

        return false;
    }

    /** The base entitlement — the platform licence (invariant: exactly one exists). */
    public function base(): Entitlement
    {
        foreach ($this->entitlements as $entitlement) {
            if ($entitlement->isBase()) {
                return $entitlement;
            }
        }

        throw LicenseException::malformed('blob has no base entitlement');
    }

    /**
     * Whether the install holds an entitlement naming `$sku`, **whatever state it is
     * in**.
     *
     * Membership and permission are separate questions, and this one is membership:
     * a revoked or grace-exhausted licence is still a licence for that SKU, and its
     * surfaces stay where the merchant left them while each feature degrades through
     * the revoke matrix ({@see self::permits()}). Filtering revoked entitlements out
     * here would instead take the paid UI away and put an upgrade prompt in front of
     * someone who already bought it — most often an install that was simply offline
     * too long.
     *
     * **Display only** — see {@see LicenseGate::holds()} for why this must never
     * gate behaviour.
     */
    public function holds(string $sku): bool
    {
        foreach ($this->entitlements as $entitlement) {
            if ($entitlement->sku === $sku) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every SKU the install holds, base first — the list form of {@see self::holds()}
     * and state-blind for the same reason.
     *
     * **Display only**, same caveat as {@see self::holds()}. Exists so a surface
     * rendering the whole estate at once (the licences page, a SPA bootstrap
     * payload) doesn't have to ask SKU by SKU.
     *
     * @return list<string>
     */
    public function heldSkus(): array
    {
        $skus = [];
        foreach ($this->entitlements as $entitlement) {
            $skus[] = $entitlement->sku;
        }

        return $skus;
    }

    /** Whether this blob is still cryptographically fresh (no refresh needed yet). */
    public function isFresh(int $now): bool
    {
        return $now < $this->notAfter;
    }

    /**
     * A copy with EVERY entitlement coerced to {@see LicenseState::Revoked}.
     *
     * Used by the gate when offline stale-tolerance is exhausted: each entitlement's
     * paid features then follow its revoke matrix (read-only keys stay readable, the
     * rest blocked) rather than the install dropping every paid feature.
     */
    public function asRevoked(): self
    {
        $revoked = array_map(static fn (Entitlement $e): Entitlement => $e->asRevoked(), $this->entitlements);

        return new self(
            schemaVersion: $this->schemaVersion,
            licenseId: $this->licenseId,
            activationId: $this->activationId,
            siteUrl: $this->siteUrl,
            issuedAt: $this->issuedAt,
            notAfter: $this->notAfter,
            envClass: $this->envClass,
            seatWarning: $this->seatWarning,
            kid: $this->kid,
            entitlements: $revoked,
        );
    }

    /** Whether this blob is bound to the given canonical site URL. */
    public function boundToSite(string $siteUrl): bool
    {
        return hash_equals($this->siteUrl, $siteUrl);
    }

    /**
     * Build a blob VO from the decoded, verified payload array.
     *
     * @param array<string, mixed> $p   the verified payload
     * @param non-empty-string     $kid the key id it verified against
     *
     * @throws LicenseException on a missing/ill-typed required field or no base entitlement
     */
    public static function fromPayload(array $p, string $kid): self
    {
        $issuedAt = self::int($p, 'issued_at');
        $envClass = EnvClass::tryFrom(self::str($p, 'env_class')) ?? throw LicenseException::malformed('env_class');

        /** @psalm-var mixed $rawEntitlements */
        $rawEntitlements = $p['entitlements'] ?? null;
        if (!is_array($rawEntitlements) || [] === $rawEntitlements) {
            throw LicenseException::malformed('entitlements');
        }

        $entitlements = [];
        $hasBase = false;
        /** @psalm-var mixed $rawEntitlement */
        foreach ($rawEntitlements as $rawEntitlement) {
            if (!is_array($rawEntitlement)) {
                throw LicenseException::malformed('entitlement');
            }
            /** @var array<string, mixed> $rawEntitlement */
            $entitlement = Entitlement::fromArray($rawEntitlement, $issuedAt);
            $hasBase = $hasBase || $entitlement->isBase();
            $entitlements[] = $entitlement;
        }
        if (!$hasBase) {
            throw LicenseException::malformed('entitlements: no base');
        }

        return new self(
            schemaVersion: self::int($p, 'v'),
            licenseId: self::str($p, 'license_id'),
            activationId: self::str($p, 'activation_id'),
            siteUrl: self::str($p, 'site_url'),
            issuedAt: $issuedAt,
            notAfter: self::int($p, 'not_after'),
            envClass: $envClass,
            seatWarning: self::nullableStr($p, 'seat_warning'),
            kid: $kid,
            entitlements: $entitlements,
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

    /**
     * @param array<string, mixed> $p
     *
     * @throws LicenseException
     */
    private static function int(array $p, string $key): int
    {
        /** @psalm-var mixed $v */
        $v = $p[$key] ?? null;

        return is_int($v) ? $v : throw LicenseException::malformed($key);
    }

    /** @param array<string, mixed> $p */
    private static function nullableStr(array $p, string $key): ?string
    {
        /** @psalm-var mixed $v */
        $v = $p[$key] ?? null;

        return is_string($v) && '' !== $v ? $v : null;
    }
}
