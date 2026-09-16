<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * One reason a subject's platform record may not be hard-deleted yet.
 *
 * A blocker is *remediable*: it names the workflow the operator must complete
 * before deletion becomes possible (write off the stock, fulfill-or-cancel the
 * order, close the PO). The block is never a permanent "no".
 *
 * `type` is an open string (not a closed enum) so add-ons can contribute their
 * own blocker kinds through the `invflux_subject_deletion_blockers` filter
 * (a Channel-Sync live listing, an open supplier claim, a future RMA
 * return-window override). Core's built-in types are the `TYPE_*` constants on
 * {@see SubjectDeletionGuard}.
 *
 * `remediation` is a machine slug naming the clearing workflow (the `REMEDIATION_*`
 * constants); the presentation layer maps it to a localized operator string.
 *
 * @api
 */
final class DeletionBlocker
{
    public function __construct(
        public readonly string $type,
        public readonly int $count,
        public readonly string $remediation,
    ) {
    }
}
