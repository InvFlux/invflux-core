<?php

declare(strict_types=1);

namespace Tests\Unit\License;

use Nandan108\InvFlux\Exceptions\LicenseException;
use Nandan108\InvFlux\License\AccessMode;
use Nandan108\InvFlux\License\BlobVerifier;
use Nandan108\InvFlux\License\Entitlement;
use Nandan108\InvFlux\License\EnvClass;
use Nandan108\InvFlux\License\LicenseBlob;
use Nandan108\InvFlux\License\LicenseState;
use PHPUnit\Framework\TestCase;

/**
 * The cross-repo wire-contract test.
 *
 * Proves the license server's signer and the plugin's {@see BlobVerifier} agree
 * on the blob envelope. The {@see self::sign()} helper below MUST mirror
 * `App\Support\BlobSigner::sign()` in invflux-license-server byte-for-byte — if
 * the wire format changes on either side, change both, and this test is the
 * tripwire.
 */
final class BlobRoundTripTest extends TestCase
{
    private const KID = 'k1';

    public function testRoundTripParsesEveryField(): void
    {
        [$priv, $pub] = self::keypair();
        $blob = self::verify($pub, $this->sign($priv, $this->payload()));

        $this->assertSame('lic-123', $blob->licenseId);
        $this->assertSame('act-456', $blob->activationId);
        $this->assertSame('https://shop.example', $blob->siteUrl);
        $this->assertSame(1_733_400_000, $blob->issuedAt);
        $this->assertSame(1_734_004_800, $blob->notAfter);
        $this->assertSame(EnvClass::Production, $blob->envClass);
        $this->assertSame('k1', $blob->kid);

        // Base-entitlement fields.
        $base = $blob->base();
        $this->assertSame(Entitlement::BASE_SKU, $base->sku);
        $this->assertTrue($blob->holds(Entitlement::BASE_SKU));
        $this->assertSame(LicenseState::Active, $base->state);
        $this->assertSame(1_735_689_600, $base->paidThrough);
        $this->assertNull($base->graceUntil);
        $this->assertSame(42, $base->manifest->version);
        $this->assertSame('$29/mo', $base->grandfatheredPrice);
    }

    public function testActiveStateGrantsManifestMembership(): void
    {
        [$priv, $pub] = self::keypair();
        $blob = self::verify($pub, $this->sign($priv, $this->payload()));

        $this->assertTrue($blob->permits('dispatch.advanced-routing', AccessMode::Write));
        $this->assertFalse($blob->permits('not.in.manifest', AccessMode::Write));
    }

    public function testRevokedStateAppliesReadOnlyMatrix(): void
    {
        [$priv, $pub] = self::keypair();
        $blob = self::verify($pub, $this->sign($priv, $this->payload(['base' => ['state' => 'revoked']])));

        // read-only-classified feature: readable, not writable.
        $this->assertTrue($blob->permits('dispatch.advanced-routing', AccessMode::Read));
        $this->assertFalse($blob->permits('dispatch.advanced-routing', AccessMode::Write));
        // unclassified paid feature defaults to blocked (fail closed).
        $this->assertFalse($blob->permits('lpsc.cancel-immediately', AccessMode::Read));
        $this->assertFalse($blob->permits('lpsc.cancel-immediately', AccessMode::Write));
    }

    public function testAsRevokedCoercesEntitlement(): void
    {
        [$priv, $pub] = self::keypair();
        $blob = self::verify($pub, $this->sign($priv, $this->payload()))->asRevoked();

        $this->assertSame(LicenseState::Revoked, $blob->base()->state);
        $this->assertTrue($blob->permits('dispatch.advanced-routing', AccessMode::Read));
        $this->assertFalse($blob->permits('dispatch.advanced-routing', AccessMode::Write));
    }

    public function testAddonEntitlementGrantsItsOwnKeysAlongsideBase(): void
    {
        [$priv, $pub] = self::keypair();
        $payload = $this->payloadWithAddons([$this->addon('channel-sync', 'active', ['channel.sync'], quantity: 3)]);
        $blob = self::verify($pub, $this->sign($priv, $payload));

        // Base key AND add-on key both resolve — the OR across entitlements.
        $this->assertTrue($blob->permits('dispatch.advanced-routing', AccessMode::Write));
        $this->assertTrue($blob->permits('channel.sync', AccessMode::Write));
        $this->assertCount(2, $blob->entitlements);
        // The add-on is held alongside the base, and neither displaces the other.
        $this->assertSame([Entitlement::BASE_SKU, 'channel-sync'], $blob->heldSkus());
    }

    public function testRevokedAddonDegradesOnlyItsOwnKeys(): void
    {
        [$priv, $pub] = self::keypair();
        // Revoked add-on, default (blocked) revoke class.
        $payload = $this->payloadWithAddons([$this->addon('channel-sync', 'revoked', ['channel.sync'])]);
        $blob = self::verify($pub, $this->sign($priv, $payload));

        $this->assertFalse($blob->permits('channel.sync', AccessMode::Write));       // add-on gone
        $this->assertTrue($blob->permits('dispatch.advanced-routing', AccessMode::Write)); // base intact
    }

    public function testBlobWithoutABaseEntitlementIsRejected(): void
    {
        [$priv, $pub] = self::keypair();
        $payload = $this->payload();
        $payload['entitlements'] = [$this->addon('channel-sync', 'active', ['channel.sync'])];

        $this->expectException(LicenseException::class);
        self::verify($pub, $this->sign($priv, $payload));
    }

    public function testFreshnessAndSiteBinding(): void
    {
        [$priv, $pub] = self::keypair();
        $blob = self::verify($pub, $this->sign($priv, $this->payload()));

        $this->assertTrue($blob->isFresh(1_733_400_001));
        $this->assertFalse($blob->isFresh(1_734_004_800));
        $this->assertTrue($blob->boundToSite('https://shop.example'));
        $this->assertFalse($blob->boundToSite('https://evil.example'));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        [$priv, $pub] = self::keypair();
        $env = $this->envelope($this->sign($priv, $this->payload()));
        $raw = (string) base64_decode((string) $env['payload'], true);
        // Claim a SKU the licence does not grant — invalidates the signature over the
        // original bytes.
        $raw = str_replace('"sku":"'.Entitlement::BASE_SKU.'"', '"sku":"pro"', $raw);
        $env['payload'] = base64_encode($raw);

        $this->expectException(LicenseException::class);
        self::verify($pub, json_encode($env, JSON_THROW_ON_ERROR));
    }

    public function testUnknownKidIsRejected(): void
    {
        [$priv, $pub] = self::keypair();
        $env = $this->envelope($this->sign($priv, $this->payload()));
        $env['kid'] = 'k999';

        $this->expectException(LicenseException::class);
        self::verify($pub, json_encode($env, JSON_THROW_ON_ERROR));
    }

    public function testWrongSigningKeyIsRejected(): void
    {
        [$priv] = self::keypair();
        [, $otherPublic] = self::keypair();

        $this->expectException(LicenseException::class);
        self::verify($otherPublic, $this->sign($priv, $this->payload()));
    }

    public function testMalformedEnvelopeIsRejected(): void
    {
        [, $pub] = self::keypair();

        $this->expectException(LicenseException::class);
        self::verify($pub, '{"not":"an envelope"}');
    }

    public function testMissingRequiredFieldIsRejected(): void
    {
        [$priv, $pub] = self::keypair();
        $payload = $this->payload();
        unset($payload['license_id']);

        $this->expectException(LicenseException::class);
        self::verify($pub, $this->sign($priv, $payload));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} [privateKey, publicKey] */
    private static function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair)];
    }

    private static function verify(string $publicKey, string $signedWire): LicenseBlob
    {
        return (new BlobVerifier([self::KID => $publicKey]))->verify($signedWire);
    }

    /**
     * MUST mirror App\Support\BlobSigner::sign() in invflux-license-server.
     *
     * @param array<string, mixed> $payload
     */
    private function sign(string $privateKey, array $payload): string
    {
        $bytes = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $sig = sodium_crypto_sign_detached($bytes, $privateKey);

        return json_encode([
            'payload' => base64_encode($bytes),
            'sig'     => base64_encode($sig),
            'kid'     => self::KID,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(string $signedWire): array
    {
        /** @var array<string, mixed> $env */
        $env = json_decode($signedWire, true, 512, JSON_THROW_ON_ERROR);

        return $env;
    }

    /**
     * @param list<array<string, mixed>> $addons
     *
     * @return array<string, mixed>
     */
    private function payloadWithAddons(array $addons): array
    {
        $payload = $this->payload();
        /** @var list<array<string, mixed>> $entitlements */
        $entitlements = $payload['entitlements'];
        $payload['entitlements'] = array_merge($entitlements, $addons);

        return $payload;
    }

    /**
     * @param list<string> $features
     *
     * @return array<string, mixed>
     */
    private function addon(string $key, string $state, array $features, ?int $quantity = null): array
    {
        return [
            'kind'                 => 'addon',
            'sku'                  => $key,
            'state'                => $state,
            'manifest_version'     => 7,
            'features'             => $features,
            'feature_revoke_class' => [],
            'paid_through'         => null,
            'grace_until'          => null,
            'grandfathered_price'  => null,
            'quantity'             => $quantity,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        /** @var array<string, mixed> $baseOverrides */
        $baseOverrides = $overrides['base'] ?? [];
        unset($overrides['base']);

        return array_merge([
            'v'             => 1,
            'license_id'    => 'lic-123',
            'activation_id' => 'act-456',
            'site_url'      => 'https://shop.example',
            'issued_at'     => 1_733_400_000,
            'not_after'     => 1_734_004_800,
            'env_class'     => 'production',
            'seat_warning'  => null,
            'entitlements'  => [
                array_merge([
                    'kind'                 => 'base',
                    'sku'                  => Entitlement::BASE_SKU,
                    'state'                => 'active',
                    'manifest_version'     => 42,
                    'features'             => ['lpsc.cancel-immediately', 'dispatch.advanced-routing'],
                    'feature_revoke_class' => ['dispatch.advanced-routing' => 'read-only'],
                    'paid_through'         => 1_735_689_600,
                    'grace_until'          => null,
                    'grandfathered_price'  => '$29/mo',
                    'quantity'             => null,
                ], $baseOverrides),
            ],
        ], $overrides);
    }
}
