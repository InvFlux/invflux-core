<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\InvFlux\Domain\Order\MonetaryEventPayload;
use PHPUnit\Framework\TestCase;

final class MonetaryEventPayloadTest extends TestCase
{
    public function testConstructsAndExposesAllFields(): void
    {
        $payload = new MonetaryEventPayload(
            amount: '10.50',
            currency: 'USD',
            base_amount: '9.45',
            base_currency: 'EUR',
            fx_rate_used: '0.90000000',
            extras: ['correction_ids' => ['abc']],
        );

        $this->assertSame('10.50', $payload->amount);
        $this->assertSame('USD', $payload->currency);
        $this->assertSame('9.45', $payload->base_amount);
        $this->assertSame('EUR', $payload->base_currency);
        $this->assertSame('0.90000000', $payload->fx_rate_used);
        $this->assertSame(['correction_ids' => ['abc']], $payload->extras);
    }

    public function testRejectsNonIsoCurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('currency must be ISO 4217 alpha-3');
        new MonetaryEventPayload('10.00', 'EURO', '10.00', 'EUR', '1.0');
    }

    public function testRejectsNonIsoBaseCurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('base_currency must be ISO 4217 alpha-3');
        new MonetaryEventPayload('10.00', 'USD', '10.00', 'EURO', '1.0');
    }

    public function testJsonRoundtrip(): void
    {
        $original = new MonetaryEventPayload(
            amount: '10.50',
            currency: 'USD',
            base_amount: '9.45',
            base_currency: 'EUR',
            fx_rate_used: '0.90000000',
            extras: ['correction_ids' => ['abc'], 'wc_refund_id' => 123],
        );

        $encoded = json_encode($original, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        $rehydrated = MonetaryEventPayload::fromJson($decoded);

        $this->assertSame($original->amount, $rehydrated->amount);
        $this->assertSame($original->currency, $rehydrated->currency);
        $this->assertSame($original->base_amount, $rehydrated->base_amount);
        $this->assertSame($original->base_currency, $rehydrated->base_currency);
        $this->assertSame($original->fx_rate_used, $rehydrated->fx_rate_used);
        $this->assertSame($original->extras, $rehydrated->extras);
    }

    public function testEmptyExtrasJsonSerializesAsObjectNotArray(): void
    {
        $payload = new MonetaryEventPayload('10.00', 'USD', '10.00', 'USD', '1.0');

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"extras":{}', $encoded);
    }
}
