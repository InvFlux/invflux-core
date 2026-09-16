<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\TermsSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The canonical form terms are stored and hashed in. Every case here is a difference a reader cannot
 * see, which is exactly why it must not reach the digest as a different text.
 */
final class TermsSourceTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function invisibleDifferences(): iterable
    {
        yield 'windows line endings'  => ["1. Delivery.\r\n2. Payment.", "1. Delivery.\n2. Payment."];
        yield 'old mac line endings'  => ["1. Delivery.\r2. Payment.", "1. Delivery.\n2. Payment."];
        yield 'trailing spaces'       => ["1. Delivery.   \n2. Payment.\t", "1. Delivery.\n2. Payment."];
        yield 'a run of blank lines'  => ["# Delivery\n\n\n\nGoods travel at our risk.", "# Delivery\n\nGoods travel at our risk."];
        yield 'blank lines at the ends' => ["\n\n# Delivery\n\n", '# Delivery'];
        yield 'a byte-order mark'     => ["\u{FEFF}# Delivery", '# Delivery'];
        yield 'blank lines holding only spaces' => ["# Delivery\n   \n \nText.", "# Delivery\n\nText."];
    }

    #[DataProvider('invisibleDifferences')]
    public function testAnInvisibleDifferenceIsRemoved(string $source, string $canonical): void
    {
        self::assertSame($canonical, TermsSource::normalise($source));
    }

    /** What decides what a line *is* in Markdown survives: indentation, and a single blank line. */
    public function testMeaningfulWhitespaceIsKept(): void
    {
        $source = "- Delivery\n  - by road\n\n    code-like indent\n\nNext paragraph.";

        self::assertSame($source, TermsSource::normalise($source));
    }

    /** Normalising twice changes nothing — otherwise re-saving unchanged terms would mint a version. */
    public function testNormalisingIsIdempotent(): void
    {
        $once = TermsSource::normalise("\u{FEFF}\r\n# Terms  \r\n\r\n\r\n1. Net 30. \r\n");

        self::assertSame($once, TermsSource::normalise($once));
    }
}
