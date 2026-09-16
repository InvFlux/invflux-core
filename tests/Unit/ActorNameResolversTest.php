<?php

declare(strict_types=1);

namespace Tests\Unit;

use Nandan108\InvFlux\Contracts\ActorNameResolver;
use Nandan108\InvFlux\Registry\ActorNameResolvers;
use PHPUnit\Framework\TestCase;

/** @psalm-suppress UnusedClass — discovered by PHPUnit. */
final class ActorNameResolversTest extends TestCase
{
    public function testResolvesEachKindThroughItsOwnResolver(): void
    {
        $registry = new ActorNameResolvers();
        $registry->register('user', self::resolver(['1' => 'Sam', '2' => 'Alex']));
        $registry->register('supplier', self::resolver(['9' => 'Acme Ltd']));

        $out = $registry->resolve(['user' => ['1', '2'], 'supplier' => ['9']]);

        self::assertSame(['1' => 'Sam', '2' => 'Alex'], $out['user']);
        self::assertSame(['9' => 'Acme Ltd'], $out['supplier']);
    }

    public function testAKindWithNoResolverIsAbsentRatherThanEmpty(): void
    {
        // The caller distinguishes "nobody can name this kind" from "named as nothing", and falls
        // back to showing the reference. An empty entry would read as a resolved blank.
        $registry = new ActorNameResolvers();
        $out = $registry->resolve(['plugin' => ['woocommerce']]);

        self::assertArrayNotHasKey('plugin', $out);
    }

    public function testAnUnresolvableReferenceIsOmittedNotPlaceheld(): void
    {
        $registry = new ActorNameResolvers();
        $registry->register('user', self::resolver(['1' => 'Sam']));

        $out = $registry->resolve(['user' => ['1', '404']]);

        self::assertSame(['1' => 'Sam'], $out['user']);
    }

    public function testAResolverIsAskedOncePerKindWithDistinctRefs(): void
    {
        // The whole point of the bulk shape: a page of events costs one call per kind, and a
        // reference repeated across fifty rows is asked about once.
        $calls = [];
        $registry = new ActorNameResolvers();
        $registry->register('user', new class($calls) implements ActorNameResolver {
            /** @param list<list<string>> $calls */
            public function __construct(public array &$calls)
            {
            }

            #[\Override]
            public function namesFor(array $refs): array
            {
                $this->calls[] = $refs;

                return [];
            }
        });

        $registry->resolve(['user' => ['1', '1', '2', '1']]);

        self::assertCount(1, $calls, 'one call per kind');
        self::assertSame(['1', '2'], $calls[0], 'duplicates collapsed before asking');
    }

    public function testAThrowingResolverCostsItsOwnKindAndNothingElse(): void
    {
        // Names are a courtesy on a record that reads fine without them, so one broken contribution
        // must not take down the page or its neighbours' names.
        $registry = new ActorNameResolvers();
        $registry->register('user', new class implements ActorNameResolver {
            #[\Override]
            public function namesFor(array $refs): array
            {
                throw new \RuntimeException('identity service down');
            }
        });
        $registry->register('supplier', self::resolver(['9' => 'Acme Ltd']));

        $out = $registry->resolve(['user' => ['1'], 'supplier' => ['9']]);

        self::assertArrayNotHasKey('user', $out);
        self::assertSame(['9' => 'Acme Ltd'], $out['supplier']);
    }

    public function testLastRegistrationWinsSoASiteCanOverrideTheHost(): void
    {
        $registry = new ActorNameResolvers();
        $registry->register('user', self::resolver(['1' => 'From the host']));
        $registry->register('user', self::resolver(['1' => 'From the add-on']));

        self::assertSame(['1' => 'From the add-on'], $registry->resolve(['user' => ['1']])['user']);
    }

    public function testAnEmptyReferenceListSkipsTheResolverEntirely(): void
    {
        $called = false;
        $registry = new ActorNameResolvers();
        $registry->register('user', new class($called) implements ActorNameResolver {
            public function __construct(public bool &$called)
            {
            }

            #[\Override]
            public function namesFor(array $refs): array
            {
                $this->called = true;

                return [];
            }
        });

        $registry->resolve(['user' => []]);

        self::assertFalse($called);
    }

    public function testHasReportsRegistration(): void
    {
        $registry = new ActorNameResolvers();
        self::assertFalse($registry->has('user'));
        $registry->register('user', self::resolver([]));
        self::assertTrue($registry->has('user'));
    }

    /** @param array<array-key, string> $names */
    private static function resolver(array $names): ActorNameResolver
    {
        return new class($names) implements ActorNameResolver {
            /** @param array<array-key, string> $names */
            public function __construct(private readonly array $names)
            {
            }

            #[\Override]
            public function namesFor(array $refs): array
            {
                return array_intersect_key($this->names, array_flip($refs));
            }
        };
    }
}
