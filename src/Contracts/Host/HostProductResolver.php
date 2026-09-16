<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Host;

use Nandan108\InvFlux\Domain\Subject\HostProductInfo;

/**
 * Reverse of subject resolution: map subject IDs back to the host platform's product
 * display fields (name / SKU / GTIN / optional image).
 *
 * Where {@see SubjectRegistrar::resolveIdentifiers()} goes host-identifier → subject, this
 * port goes subject → host product, in bulk, for lists and pickers (supplier products, PO
 * lines, thumbnail columns). Implemented by the platform adapter, which resolves through
 * the host-specific identifier types it registered itself (e.g. a product-id and a
 * variant-id type) to that host's product API.
 *
 * @api
 */
interface HostProductResolver
{
    /**
     * Resolve many subject IDs to their host product info in a single batch.
     *
     * Returns a map keyed by subject id. Every requested id is present in the result; a
     * subject whose host product no longer exists resolves to {@see HostProductInfo::missing()}
     * (exists = false) so callers can render a placeholder rather than drop the row.
     *
     * `$withImage` is opt-in because resolving an image URL costs an extra host lookup
     * (e.g. a WordPress attachment query); omit it for non-visual callers.
     *
     * @param list<int> $subjectIds
     *
     * @return array<int, HostProductInfo>
     */
    public function resolve(array $subjectIds, bool $withImage = false): array;
}
