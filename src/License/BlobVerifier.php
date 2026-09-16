<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\License;

use Nandan108\InvFlux\Exceptions\LicenseException;

/**
 * Verifies a signed license blob offline against a set of pinned Ed25519
 * public keys, producing a trusted {@see LicenseBlob}.
 *
 * Platform-agnostic (uses only PHP-core `sodium_*`), so it lives in core and
 * is reused by the WP adapter and by tests. The matching *private* key never
 * exists here — only the license server holds it
 * . A leaked plugin therefore leaks only
 * public keys, which are worthless to an attacker.
 *
 * ## Wire envelope
 *
 *     { "payload": "<base64 of canonical-JSON bytes>",
 *       "sig":     "<base64 of 64-byte Ed25519 signature>",
 *       "kid":     "<signing-key id>" }
 *
 * The signature is verified over the **exact decoded payload bytes**, which
 * are then `json_decode`d — never re-serialized. Multiple keys are pinned to
 * permit rotation
 * (`§10.1`); `kid` selects which one to check against.
 *
 * @api
 */
final class BlobVerifier
{
    /**
     * @param array<non-empty-string, string> $publicKeys map of `kid` => raw 32-byte Ed25519 public key
     */
    public function __construct(
        private readonly array $publicKeys,
    ) {
    }

    /**
     * Verify a signed wire envelope and return the trusted blob.
     *
     * @param string $signedWire the JSON envelope (see class docblock)
     *
     * @throws LicenseException on any malformation, unknown key, or bad signature
     */
    public function verify(string $signedWire): LicenseBlob
    {
        /** @psalm-var mixed $env */
        $env = json_decode($signedWire, true);
        if (!is_array($env)) {
            throw LicenseException::malformed('envelope is not a JSON object');
        }

        $kidRaw = $env['kid'] ?? null;
        $payloadB64 = $env['payload'] ?? null;
        $sigB64 = $env['sig'] ?? null;
        if (!is_string($kidRaw) || '' === $kidRaw || !is_string($payloadB64) || !is_string($sigB64)) {
            throw LicenseException::malformed('envelope fields');
        }

        $publicKey = $this->publicKeys[$kidRaw] ?? throw LicenseException::unknownKey($kidRaw);

        $payloadBytes = base64_decode($payloadB64, true);
        $sig = base64_decode($sigB64, true);
        if (false === $payloadBytes || false === $sig) {
            throw LicenseException::malformed('base64 envelope content');
        }
        if (SODIUM_CRYPTO_SIGN_BYTES !== strlen($sig)) {
            throw LicenseException::malformed('signature length');
        }

        if (!sodium_crypto_sign_verify_detached($sig, $payloadBytes, $publicKey)) {
            throw LicenseException::badSignature();
        }

        /** @psalm-var mixed $payload */
        $payload = json_decode($payloadBytes, true);
        if (!is_array($payload)) {
            throw LicenseException::malformed('payload is not a JSON object');
        }

        /** @var array<string, mixed> $payload */
        return LicenseBlob::fromPayload($payload, $kidRaw);
    }
}
