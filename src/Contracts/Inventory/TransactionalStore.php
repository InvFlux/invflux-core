<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Inventory;

/**
 * Execute multi-step operations inside one atomic persistence transaction,
 * or under an advisory lock for concurrency control outside transactions.
 *
 * @api
 */
interface TransactionalStore
{
    /**
     * Execute one operation transactionally.
     *
     * Nested calls reuse the current transaction and only the outermost call commits or rolls back.
     *
     * @template TResult
     *
     * @param \Closure(): TResult $operation
     *
     * @return TResult
     */
    public function transactional(\Closure $operation): mixed;

    /**
     * Acquire a named advisory lock, execute a callback, then release the lock.
     *
     * $timeoutSeconds: 0 = fail immediately, positive = wait up to N seconds, -1 = indefinite.
     * Throws when the lock cannot be acquired within the timeout.
     *
     * @template TResult
     *
     * @param \Closure(): TResult $callback
     *
     * @return TResult
     */
    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed;
}
