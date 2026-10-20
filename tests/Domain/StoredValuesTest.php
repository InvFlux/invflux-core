<?php

declare(strict_types=1);

namespace Tests\Domain;

use Nandan108\InvFlux\Domain\Order\OrderEventType;
use Nandan108\InvFlux\Domain\Order\OrderStatus;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Shipment\ShipmentStatus;
use Nandan108\InvFlux\License\LicenseState;
use PHPUnit\Framework\TestCase;

/**
 * Backing values that leave the process — stored in a column, written into an event, signed into a
 * license blob or read by another system — pinned literally.
 *
 * Every other test compares a case with itself, so a respelled or renumbered value passes them all
 * while every row already stored stops hydrating. Changing a value here is a data change for every
 * existing site, and should feel like one.
 */
final class StoredValuesTest extends TestCase
{
    public function testOrderStatusIntegers(): void
    {
        self::assertSame(
            ['Untouched' => 0, 'Started' => 1, 'Staged' => 2, 'Shipped' => 3, 'Canceled' => 4],
            self::values(OrderStatus::cases()),
        );
    }

    public function testPurchaseOrderStatusIntegersAndSlugs(): void
    {
        self::assertSame(
            [
                'InPrep'    => 1, 'InReview' => 3, 'Approved' => 5, 'Submitted' => 7, 'Acknowledged' => 9,
                'InTransit' => 11, 'Arrived' => 13, 'InReception' => 15, 'PartiallyReceived' => 17,
                'Received'  => 19, 'Canceled' => 21,
            ],
            self::values(PoStatus::cases()),
        );
        self::assertSame('arrived', PoStatus::Arrived->slug());
        self::assertSame('canceled', PoStatus::Canceled->slug());
    }

    public function testShipmentStatusStrings(): void
    {
        self::assertSame(
            ['Saved' => 'saved', 'Processed' => 'processed', 'Canceled' => 'canceled', 'Reversed' => 'reversed'],
            self::values(ShipmentStatus::cases()),
        );
    }

    /** Signed into the license blob by the license server and parsed by the plugin: both must agree. */
    public function testLicenseStateCanceledString(): void
    {
        self::assertSame('canceled', LicenseState::Canceled->value);
    }

    public function testCanceledEventTypeStrings(): void
    {
        self::assertSame('shipment.canceled', OrderEventType::ShipmentCanceled->value);
        self::assertSame('refund.canceled', OrderEventType::RefundCanceled->value);
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, int|string>
     */
    private static function values(array $cases): array
    {
        $out = [];
        foreach ($cases as $case) {
            $out[$case->name] = $case->value;
        }

        return $out;
    }
}
