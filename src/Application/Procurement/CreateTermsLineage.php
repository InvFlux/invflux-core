<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\InvFlux\Domain\Procurement\TermsLineage;
use Nandan108\InvFlux\Domain\Procurement\TermsRepository;
use Nandan108\InvFlux\Domain\Procurement\TermsSource;
use Nandan108\InvFlux\Domain\Procurement\TermsVersion;
use Nandan108\InvFlux\Exceptions\TermsException;

/**
 * Start a new set of purchase terms, from scratch or as a copy of another.
 *
 * **A copy must differ from its source before it is created.** Identity is the text, so an unedited
 * copy would point at the very row its source points at; if orders were ever issued under that text,
 * the new set would be born in use — archivable but never deletable — on terms nobody has shipped
 * under. The surface keeps Save disabled until the text differs, and this is the same rule where the
 * surface cannot be the only thing holding it.
 *
 * @api
 */
final class CreateTermsLineage
{
    public const NAME_MAX = 190;

    public function __construct(private readonly TermsRepository $terms)
    {
    }

    /** @param int|null $copyOf the set this one starts as a copy of */
    public function __invoke(string $name, string $body, ?int $copyOf = null): TermsLineage
    {
        $name = RenameTermsLineage::validName($name);
        RenameTermsLineage::assertNameFree($this->terms, $name);
        $source = TermsSource::normalise($body);
        if ('' === $source) {
            throw TermsException::emptyBody();
        }

        $hash = $this->terms->internTerms($source);
        if (null !== $copyOf) {
            $original = $this->terms->currentTermsVersions([$copyOf])[$copyOf] ?? null;
            if (null !== $original && $original->content_hash === $hash) {
                throw TermsException::copyUnchanged();
            }
        }

        return $this->terms->createTermsLineage(
            TermsLineage::newWith(['name' => $name]),
            TermsVersion::newWith(['ordinal' => 1, 'content_hash' => $hash, 'is_current' => true]),
        );
    }
}
