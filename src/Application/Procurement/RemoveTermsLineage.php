<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\InvFlux\Domain\Procurement\TermsRepository;
use Nandan108\InvFlux\Domain\Procurement\TermsVersion;
use Nandan108\InvFlux\Exceptions\TermsException;

/**
 * Take a set of purchase terms out of circulation — deleting it, or archiving it, as asked.
 *
 * **Deleting is for a set nothing was ever issued under.** An order stores the text and no pointer to
 * the set, so the set is the only bridge from an issued order's terms back to a name someone
 * recognises; deleting a used set would leave "which terms went out on that order?" answerable only
 * as raw text. So deleting a used set is refused, saying how many orders went out under it, and
 * archiving — which keeps it readable and takes it out of every picker — is how it is retired. Any
 * edition counts: a set whose first version went out and whose current one never did is still used.
 *
 * **A draft order following the set is released, not an obstacle.** It is still being written, so it
 * falls back to its supplier's set or the store's in the same write, and the drafts released are
 * returned for the caller to mention.
 *
 * **Both are refused while a supplier or the store's own setting chooses the set.** Those are standing
 * choices every future order inherits; the merchant changes them first, knowingly.
 *
 * @api
 */
final class RemoveTermsLineage
{
    public const DELETED = 'deleted';
    public const ARCHIVED = 'archived';

    public function __construct(private readonly TermsRepository $terms)
    {
    }

    /**
     * @param bool $archive      archive the set rather than delete it
     * @param bool $storeDefault whether the store's own terms setting names this set — a setting the
     *                           caller holds, since it lives outside this repository
     *
     * @return array{outcome: self::DELETED|self::ARCHIVED, releasedDrafts: list<int>}
     */
    public function __invoke(int $lineageId, bool $archive = false, bool $storeDefault = false): array
    {
        $lineage = $this->terms->findTermsLineage($lineageId) ?? throw TermsException::lineageNotFound($lineageId);
        if ($archive && $lineage->isRemoved()) {
            return ['outcome' => self::ARCHIVED, 'releasedDrafts' => []];
        }

        $suppliers = $this->terms->termsLineageSelections($lineageId)['suppliers'];
        if ($storeDefault || [] !== $suppliers) {
            throw TermsException::stillSelected($lineageId, $suppliers, $storeDefault);
        }

        if ($archive) {
            $lineage->removed_at = new \DateTimeImmutable();

            return ['outcome' => self::ARCHIVED, 'releasedDrafts' => $this->terms->archiveTermsLineage($lineage)];
        }

        $hashes = array_values(array_unique(array_map(
            static fn (TermsVersion $v): int => $v->content_hash,
            $this->terms->termsVersionsOf($lineageId),
        )));
        $issued = array_sum($this->terms->orderCountsForTerms($hashes));
        if ($issued > 0) {
            throw TermsException::issued($lineageId, $issued);
        }

        return ['outcome' => self::DELETED, 'releasedDrafts' => $this->terms->deleteTermsLineage($lineage)];
    }
}
