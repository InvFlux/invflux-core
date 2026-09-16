<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Annotation;

/**
 * A recoverable annotation-operation failure carrying a stable machine code the transport
 * (REST) maps to an HTTP status. Mirrors the dispatch use-case validation-exception style.
 *
 * @api
 */
final class AnnotationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function denied(string $message): self
    {
        return new self('invflux_annotation_forbidden', $message);
    }

    public static function invalid(string $message): self
    {
        return new self('invflux_annotation_invalid', $message);
    }

    public static function notFound(string $message): self
    {
        return new self('invflux_annotation_not_found', $message);
    }
}
