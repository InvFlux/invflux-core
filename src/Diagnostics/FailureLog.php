<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Diagnostics;

/**
 * Sink for a failure that was caught and deliberately not rethrown.
 *
 * InvFlux runs inside hosts it does not control, and some paths must not fail loudly: a projection
 * that throws while the host is saving an order cannot take the save down with it, and a queue tick
 * that throws on one row must still process the rest. Those paths catch broadly on purpose — and
 * what they owe in exchange is a record, because a silently swallowed failure in an inventory
 * system reads as correct stock right up until it doesn't. Much of the drift that {@see
 * DiagnosticCheck} exists to find begins life as exactly this.
 *
 * The contract is only "write it down somewhere an operator will look". Where that is belongs to
 * the host: a host with a log viewer of its own should use it in preference to the PHP error log,
 * which on many installations is a file the operator cannot reach.
 *
 * Two obligations on implementations, both because callers are already handling a failure:
 * recording must be cheap, and it must not throw. A sink that raises turns a contained problem
 * into the outage the catch was there to prevent.
 *
 * This is not a debug or tracing channel, and not a metric. It carries failures that happened,
 * were absorbed, and would otherwise leave no trace.
 *
 * @psalm-suppress UnusedClass — a contract core declares for its dependents rather than for
 *                 itself. Core has no catch-and-continue path of its own; the implementors and
 *                 callers are the host adapters and the storage packages, both of which depend on
 *                 core, which is precisely why the contract has to live here and not in either.
 */
interface FailureLog
{
    /**
     * Record one swallowed failure.
     *
     * @param string          $message what failed, in the operator's terms, with the identifiers
     *                                 needed to find it again ("dispatch projection failed for
     *                                 order 41") — not the exception's own text, which $cause
     *                                 carries and the implementation is free to render its own way
     * @param \Throwable|null $cause   the exception that was absorbed, when there was one
     */
    public function record(string $message, ?\Throwable $cause = null): void;
}
