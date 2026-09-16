<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\InvFlux\Domain\Procurement\TermsLineage;
use Nandan108\InvFlux\Domain\Procurement\TermsRepository;
use Nandan108\InvFlux\Exceptions\TermsException;

/**
 * Rename a set of purchase terms — always allowed, including after orders were issued under it.
 *
 * The name is internal and never printed, and orders point at the text rather than at the set, so a
 * rename changes no supplier's copy and no order's meaning. That is also why it needs no lock and
 * never rides a content save: it is a different change, made at a different moment.
 *
 * @api
 */
final class RenameTermsLineage
{
    public function __construct(private readonly TermsRepository $terms)
    {
    }

    public function __invoke(int $lineageId, string $name): TermsLineage
    {
        $lineage = $this->terms->findTermsLineage($lineageId) ?? throw TermsException::lineageNotFound($lineageId);
        $name = self::validName($name);
        self::assertNameFree($this->terms, $name, $lineageId);
        $lineage->name = $name;

        return $this->terms->saveTermsLineage($lineage);
    }

    /**
     * Refuse a name another set carries, archived sets included, compared case-insensitively. A set
     * keeping its own name — a rename that changes only its case — is not a clash.
     *
     * One read of every set: the table holds a merchant's handful of term sets, not a catalogue.
     */
    public static function assertNameFree(TermsRepository $terms, string $name, ?int $exceptId = null): void
    {
        $wanted = mb_strtolower($name);
        foreach ($terms->termsLineages(true) as $lineage) {
            if ((int) $lineage->id !== $exceptId && mb_strtolower(trim($lineage->name)) === $wanted) {
                throw TermsException::nameTaken($name);
            }
        }
    }

    /** A set's name, trimmed, or the refusal saying what is wrong with it. */
    public static function validName(string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw TermsException::emptyName();
        }
        if (mb_strlen($name) > CreateTermsLineage::NAME_MAX) {
            throw TermsException::nameTooLong(CreateTermsLineage::NAME_MAX);
        }

        return $name;
    }
}
