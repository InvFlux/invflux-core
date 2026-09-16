<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Util;

/**
 * Small JSON helper with exceptions enabled.
 *
 * @api
 */
final class Json
{
    /**
     * Encode one value to JSON.
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR) ?: throw new \JsonException('Failed to encode JSON.');
    }

    /**
     * Decode one JSON payload, preserving whatever type it encodes.
     *
     * The counterpart to {@see encode()}: a value that went in as a string, an int or an array
     * comes back as one. Use this wherever the payload's shape is the caller's business rather
     * than guaranteed to be an object — a setting's value, say, which may legitimately be a
     * scalar. {@see decodeObject()} is the narrower helper for payloads that must be arrays, and
     * silently returns `[]` for anything else, which would quietly destroy a scalar.
     */
    public static function decode(string $json): mixed
    {
        /** @psalm-var mixed */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode one JSON payload that is expected to be an object/array.
     *
     * Non-array payloads collapse to `[]` rather than throwing — deliberate for callers that want
     * a shape guarantee, and the reason {@see decode()} exists for callers that do not.
     */
    public static function decodeObject(string $json): array
    {
        /** @psalm-var mixed */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
