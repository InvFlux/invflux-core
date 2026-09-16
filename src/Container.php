<?php

declare(strict_types=1);

namespace Nandan108\InvFlux;

use Nandan108\InvFlux\Container\ContainerException;
use Nandan108\InvFlux\Container\NotFoundException;
use Psr\Container\ContainerInterface;

/**
 * Service container — InvFlux's primary extension point.
 *
 * The `invflux_container_built` extension lifecycle and canonical usage
 * examples (LicenseGate swap, tag-based pluggable lists, decoration) are
 * documented with the container contract.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, \Closure> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, list<string>> tag => service ids */
    private array $servicesByTag = [];

    /** @var array<string, list<\Closure>> id => decorators in registration order */
    private array $decorators = [];

    /** @var array<string, true> ids currently being resolved (cycle detection) */
    private array $resolving = [];

    public function __construct()
    {
        // Self-register so factories and constructors that declare a
        // `Container` parameter (or `self`) get this instance via autowire,
        // rather than the container trying to instantiate a fresh one.
        $this->instances[self::class] = $this;
    }

    /**
     * Register a service factory or singleton instance, optionally tagged.
     *
     * Bare objects (non-closures) are wrapped internally as factories that
     * always return the same instance.
     *
     * Re-registering an id BEFORE it has been resolved replaces the factory
     * (the override mechanism add-ons use during `invflux_container_built`).
     * Re-registering AFTER resolution THROWS — same strict lifecycle as
     * `decorate()`: registration must happen before resolution (no late
     * binding). This prevents split-brain where some consumers hold the old
     * instance and later callers get a rebuilt one.
     *
     * @param mixed                    $factoryOrValue A `\Closure(self): mixed` factory, OR a
     *                                                 string FQN of a known class/interface
     *                                                 (auto-converted to a lazy alias that
     *                                                 resolves via `get($target)` at fetch time),
     *                                                 OR any other bare value (object, scalar
     *                                                 other than class strings, array, null)
     *                                                 to register as a singleton. To bind a class
     *                                                 FQN as a literal *string*, wrap it in a
     *                                                 closure: `set('errorClass', fn() => Foo::class)`.
     * @param string|list<string>|null $tag            Tag or list of tags to attach
     *
     * @psalm-param \Closure(self): mixed|mixed $factoryOrValue
     */
    public function set(
        string $id,
        mixed $factoryOrValue,
        string | array | null $tag = null,
    ): void {
        // Strict lifecycle (matches decorate()): re-registering a service that
        // has already been resolved would create split-brain — consumers holding
        // the old instance vs. callers getting a rebuilt one. Forbid it. Re-set
        // BEFORE resolution (the add-on override case) is fine and falls through.
        if (\array_key_exists($id, $this->instances)) {
            throw new ContainerException(\sprintf(
                'Cannot re-register "%s" — service has already been resolved. '
                .'Registration must happen before resolution (no late binding).',
                $id,
            ));
        }

        if ($factoryOrValue instanceof \Closure) {
            $this->factories[$id] = $factoryOrValue;
        } elseif (\is_string($factoryOrValue) && (\class_exists($factoryOrValue) || \interface_exists($factoryOrValue))) {
            // String pointing at a known class or interface → treat as a lazy
            // alias to that type. Resolves through get() at fetch time, so
            // explicit overrides AND autowire of the target both work.
            $target = $factoryOrValue;
            $this->factories[$id] = static fn (Container $c): mixed => $c->get($target);
        } else {
            /** @psalm-suppress MixedAssignment — bare-value bindings are intentionally untyped */
            $value = $factoryOrValue;
            $this->factories[$id] = static fn (): mixed => $value;
        }

        if (null !== $tag) {
            $tags = \is_array($tag) ? $tag : [$tag];
            foreach ($tags as $t) {
                $this->tag($id, $t);
            }
        }
    }

    /**
     * Resolve a service. Memoizes on first call.
     *
     * Resolution order:
     *   1. Memoized instance (return as-is)
     *   2. Registered factory (call factory, apply decorators, memoize)
     *   3. Autowire fallback: if $id is a class-string for an instantiable
     *      class whose constructor params are all themselves resolvable
     *      through this container (or themselves autowireable), construct
     *      it via reflection.
     *
     * @template T of object
     *
     * @param string $id (typically a class-string<T>, in which case psalm narrows the return to T)
     *
     * @psalm-param class-string<T>|string $id
     *
     * @psalm-return ($id is class-string<T> ? T : mixed)
     *
     * @throws NotFoundException  if no factory is registered for $id and autowire fails
     * @throws ContainerException if a circular dependency is detected, or a factory or decorator throws
     */
    #[\Override]
    public function get(string $id): mixed
    {
        if (\array_key_exists($id, $this->instances)) {
            /** @psalm-suppress MixedReturnStatement — memoized values are intentionally mixed */
            return $this->instances[$id];
        }

        if (isset($this->resolving[$id])) {
            $chain = implode(' -> ', array_keys($this->resolving)).' -> '.$id;
            throw new ContainerException(\sprintf('Circular dependency detected: %s', $chain));
        }

        $this->resolving[$id] = true;
        try {
            // Factory or decorator exceptions propagate raw — we deliberately
            // do NOT wrap them in ContainerException so call sites can catch
            // domain-specific exception types unchanged. PSR-11 permits but
            // does not require wrapping; for InvFlux the rawer signal wins.
            if (isset($this->factories[$id])) {
                /** @psalm-suppress MixedAssignment — factory return type is intentionally mixed */
                $value = $this->invokeFactory($id, $this->factories[$id]);
            } else {
                $value = $this->autowire($id);
            }
            foreach ($this->decorators[$id] ?? [] as $decorator) {
                /** @psalm-suppress MixedAssignment — decorator chain operates on mixed values */
                $value = $this->invokeDecorator($id, $decorator, $value);
            }
        } finally {
            unset($this->resolving[$id]);
        }

        $this->instances[$id] = $value;

        /** @psalm-suppress MixedReturnStatement — factory result is intentionally mixed */
        return $value;
    }

    /**
     * Reports whether `get($id)` can return a service without throwing
     * NotFoundException. Returns true for:
     *   - any registered factory, OR
     *   - any class-string for an instantiable concrete class (autowire
     *     candidate). Note: this does NOT recursively verify the class's
     *     constructor params are resolvable — that check happens at
     *     autowire time. So `has()` is optimistic for autowire candidates;
     *     the subsequent `get()` may still throw if a constructor param
     *     can't be resolved.
     */
    #[\Override]
    public function has(string $id): bool
    {
        if (isset($this->factories[$id])) {
            return true;
        }

        if (class_exists($id)) {
            try {
                return (new \ReflectionClass($id))->isInstantiable();
            } catch (\ReflectionException) {
                return false;
            }
        }

        return false;
    }

    /**
     * Invoke a factory closure with its parameters injected via the same
     * autowire rules used for constructors. The factory may declare any
     * mix of class-typed and scalar-by-name parameters; each is resolved
     * via resolveParameter().
     *
     * Closures with no parameters are invoked directly (no reflection
     * cost).
     */
    private function invokeFactory(string $id, \Closure $factory): mixed
    {
        $reflection = new \ReflectionFunction($factory);
        $params = $reflection->getParameters();
        if ([] === $params) {
            /** @psalm-suppress MixedReturnStatement — factory return is intentionally mixed */
            return $factory();
        }

        $args = [];
        foreach ($params as $param) {
            /** @psalm-suppress MixedAssignment — injected arg type depends on the param */
            $args[] = $this->resolveParameter($id, $param);
        }

        /** @psalm-suppress MixedReturnStatement — factory return is intentionally mixed */
        return $factory(...$args);
    }

    /**
     * Invoke a decorator closure. The first parameter is always the inner
     * (previously-resolved) value, passed positionally. Remaining parameters
     * are injected via resolveParameter() like a factory.
     *
     * This preserves the canonical decorator signature
     * `fn (mixed $inner, Container $c): mixed` (Container resolves to $this
     * via self-registration) while also allowing richer signatures like
     * `fn (mixed $inner, LoggerInterface $log, string $tablePrefix): mixed`.
     */
    private function invokeDecorator(string $id, \Closure $decorator, mixed $inner): mixed
    {
        $reflection = new \ReflectionFunction($decorator);
        $params = $reflection->getParameters();
        if ([] === $params) {
            // Degenerate decorator with no params — call with no args (it
            // can't see the inner, but technically valid).
            /** @psalm-suppress MixedReturnStatement */
            return $decorator();
        }

        // First param is always the inner instance, passed positionally.
        $args = [$inner];
        foreach (\array_slice($params, 1) as $param) {
            /** @psalm-suppress MixedAssignment */
            $args[] = $this->resolveParameter($id, $param);
        }

        /** @psalm-suppress MixedReturnStatement */
        return $decorator(...$args);
    }

    /**
     * Resolve a class via reflection — autowire fallback when no factory
     * is registered.
     *
     * @psalm-param class-string|string $id
     *
     * @throws NotFoundException if the class doesn't exist, isn't
     *                           instantiable, or has a constructor param
     *                           that isn't resolvable (scalar without
     *                           default, unregistered interface, etc.)
     */
    private function autowire(string $id): object
    {
        if (!class_exists($id)) {
            throw new NotFoundException(\sprintf(
                'Service "%s" is not registered and is not a known class.',
                $id,
            ));
        }

        $reflection = new \ReflectionClass($id);
        if (!$reflection->isInstantiable()) {
            throw new NotFoundException(\sprintf(
                'Cannot autowire "%s": class is not instantiable (interface or abstract). '
                .'Register a concrete impl via set().',
                $id,
            ));
        }

        $constructor = $reflection->getConstructor();
        if (null === $constructor) {
            return $reflection->newInstance();
        }

        /** @var list<mixed> $args */
        $args = [];
        foreach ($constructor->getParameters() as $param) {
            /** @psalm-suppress MixedAssignment — autowired arg type depends on the param */
            $args[] = $this->resolveParameter($id, $param);
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * Resolve a single constructor parameter during autowiring.
     *
     * Rules (in order):
     *   1. Named class/interface type, resolvable via container → resolve recursively by type
     *   2. Builtin (scalar/array) type, container has a binding for the parameter NAME
     *      (e.g. `$container->set('tablePrefix', fn () => 'wp_')`) → resolve by name
     *   3. Param has a default value → use the default
     *   4. Param is nullable (allows null) → use null
     *   5. Otherwise → throw NotFoundException with a message pointing at this param
     *
     * Union and intersection types are not supported in autowire and
     * trigger an error pointing at the offending param.
     *
     * Scalar-by-name resolution (rule 2) is useful for binding values like
     * a table prefix or a config string globally without writing a closure
     * per consumer. The parameter name is globally meaningful in this
     * pattern — use descriptive names (`$wpTablePrefix` rather than `$name`)
     * to avoid cross-service collisions.
     */
    private function resolveParameter(string $serviceId, \ReflectionParameter $param): mixed
    {
        $type = $param->getType();

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();
            if ($this->has($typeName)) {
                return $this->get($typeName);
            }
            // Class-typed param but the class isn't resolvable.
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }
            if ($type->allowsNull()) {
                return null;
            }
            throw new NotFoundException(\sprintf(
                'Cannot autowire "%s": param $%s requires %s, which is not registered. '
                .'Register a factory for %s via set().',
                $serviceId,
                $param->getName(),
                $typeName,
                $typeName,
            ));
        }

        // Builtin (scalar/array) type — try resolving by parameter name first.
        if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
            $paramName = $param->getName();
            if ($this->has($paramName)) {
                /** @psalm-suppress MixedReturnStatement — scalar bindings are intentionally untyped at this layer */
                return $this->get($paramName);
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if (null !== $type && $type->allowsNull()) {
            return null;
        }

        $typeDescription = $this->describeType($type);
        throw new NotFoundException(\sprintf(
            'Cannot autowire "%s": param $%s (%s) has no resolvable type and no default value. '
            .'Register a factory via set() or bind by name ($container->set(%s, ...)).',
            $serviceId,
            $param->getName(),
            $typeDescription,
            \var_export($param->getName(), true),
        ));
    }

    private function describeType(?\ReflectionType $type): string
    {
        if (null === $type) {
            return 'no type';
        }
        if ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin() ? $type->getName() : 'unregistered '.$type->getName();
        }
        if ($type instanceof \ReflectionUnionType) {
            return 'union type';
        }
        if ($type instanceof \ReflectionIntersectionType) {
            return 'intersection type';
        }

        return 'unknown';
    }

    /**
     * Return the service if registered, null otherwise — for optional dependencies.
     *
     * @template T of object
     *
     * @psalm-param class-string<T>|string $id
     *
     * @psalm-return ($id is class-string<T> ? T|null : mixed)
     *
     * @throws ContainerException if a factory or decorator throws
     */
    public function tryGet(string $id): mixed
    {
        /** @psalm-suppress MixedReturnStatement — service value is intentionally mixed */
        return $this->has($id) ? $this->get($id) : null;
    }

    /**
     * Add a tag to an already-registered service. Idempotent — tagging the
     * same (id, tag) pair twice has no additional effect.
     *
     * @throws NotFoundException if $id is not registered
     */
    public function tag(string $id, string $tag): void
    {
        if (!isset($this->factories[$id])) {
            throw new NotFoundException(\sprintf('Cannot tag "%s" — service is not registered.', $id));
        }

        $existing = $this->servicesByTag[$tag] ?? [];
        if (!\in_array($id, $existing, true)) {
            $existing[] = $id;
            $this->servicesByTag[$tag] = $existing;
        }
    }

    /**
     * Iterate services with the given tag. Resolution is lazy per item —
     * each service is resolved on iteration. Consumer may stop early.
     *
     * Typical use is for tags grouping object services (projection
     * participants, registrars, etc.), but scalar/array bindings can
     * also be tagged and iterated.
     *
     * @return iterable<mixed>
     */
    public function getByTag(string $tag): iterable
    {
        foreach ($this->servicesByTag[$tag] ?? [] as $id) {
            yield $this->get($id);
        }
    }

    /**
     * Wrap an already-registered service with a decorator. The decorator
     * receives the existing (possibly already-decorated) service plus the
     * container, and returns a replacement that wraps it.
     *
     * Multiple decorate() calls on the same id stack in registration order:
     * the first registered decorator runs innermost, the last outermost.
     *
     * Must be called before the service is resolved.
     *
     * The decorator's FIRST parameter is the inner value (positional). Any
     * additional parameters are injected via the same autowire rules as
     * constructors and factories — class-typed params resolve by type,
     * scalar-typed params resolve by name.
     *
     * @throws ContainerException if the service has already been resolved
     * @throws NotFoundException  if $id is not registered
     */
    public function decorate(string $id, \Closure $decorator): void
    {
        if (!isset($this->factories[$id])) {
            throw new NotFoundException(\sprintf('Cannot decorate "%s" — service is not registered.', $id));
        }
        if (\array_key_exists($id, $this->instances)) {
            throw new ContainerException(\sprintf('Cannot decorate "%s" — service has already been resolved.', $id));
        }
        $this->decorators[$id][] = $decorator;
    }
}
