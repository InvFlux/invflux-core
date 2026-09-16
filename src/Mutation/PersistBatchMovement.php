<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Mutation;

use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\SlotFlow\Batch\QuantityStateBatch;

/**
 * Describe one multi-subject persistence request.
 *
 * @api
 */
final class PersistBatchMovement
{
    private ?\DateTimeImmutable $resolvedRecordedAt = null;

    /**
     * Build one batch persist command.
     *
     * @param \Closure(mixed): SubjectId                         $subjectIdResolver
     * @param array<int, array<non-empty-string, QuantityGuard>> $guardsBySubjectIdAndSlotKey
     */
    public function __construct(
        public readonly QuantityStateBatch $movementBatch,
        public readonly \Closure $subjectIdResolver,
        public readonly string $movementTypeOwnerKey,
        public readonly string $movementTypeCode,
        public readonly ?EntityReference $reference = null,
        public readonly ?ActorReference $actor = null,
        public readonly array $guardsBySubjectIdAndSlotKey = [],
        public readonly ?\DateTimeImmutable $recordedAt = null,
        public readonly ?SurfaceReference $surface = null,
    ) {
        '' !== trim($this->movementTypeOwnerKey)
            || throw new ConfigurationException('movementTypeOwnerKey must be a non-empty string.', 'empty_movement_type_owner_key');

        '' !== trim($this->movementTypeCode)
            || throw new ConfigurationException('movementTypeCode must be a non-empty string.', 'empty_movement_type_code');
    }

    /** Resolve and memoize the operation timestamp for this command. */
    public function recordedAt(): \DateTimeImmutable
    {
        return $this->resolvedRecordedAt ??= $this->recordedAt ?? new \DateTimeImmutable();
    }

    /**
     * Return the guard declared for one subject-slot pair, if any.
     *
     * @psalm-param non-empty-string $slotKey
     */
    public function guardFor(SubjectId $subjectId, string $slotKey): ?QuantityGuard
    {
        return $this->guardsBySubjectIdAndSlotKey[$subjectId->id][$slotKey] ?? null;
    }

    /** Resolve the SubjectId for one batch subject. */
    public function subjectIdFor(mixed $subject): SubjectId
    {
        /** @psalm-var mixed $subjectId */
        $subjectId = ($this->subjectIdResolver)($subject);

        if (!$subjectId instanceof SubjectId) {
            throw new ConfigurationException(
                'Resolved batch subject ID must be a SubjectId instance.',
                'invalid_batch_subject_id',
            );
        }

        return $subjectId;
    }
}
