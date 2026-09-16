<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\InvFlux\Container;
use Nandan108\InvFlux\Container\ContainerException;
use Nandan108\InvFlux\Container\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

final class ContainerTest extends TestCase
{
    public function testImplementsPsr11Interface(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, new Container());
    }

    public function testSetAndGetClosureFactory(): void
    {
        $container = new Container();
        $container->set('svc', static fn (): object => new \stdClass());

        $instance = $container->get('svc');

        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    public function testGetMemoizesInstance(): void
    {
        $container = new Container();
        $container->set('svc', static fn (): object => new \stdClass());

        $first = $container->get('svc');
        $second = $container->get('svc');

        $this->assertSame($first, $second);
    }

    public function testFactoryReceivesContainer(): void
    {
        $container = new Container();
        $captured = null;
        $container->set('svc', function (Container $c) use (&$captured): object {
            $captured = $c;

            return new \stdClass();
        });

        $container->get('svc');

        $this->assertSame($container, $captured);
    }

    public function testGetThrowsNotFoundExceptionForUnregisteredId(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Service "missing" is not registered and is not a known class.');

        $container->get('missing');
    }

    public function testNotFoundExceptionImplementsPsrInterface(): void
    {
        $container = new Container();

        try {
            $container->get('missing');
            $this->fail('Expected NotFoundException');
        } catch (NotFoundExceptionInterface $e) {
            $this->assertInstanceOf(NotFoundExceptionInterface::class, $e);
            $this->assertInstanceOf(ContainerExceptionInterface::class, $e);
        }
    }

    public function testHasReturnsTrueForRegisteredId(): void
    {
        $container = new Container();
        $container->set('svc', static fn (): object => new \stdClass());

        $this->assertTrue($container->has('svc'));
        $this->assertFalse($container->has('missing'));
    }

    public function testTryGetReturnsNullForUnregisteredId(): void
    {
        $container = new Container();

        $this->assertNull($container->tryGet('missing'));
    }

    public function testTryGetReturnsInstanceForRegisteredId(): void
    {
        $container = new Container();
        $instance = new \stdClass();
        $container->set('svc', static fn (): object => $instance);

        $this->assertSame($instance, $container->tryGet('svc'));
    }

    public function testSetWithBareObjectReturnsSameInstance(): void
    {
        $container = new Container();
        $instance = new \stdClass();
        $container->set('svc', $instance);

        $this->assertSame($instance, $container->get('svc'));
        $this->assertSame($instance, $container->get('svc'));
    }

    public function testReRegisterBeforeResolutionReplacesFactory(): void
    {
        $container = new Container();
        $first = new \stdClass();
        $second = new \stdClass();

        // Re-set BEFORE resolution is the add-on override case — the last
        // registration wins, no resolution has happened yet.
        $container->set('svc', static fn (): object => $first);
        $container->set('svc', static fn (): object => $second);

        $this->assertSame($second, $container->get('svc'));
    }

    public function testReRegisterAfterResolutionThrows(): void
    {
        $container = new Container();
        $container->set('svc', static fn (): object => new \stdClass());
        $container->get('svc'); // resolve + memoize

        // Strict lifecycle (matches decorate()): re-registering a resolved
        // service would split-brain consumers. Forbidden.
        $this->expectException(ContainerException::class);
        $container->set('svc', static fn (): object => new \stdClass());
    }

    public function testSetWithSingleTagAttachesTag(): void
    {
        $container = new Container();
        $a = new \stdClass();
        $b = new \stdClass();

        $container->set('a', $a, 'group.x');
        $container->set('b', $b, 'group.x');

        $resolved = iterator_to_array($container->getByTag('group.x'), false);
        $this->assertSame([$a, $b], $resolved);
    }

    public function testSetWithMultipleTagsAttachesAll(): void
    {
        $container = new Container();
        $obj = new \stdClass();
        $container->set('svc', $obj, ['group.x', 'group.y']);

        $this->assertSame([$obj], iterator_to_array($container->getByTag('group.x'), false));
        $this->assertSame([$obj], iterator_to_array($container->getByTag('group.y'), false));
    }

    public function testTagAttachesAfterRegistration(): void
    {
        $container = new Container();
        $obj = new \stdClass();
        $container->set('svc', $obj);
        $container->tag('svc', 'group.x');

        $this->assertSame([$obj], iterator_to_array($container->getByTag('group.x'), false));
    }

    public function testTagIsIdempotent(): void
    {
        $container = new Container();
        $obj = new \stdClass();
        $container->set('svc', $obj);
        $container->tag('svc', 'group.x');
        $container->tag('svc', 'group.x');

        $resolved = iterator_to_array($container->getByTag('group.x'), false);
        $this->assertCount(1, $resolved);
    }

    public function testTagThrowsForUnregisteredService(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $container->tag('missing', 'group.x');
    }

    public function testGetByTagReturnsEmptyForUnknownTag(): void
    {
        $container = new Container();

        $this->assertSame([], iterator_to_array($container->getByTag('nope'), false));
    }

    public function testGetByTagResolvesLazily(): void
    {
        $container = new Container();
        $counter = new ContainerTestCounter();
        $container->set('a', static function () use ($counter): object {
            ++$counter->value;

            return new \stdClass();
        }, 'group.x');
        $container->set('b', static function () use ($counter): object {
            ++$counter->value;

            return new \stdClass();
        }, 'group.x');

        // No resolution should happen until iteration starts
        $iter = $container->getByTag('group.x');
        $this->assertSame(0, $counter->value);

        // Pull only the first; second should NOT be resolved
        foreach ($iter as $svc) {
            $this->assertInstanceOf(\stdClass::class, $svc);
            break;
        }

        /** @psalm-suppress DocblockTypeContradiction — closure mutations untracked by psalm */
        $this->assertSame(1, $counter->value);
    }

    public function testDecorateWrapsResolvedService(): void
    {
        $container = new Container();
        $inner = new \stdClass();
        $inner->name = 'inner';

        $container->set('svc', $inner);
        $container->decorate('svc', static function (object $existing, Container $c): object {
            $wrapped = new \stdClass();
            $wrapped->name = 'wrapped';
            $wrapped->inner = $existing;

            return $wrapped;
        });

        $resolved = $container->get('svc');
        $this->assertSame('wrapped', $resolved->name);
        $this->assertSame($inner, $resolved->inner);
    }

    public function testDecorateChainAppliesInRegistrationOrder(): void
    {
        $container = new Container();
        $container->set('svc', static fn (): object => new ContainerTestNamed('base'));
        $container->decorate('svc', static function (object $i): object {
            \assert($i instanceof ContainerTestNamed);
            $i->name .= ' -> first';

            return $i;
        });
        $container->decorate('svc', static function (object $i): object {
            \assert($i instanceof ContainerTestNamed);
            $i->name .= ' -> second';

            return $i;
        });

        $resolved = $container->get('svc');
        \assert($resolved instanceof ContainerTestNamed);
        $this->assertSame('base -> first -> second', $resolved->name);
    }

    public function testDecorateAfterResolutionThrows(): void
    {
        $container = new Container();
        $container->set('svc', new \stdClass());
        $container->get('svc');

        $this->expectException(ContainerException::class);
        $container->decorate('svc', static fn (object $i): object => $i);
    }

    public function testDecorateThrowsForUnregisteredService(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $container->decorate('missing', static fn (object $i): object => $i);
    }

    public function testFactoryExceptionPropagatesRaw(): void
    {
        $container = new Container();
        $container->set('svc', static function (): object {
            throw new \LogicException('boom');
        });

        // Container deliberately does NOT wrap factory exceptions — call
        // sites can catch domain-specific types unchanged. See Container.php
        // for the rationale.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('boom');
        $container->get('svc');
    }

    public function testNotFoundDuringFactoryPropagatesWithoutWrapping(): void
    {
        $container = new Container();
        $container->set('svc', static function (Container $c): object {
            return $c->get('missing'); // bubbles up
        });

        $this->expectException(NotFoundException::class);
        $container->get('svc');
    }

    // ------------------------------------------------------------------
    // Autowire fallback
    // ------------------------------------------------------------------

    public function testAutowireResolvesNoConstructorClass(): void
    {
        $container = new Container();

        $instance = $container->get(AutowireNoCtor::class);

        $this->assertInstanceOf(AutowireNoCtor::class, $instance);
    }

    public function testAutowireResolvesClassWithResolvableDeps(): void
    {
        $container = new Container();
        // AutowireB depends on AutowireA — both have no-arg constructors,
        // both are autowireable transitively.
        $instance = $container->get(AutowireB::class);

        $this->assertInstanceOf(AutowireB::class, $instance);
        $this->assertInstanceOf(AutowireA::class, $instance->a);
    }

    public function testAutowireMemoizesResult(): void
    {
        $container = new Container();

        $first = $container->get(AutowireA::class);
        $second = $container->get(AutowireA::class);

        $this->assertSame($first, $second);
    }

    public function testAutowireReusesSharedDependency(): void
    {
        $container = new Container();

        // Both AutowireB and AutowireC depend on AutowireA — autowire
        // should resolve the same memoized AutowireA for both.
        $b = $container->get(AutowireB::class);
        $c = $container->get(AutowireC::class);

        $this->assertSame($b->a, $c->a);
    }

    public function testExplicitFactoryOverridesAutowire(): void
    {
        $container = new Container();
        $custom = new AutowireA();
        $container->set(AutowireA::class, $custom);

        $resolved = $container->get(AutowireA::class);

        $this->assertSame($custom, $resolved);
    }

    public function testAutowireThrowsOnScalarParamWithoutDefault(): void
    {
        $container = new Container();

        try {
            $container->get(AutowireScalarRequired::class);
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString('AutowireScalarRequired', $e->getMessage());
            $this->assertStringContainsString('$count', $e->getMessage());
            $this->assertStringContainsString('int', $e->getMessage());
        }
    }

    public function testAutowireUsesDefaultValueForScalarParam(): void
    {
        $container = new Container();

        $instance = $container->get(AutowireScalarWithDefault::class);

        $this->assertSame(42, $instance->count);
    }

    public function testAutowireUsesDefaultValueForUnresolvableClassParam(): void
    {
        // When a constructor declares a class param that the container can't
        // resolve (here: an interface with no registered impl), AND the param
        // has a default value, autowire falls back to the default rather than
        // erroring.
        $container = new Container();

        $instance = $container->get(AutowireOptionalInterfaceDep::class);

        $this->assertNull($instance->svc);
    }

    public function testAutowireUsesNullForNullableUnresolvableClassParam(): void
    {
        $container = new Container();

        $instance = $container->get(AutowireNullableInterfaceDep::class);

        $this->assertNull($instance->svc);
    }

    public function testAutowireResolvesEagerlyWhenDepIsRegisterableEvenWithDefault(): void
    {
        // If the param's declared class IS resolvable (autowireable concrete
        // class), autowire prefers resolving it over using the default.
        $container = new Container();

        $instance = $container->get(AutowireOptionalAutowireableDep::class);

        $this->assertInstanceOf(AutowireA::class, $instance->a);
    }

    public function testAutowireThrowsOnInterfaceParam(): void
    {
        $container = new Container();

        try {
            $container->get(AutowireInterfaceDep::class);
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString('AutowireInterfaceDep', $e->getMessage());
            $this->assertStringContainsString('AutowireSomeInterface', $e->getMessage());
        }
    }

    public function testHasReturnsTrueForAutowireableConcreteClass(): void
    {
        $container = new Container();

        $this->assertTrue($container->has(AutowireA::class));
    }

    public function testHasReturnsFalseForInterface(): void
    {
        $container = new Container();

        $this->assertFalse($container->has(AutowireSomeInterface::class));
    }

    public function testHasReturnsFalseForUnknownClass(): void
    {
        $container = new Container();

        $this->assertFalse($container->has('Nonexistent\\Service'));
    }

    public function testAutowireDetectsCircularDependency(): void
    {
        $container = new Container();
        // CycleA depends on CycleB; CycleB depends on CycleA.
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency detected');
        $container->get(CycleA::class);
    }

    public function testAutowireThrowsOnNoTypeParam(): void
    {
        $container = new Container();

        try {
            $container->get(AutowireNoType::class);
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString('AutowireNoType', $e->getMessage());
            $this->assertStringContainsString('$mystery', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Scalar-by-name binding (autowire extension)
    // ------------------------------------------------------------------

    public function testAutowireResolvesScalarParamByName(): void
    {
        $container = new Container();
        $container->set('tablePrefix', 'wp_');

        $instance = $container->get(NeedsTablePrefix::class);

        $this->assertSame('wp_', $instance->tablePrefix);
    }

    public function testAutowireResolvesScalarParamByNameFromFactory(): void
    {
        $container = new Container();
        $container->set('tablePrefix', static fn (Container $c): string => 'wp_');

        $instance = $container->get(NeedsTablePrefix::class);

        $this->assertSame('wp_', $instance->tablePrefix);
    }

    public function testScalarBindingMemoizesAcrossCalls(): void
    {
        $container = new Container();
        $callCount = new ContainerTestCounter();
        $container->set('tablePrefix', static function () use ($callCount): string {
            ++$callCount->value;

            return 'wp_';
        });

        $container->get(NeedsTablePrefix::class);
        $container->get(NeedsTablePrefix::class);

        /** @psalm-suppress DocblockTypeContradiction — closure mutations untracked by psalm */
        $this->assertSame(1, $callCount->value);
    }

    public function testAutowireUsesDefaultForUnboundScalarParam(): void
    {
        $container = new Container();
        // No binding for $tablePrefix; AutowireScalarWithDefault has int default 42
        $instance = $container->get(AutowireScalarWithDefault::class);

        $this->assertSame(42, $instance->count);
    }

    public function testGetReturnsScalarValuesDirectly(): void
    {
        $container = new Container();
        $container->set('answer', 42);

        $this->assertSame(42, $container->get('answer'));
    }

    public function testGetReturnsNullScalarBinding(): void
    {
        $container = new Container();
        $container->set('maybeNull', null);

        $this->assertNull($container->get('maybeNull'));
    }

    // ------------------------------------------------------------------
    // Alias shorthand — set(A::class, B::class) when B is a class/interface
    // ------------------------------------------------------------------

    public function testSetWithClassStringValueAliasesToThatClass(): void
    {
        $container = new Container();
        $container->set(AutowireSomeInterface::class, AliasConcrete::class);

        $resolved = $container->get(AutowireSomeInterface::class);

        $this->assertInstanceOf(AliasConcrete::class, $resolved);
        $this->assertInstanceOf(AutowireSomeInterface::class, $resolved);
    }

    public function testAliasIsLazy(): void
    {
        $container = new Container();
        // Register a non-autowireable target — verify no error at set() time
        // (target only resolved when get() on the alias is called).
        $container->set('lazyAlias', AliasConcrete::class);

        // No error yet
        $this->assertTrue($container->has('lazyAlias'));

        // Resolve and verify it returns an instance of the target
        $resolved = $container->get('lazyAlias');
        $this->assertInstanceOf(AliasConcrete::class, $resolved);
    }

    public function testAliasRespectsExplicitOverrideOfTarget(): void
    {
        $container = new Container();
        $customInstance = new AliasConcrete();
        $container->set(AliasConcrete::class, $customInstance);
        $container->set(AutowireSomeInterface::class, AliasConcrete::class);

        $resolved = $container->get(AutowireSomeInterface::class);

        $this->assertSame($customInstance, $resolved);
    }

    public function testNonClassStringIsStoredAsLiteralValue(): void
    {
        $container = new Container();
        $container->set('greeting', 'Hello, world');

        $this->assertSame('Hello, world', $container->get('greeting'));
    }

    public function testClassStringCanBeBoundAsLiteralViaClosure(): void
    {
        // Documented escape hatch for the rare case where you want to bind
        // a class FQN as a literal string value (not an alias).
        $container = new Container();
        $container->set('errorClass', static fn (): string => AliasConcrete::class);

        $this->assertSame(AliasConcrete::class, $container->get('errorClass'));
    }

    // ------------------------------------------------------------------
    // Closure parameter injection (factories get autowired params)
    // ------------------------------------------------------------------

    public function testFactoryClosureGetsClassParamsInjected(): void
    {
        $container = new Container();
        $container->set(
            'composite',
            static fn (AutowireA $a, AutowireB $b): AutowireC => new AutowireC($b->a),
        );

        $result = $container->get('composite');

        $this->assertInstanceOf(AutowireC::class, $result);
    }

    public function testFactoryClosureGetsContainerInjected(): void
    {
        $container = new Container();
        $container->set('captured', static fn (Container $c): Container => $c);

        $resolved = $container->get('captured');

        $this->assertSame($container, $resolved);
    }

    public function testFactoryClosureGetsScalarParamByName(): void
    {
        $container = new Container();
        $container->set('tablePrefix', 'wp_');
        $container->set(
            'composite',
            static fn (string $tablePrefix): string => $tablePrefix.'users',
        );

        $this->assertSame('wp_users', $container->get('composite'));
    }

    public function testFactoryClosureMixesClassAndScalarInjection(): void
    {
        $container = new Container();
        $container->set('tablePrefix', 'wp_');
        $container->set(
            NeedsTablePrefix::class,
            static fn (string $tablePrefix): NeedsTablePrefix => new NeedsTablePrefix($tablePrefix.'override'),
        );

        $this->assertSame('wp_override', $container->get(NeedsTablePrefix::class)->tablePrefix);
    }

    public function testZeroParamFactoryClosureRunsDirectly(): void
    {
        $container = new Container();
        $container->set('greeting', static fn (): string => 'hi');

        $this->assertSame('hi', $container->get('greeting'));
    }

    public function testDecoratorClosureGetsInnerPlusInjectedExtras(): void
    {
        $container = new Container();
        $container->set('tablePrefix', 'wp_');
        $container->set('svc', new ContainerTestNamed('base'));
        $container->decorate('svc', static function (object $inner, string $tablePrefix): object {
            \assert($inner instanceof ContainerTestNamed);
            $inner->name = $tablePrefix.$inner->name;

            return $inner;
        });

        $resolved = $container->get('svc');
        \assert($resolved instanceof ContainerTestNamed);
        $this->assertSame('wp_base', $resolved->name);
    }
}

final class ContainerTestNamed
{
    public function __construct(public string $name)
    {
    }
}

final class ContainerTestCounter
{
    public int $value = 0;
}

// ------------------------------------------------------------------
// Autowire test fixtures
// ------------------------------------------------------------------

final class AutowireNoCtor
{
}

final class AutowireA
{
}

final class AutowireB
{
    public function __construct(public AutowireA $a)
    {
    }
}

final class AutowireC
{
    public function __construct(public AutowireA $a)
    {
    }
}

final class AutowireScalarRequired
{
    public function __construct(public int $count)
    {
    }
}

final class AutowireScalarWithDefault
{
    public function __construct(public int $count = 42)
    {
    }
}

interface AutowireSomeInterface
{
}

final class AutowireInterfaceDep
{
    public function __construct(public AutowireSomeInterface $svc)
    {
    }
}

final class AutowireOptionalInterfaceDep
{
    public function __construct(public ?AutowireSomeInterface $svc = null)
    {
    }
}

final class AutowireNullableInterfaceDep
{
    public function __construct(public ?AutowireSomeInterface $svc)
    {
    }
}

final class AutowireOptionalAutowireableDep
{
    public function __construct(public ?AutowireA $a = null)
    {
    }
}

final class CycleA
{
    public function __construct(public CycleB $b)
    {
    }
}

final class CycleB
{
    public function __construct(public CycleA $a)
    {
    }
}

final class AutowireNoType
{
    /**
     * @psalm-param mixed $mystery
     */
    public function __construct(public $mystery)
    {
    }
}

final class NeedsTablePrefix
{
    public function __construct(public string $tablePrefix)
    {
    }
}

final class AliasConcrete implements AutowireSomeInterface
{
}
