<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\InvFlux\Domain\Procurement\TermsRepository;
use Nandan108\InvFlux\Domain\Procurement\TermsSource;
use Nandan108\InvFlux\Domain\Procurement\TermsVersion;
use Nandan108\InvFlux\Exceptions\TermsException;

/**
 * Save new text for a set of purchase terms.
 *
 * **What a save does depends on whether any order was issued under the current text**, and nothing
 * else:
 *
 *  - **Unused** — the current edition is repointed at the new text, and no version is minted. Fixing
 *    a typo before anything went out is not a new edition of the terms.
 *  - **Used** — a new edition is minted and the current one retired. The text orders were issued
 *    under is never rewritten: it stays interned, and the edition that named it stays in the archive.
 *  - **Identical** to the current text once normalised — nothing happens. Re-saving what is there
 *    must not mint a version, which is what the normalisation exists to guarantee.
 *
 * **The edit names the edition it started from**, and a save from any other is refused. Two people
 * editing one set would otherwise have the second silently overwrite the first — or, where the first
 * minted a version, mint another on top of text the second never saw.
 *
 * Usage is read when the save runs. An order numbered in the same instant can still pick up the
 * text this save is replacing; it then carries that text, which stays interned and valid, and only
 * the archive loses the edition naming it. The window is the gap between two statements, and the
 * cost is a label, never the terms themselves.
 *
 * @api
 */
final class SaveTermsContent
{
    public const UNCHANGED = 'unchanged';
    public const REPLACED = 'replaced';
    public const VERSIONED = 'versioned';

    public function __construct(private readonly TermsRepository $terms)
    {
    }

    /**
     * @return array{outcome: self::UNCHANGED|self::REPLACED|self::VERSIONED, version: TermsVersion}
     */
    public function __invoke(int $lineageId, string $body, int $editedFromOrdinal): array
    {
        $lineage = $this->terms->findTermsLineage($lineageId) ?? throw TermsException::lineageNotFound($lineageId);
        if ($lineage->isRemoved()) {
            throw TermsException::removed($lineageId);
        }
        $current = $this->terms->currentTermsVersions([$lineageId])[$lineageId] ?? throw TermsException::lineageNotFound($lineageId);
        if ($current->ordinal !== $editedFromOrdinal) {
            throw TermsException::staleEdit($editedFromOrdinal, $current->ordinal);
        }

        $source = TermsSource::normalise($body);
        if ('' === $source) {
            throw TermsException::emptyBody();
        }
        $hash = $this->terms->internTerms($source);
        if ($hash === $current->content_hash) {
            return ['outcome' => self::UNCHANGED, 'version' => $current];
        }

        $used = ($this->terms->orderCountsForTerms([$current->content_hash])[$current->content_hash] ?? 0) > 0;
        if (!$used) {
            $current->content_hash = $hash;

            return ['outcome' => self::REPLACED, 'version' => $this->terms->saveTermsVersion($current)];
        }

        $current->is_current = null;
        $current->retired_at = new \DateTimeImmutable();
        $next = TermsVersion::newWith([
            'lineage_id'   => $lineageId,
            'ordinal'      => $current->ordinal + 1,
            'content_hash' => $hash,
            'is_current'   => true,
        ]);

        return ['outcome' => self::VERSIONED, 'version' => $this->terms->saveTermsVersion($next, $current)];
    }
}
