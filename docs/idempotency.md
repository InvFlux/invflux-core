# Idempotency

InvFlux exposes [`IdempotencyStore`](../src/Contracts/Inventory/IdempotencyStore.php) for application flows that may be invoked more than once even though the underlying business operation must only be applied once.

Typical examples:

- a platform hook that may fire twice for the same order transition
- a callback that may be retried after a timeout even though the first attempt committed successfully
- a workflow where the InvFlux write succeeds but the surrounding platform-side projection or UI update fails afterward

This is not a replacement for transactions.

- [`TransactionalStore`](../src/Contracts/Inventory/TransactionalStore.php) keeps one multi-step persistence operation atomic
- [`IdempotencyStore`](../src/Contracts/Inventory/IdempotencyStore.php) ensures duplicate invocations replay one stored terminal outcome instead of executing the same logical operation twice

## Core API

The core API consists of:

- [`IdempotencyStore`](../src/Contracts/Inventory/IdempotencyStore.php)
- [`IdempotencyKey`](../src/Idempotency/IdempotencyKey.php)
- [`IdempotentOutcome`](../src/Idempotency/IdempotentOutcome.php)
- [`IdempotentExecution`](../src/Idempotency/IdempotentExecution.php)

`IdempotencyKey` identifies one logical operation:

```php
use Nandan108\InvFlux\Idempotency\IdempotencyKey;

$key = new IdempotencyKey(
    scope: 'woo_order_flow',
    operationKey: 'woo-order:123:book_reserved:v1',
);
```

`executeIdempotent(...)` executes a closure behind that key:

```php
use Nandan108\InvFlux\Idempotency\IdempotentOutcome;

$execution = $store->executeIdempotent($key, function () use ($store, $request): IdempotentOutcome {
    $persisted = $store->executeBatchFlowFromStorage($request);

    if (!$persisted->ok) {
        return IdempotentOutcome::retryable('conflict', [
            'conflicts' => $persisted->conflicts,
        ]);
    }

    return IdempotentOutcome::terminal('booked_reserved');
});
```

The returned [`IdempotentExecution`](../src/Idempotency/IdempotentExecution.php) tells the caller whether the outcome was replayed from a prior successful attempt:

```php
if ($execution->replayed) {
    // A prior duplicate invocation already completed this logical operation.
}

if ($execution->terminal) {
    // Safe to project the terminal business outcome into platform state.
}
```

## How To Use It

Use idempotency when all of the following are true:

- the entrypoint may be invoked more than once for the same business transition
- repeating the store mutation would be wrong or at least dangerous
- you can name the logical operation with one stable deterministic key
- you have a meaningful terminal business outcome to replay later

Good keys are semantic and versioned:

- `woo-order:123:reserve:v1`
- `woo-order:123:book_reserved:v1`
- `woo-order:123:book_recovered:v1`

The recommended execution model is:

1. execute the InvFlux mutation inside `executeIdempotent(...)`
2. return a terminal outcome only when the logical operation is complete
3. keep platform-side side effects outside that closure
4. after commit, project the terminal outcome into the host platform if needed

That last point matters. The idempotent closure should stay focused on authoritative persistence work. A duplicate replay should not send the same email twice, append the same order note twice, or trigger the same platform hook cascade twice.

## Terminal vs Retryable Outcomes

`IdempotentOutcome::terminal(...)` means:

- record this outcome under the idempotency key
- future duplicate invocations must skip the closure and replay this stored outcome

`IdempotentOutcome::retryable(...)` means:

- do not keep the claim
- allow a later invocation to execute the closure again

Use retryable outcomes for ordinary non-terminal results such as:

- inventory conflicts that may legitimately be retried later
- upstream preconditions that are not satisfied yet
- transient application-level conditions where “not yet” is not the same as “finished”

## When Not To Use It

Do not use idempotency for every write.

Avoid it when:

- the operation is already naturally safe to repeat
- the caller can cheaply tolerate duplicates without state corruption
- there is no stable semantic key for the logical operation
- the closure performs broad host-platform side effects directly

Also do not treat idempotency as a substitute for transactional design. If several storage changes must succeed or fail together, that is still a transaction problem first.

## Why It Exists In Core

Idempotency is not specific to one platform adapter. Any host environment can create the same failure boundary:

1. the InvFlux mutation commits successfully
2. the surrounding application fails before it records or projects the outcome
3. the host retries the same logical operation

By keeping idempotency in core, adapters can use one consistent mechanism instead of inventing their own duplicate-suppression rules on top of postmeta, sessions, or host-specific bookkeeping.