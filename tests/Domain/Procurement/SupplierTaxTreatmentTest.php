<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\Supplier;
use Nandan108\InvFlux\Domain\Procurement\SupplierTaxTreatment;
use PHPUnit\Framework\TestCase;

final class SupplierTaxTreatmentTest extends TestCase
{
    /** Only ordinary domestic purchasing yields tax; every other regime is zero-rated at the supplier. */
    public function testOnlyStandardChargesTax(): void
    {
        self::assertTrue(SupplierTaxTreatment::Standard->chargesTax());

        foreach (SupplierTaxTreatment::cases() as $case) {
            if (SupplierTaxTreatment::Standard === $case) {
                continue;
            }
            self::assertFalse($case->chargesTax(), $case->name.' is zero-rated at the supplier');
        }
    }

    /** The values are stored in an ENUM column, so they are a wire/storage contract, not free text. */
    public function testBackingValuesAreStable(): void
    {
        self::assertSame('standard', SupplierTaxTreatment::Standard->value);
        self::assertSame('reverse_charge', SupplierTaxTreatment::ReverseCharge->value);
        self::assertSame('not_registered', SupplierTaxTreatment::NotRegistered->value);
    }

    /**
     * A merchant who never buys cross-border should never have to think about this, so a fresh
     * supplier is ordinary-domestic without being told.
     */
    public function testANewSupplierIsStandardByDefault(): void
    {
        $supplier = Supplier::newWith(['name' => 'Acme']);

        self::assertSame(SupplierTaxTreatment::Standard, $supplier->tax_treatment);
        self::assertFalse($supplier->quotes_include_tax, 'B2B quotes are net unless the supplier says otherwise');
        self::assertNull($supplier->document_language, 'null means "use the store default"');
        self::assertNull($supplier->terms_lineage_id);
        self::assertNull($supplier->account_number);
    }
}
