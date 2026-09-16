<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\InvFlux\Domain\Order\BuiltInCorrectionReasons;
use Nandan108\InvFlux\Domain\Order\BuiltInCorrectionTypes;
use Nandan108\InvFlux\Domain\Order\Cause;
use Nandan108\InvFlux\Domain\Order\OrderCorrectionReason;
use PHPUnit\Framework\TestCase;

/**
 * The default reason per built-in type. Fault is the reason's cause, so what these defaults say
 * about fault is what a correction records when nobody chooses otherwise.
 */
final class BuiltInCorrectionDefaultReasonsTest extends TestCase
{
    /** @return array<string, OrderCorrectionReason> */
    private static function reasons(): array
    {
        $out = [];
        foreach (BuiltInCorrectionReasons::all() as $reason) {
            $out[$reason->code] = $reason;
        }

        return $out;
    }

    public function testEveryDefaultIsASeededReasonThatCanExplainTheType(): void
    {
        $reasons = self::reasons();

        foreach (BuiltInCorrectionTypes::all() as $type) {
            $default = BuiltInCorrectionTypes::defaultReasonCode($type->code);
            if (null === $default) {
                continue;
            }
            self::assertArrayHasKey($default, $reasons, "{$type->code} defaults to an unseeded reason");
            self::assertTrue(
                $reasons[$default]->timing->appliesTo($type->pre_dispatch),
                "{$type->code} defaults to a reason that cannot happen at its timing",
            );
        }
    }

    /** Before shipment the reason decides where the stock goes, so a default must agree with its type. */
    public function testAnOperatorTypeDefaultMovesStockTheWayTheTypeDoes(): void
    {
        foreach (['cancel_customer', 'writeoff_defective', 'writeoff_missing'] as $code) {
            $type = null;
            foreach (BuiltInCorrectionTypes::all() as $candidate) {
                if ($candidate->code === $code) {
                    $type = $candidate;
                }
            }
            self::assertNotNull($type, $code);
            self::assertSame(
                !$type->restock,
                BuiltInCorrectionReasons::writesOff(BuiltInCorrectionTypes::defaultReasonCode($code) ?? ''),
                $code,
            );
        }
    }

    /** A type without one obvious reason must not pretend to have one. */
    public function testATypeWithoutOneObviousReasonHasNoDefault(): void
    {
        foreach (['cancel_merchant', 'return_resaleable', 'return_resaleable_exception', 'refund_external', 'refund_external_pre', 'cancel_system', 'cancel_reversal'] as $code) {
            self::assertNull(BuiltInCorrectionTypes::defaultReasonCode($code), $code);
        }
    }

    public function testDefaultsAttributeFaultTheWayTheTypeNameSays(): void
    {
        $reasons = self::reasons();
        $cause = static fn (string $type): ?Cause => ($reasons[BuiltInCorrectionTypes::defaultReasonCode($type) ?? ''] ?? null)?->cause;

        self::assertSame(Cause::Customer, $cause('cancel_customer'));
        self::assertSame(Cause::Merchant, $cause('writeoff_defective'));
        self::assertSame(Cause::Merchant, $cause('writeoff_missing'));
        self::assertSame(Cause::Merchant, $cause('capture_short'));
        self::assertSame(Cause::Customer, $cause('shortfall_disclosed'), 'the customer was warned');
        self::assertSame(Cause::Merchant, $cause('shortfall_undisclosed'), 'the customer was not');
    }
}
