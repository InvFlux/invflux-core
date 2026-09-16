<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * Report a malformed, tampered, or unverifiable license blob.
 *
 * Thrown by the blob verifier ({@see \Nandan108\InvFlux\License\BlobVerifier})
 * and the blob value object on parse failure. Callers MUST catch this and
 * degrade to the Essentials tier with a notice — a licensing fault must never
 * break the inventory engine ( "degrades to an upgrade prompt,
 * never a hard error";).
 *
 * The {@see self::$detailCode} gives a stable, log-safe reason without
 * leaking blob contents.
 *
 * @api
 */
final class LicenseException extends InvFluxException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly string $detailCode = 'license_error',
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function badSignature(): self
    {
        return new self('License blob signature verification failed.', 'bad_signature');
    }

    public static function unknownKey(string $kid): self
    {
        return new self('License blob signed with an unrecognized key.', 'unknown_kid', ['kid' => $kid]);
    }

    public static function malformed(string $why): self
    {
        return new self('License blob is malformed: '.$why, 'malformed', ['why' => $why]);
    }
}
