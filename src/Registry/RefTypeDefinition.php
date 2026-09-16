<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Registry;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Declare one allowed ledger/event reference type.
 *
 * Reference types identify the kind of business entity a ledger movement or
 * event row points at (e.g., `order`, `purchase_order`, `correction`,
 * `order_line`). Codes are stable identifiers stored in
 * `invflux_ref_types.code`; the matching id is stored on the referencing row.
 *
 * `$identityClass` declares which generic ledger id column carries the referent's id:
 * `uuid` → `ref_id` (BINARY(16)); `int` → `ref_int_id` (INT UNSIGNED). It is dev-facing
 * metadata + an insert-time validation hint (the ledger write routes by value type).
 *
 * @api
 */
final class RefTypeDefinition
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $identityClass = 'uuid',
    ) {
        if ('' === $this->code) {
            throw new ConfigurationException('Ref type code must be a non-empty string.', 'empty_ref_type_code');
        }

        if (strlen($this->code) > 32) {
            throw new ConfigurationException('Ref type code must be at most 32 characters long.', 'ref_type_code_too_long');
        }

        if ('' === $this->name) {
            throw new ConfigurationException('Ref type name must be a non-empty string.', 'empty_ref_type_name');
        }

        if ('uuid' !== $this->identityClass && 'int' !== $this->identityClass) {
            throw new ConfigurationException(
                'Ref type identity class must be "uuid" or "int".',
                'invalid_ref_type_identity_class',
            );
        }
    }
}
