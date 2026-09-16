<?php

declare(strict_types=1);

namespace Tests\Mutation;

use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Mutation\MetaEvent;
use Nandan108\InvFlux\Mutation\PersistBatchMovement;
use Nandan108\InvFlux\Mutation\PersistMovement;
use Nandan108\InvFlux\Mutation\QuantityGuard;
use Nandan108\SlotFlow\Batch\QuantityStateBatch;
use Nandan108\SlotFlow\MovementResult;
use PHPUnit\Framework\TestCase;

final class InvariantTest extends TestCase
{
    public function testQuantityGuardRejectsMinGreaterThanMax(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Quantity guard `min` cannot be greater than `max`.');

        new QuantityGuard(min: 10, max: 5);
    }

    public function testQuantityGuardRejectsNegativeMinimum(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Quantity guard `min` must be a non-negative integer.');

        new QuantityGuard(min: -1);
    }

    public function testQuantityGuardRejectsNegativeMaximum(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Quantity guard `max` must be a non-negative integer when provided.');

        new QuantityGuard(max: -1);
    }

    public function testMetaEventRecordedAtIsStableAcrossRepeatedCalls(): void
    {
        $event = new MetaEvent('schema_bootstrapped');

        self::assertSame($event->recordedAt(), $event->recordedAt());
    }

    public function testPersistMovementRejectsInvalidSubjectId(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('SubjectId must be a positive integer.');

        new PersistMovement(new SubjectId(0), 'inventory', 'alloc', new MovementResult([], 0));
    }

    public function testPersistMovementRecordedAtIsStableAcrossRepeatedCalls(): void
    {
        $movement = new PersistMovement(
            new SubjectId(1),
            'inventory',
            'alloc',
            new MovementResult([], 0),
        );

        self::assertSame($movement->recordedAt(), $movement->recordedAt());
    }

    public function testPersistBatchMovementRejectsNonSubjectIdResolvedValues(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Resolved batch subject ID must be a SubjectId instance.');

        /** @var \Closure(mixed): SubjectId $badResolver */
        $badResolver = static fn (mixed $subject): object => new \stdClass();

        $batch = new PersistBatchMovement(
            new QuantityStateBatch([]),
            $badResolver,
            'inventory',
            'alloc',
        );

        $batch->subjectIdFor(new \stdClass());
    }

    public function testPersistBatchMovementRecordedAtIsStableAcrossRepeatedCalls(): void
    {
        $batch = new PersistBatchMovement(
            new QuantityStateBatch([]),
            static fn (mixed $subject): SubjectId => new SubjectId(1),
            'inventory',
            'alloc',
        );

        self::assertSame($batch->recordedAt(), $batch->recordedAt());
    }
}
