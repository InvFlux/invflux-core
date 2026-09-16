<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Tests\Domain;

use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Record;
use PHPUnit\Framework\TestCase;

/**
 * Every Record in core holds a lock tier of its own.
 *
 * **This is not redundant with `LockSet`'s runtime check, and deleting it as such would be the
 * mistake it exists to prevent.** `LockSet::acquire()` throws on a tier collision, but only for
 * targets that meet *in one call* — so a pair that shares a tier and is never yet locked together
 * passes every suite, and starts throwing on the day someone writes the feature that locks both.
 * Asserting the property directly moves that discovery to the commit that introduces it, and covers
 * the pairs nothing locks together today.
 *
 * The direction this cannot see is add-ons: core has no way to enumerate what is installed
 * alongside it. Add-on slices assert the other half by reading core's tiers and checking their own
 * band is disjoint. Core keeps **20–59** and grows contiguously; add-ons start at 60.
 */
final class LockTierUniquenessTest extends TestCase
{
    public function testNoTwoRecordsShareALockTier(): void
    {
        $byTier = [];
        foreach (self::recordClasses() as $class) {
            $attrs = (new \ReflectionClass($class))->getAttributes(LockTier::class);
            if ([] === $attrs) {
                continue;
            }
            $byTier[$attrs[0]->newInstance()->tier][] = $class;
        }

        self::assertNotEmpty($byTier, 'no Records with a lock tier were found — the scan is broken');

        $collisions = [];
        foreach ($byTier as $tier => $classes) {
            if (count($classes) > 1) {
                $collisions[] = $tier.': '.implode(', ', array_map(self::short(...), $classes));
            }
        }

        self::assertSame([], $collisions, "two Records share a lock tier, so their lock order is undefined:\n  ".implode("\n  ", $collisions));
    }

    /** Core's band. An add-on's tier here means a slice's Records leaked into core, or the band moved. */
    public function testEveryTierSitsInCoresBand(): void
    {
        foreach (self::recordClasses() as $class) {
            $attrs = (new \ReflectionClass($class))->getAttributes(LockTier::class);
            if ([] === $attrs) {
                continue;
            }
            $tier = $attrs[0]->newInstance()->tier;
            self::assertGreaterThanOrEqual(1, $tier, self::short($class).' has a non-positive tier');
            self::assertLessThanOrEqual(59, $tier, self::short($class).' sits in the add-on band (60+)');
        }
    }

    /**
     * Every concrete Record subclass under `src/`, found by walking the tree rather than by a
     * hand-kept list — a list is exactly what a new Record forgets to join.
     *
     * @return list<class-string<Record>>
     */
    private static function recordClasses(): array
    {
        $root = dirname(__DIR__, 2).'/src';
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'Nandan108\\InvFlux\\'.str_replace('/', '\\', $relative);

            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(Record::class)) {
                continue;
            }
            $found[] = $class;
        }
        sort($found);

        /** @var list<class-string<Record>> $found */
        return $found;
    }

    private static function short(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts);
    }
}
