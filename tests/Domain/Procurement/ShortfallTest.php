<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\Incoterm;
use Nandan108\InvFlux\Domain\Procurement\ShortfallCapture;
use Nandan108\InvFlux\Domain\Procurement\ShortfallCause;
use Nandan108\InvFlux\Domain\Procurement\ShortfallPart;
use Nandan108\InvFlux\Domain\Procurement\ShortfallParty;
use Nandan108\InvFlux\Domain\Procurement\ShortfallSplit;
use Nandan108\InvFlux\Exceptions\ShortfallCaptureException;
use PHPUnit\Framework\TestCase;

final class ShortfallTest extends TestCase
{
    public function testTheSplitAlwaysAddsUpToWhatIsOpen(): void
    {
        // Nothing confirmed, nothing damaged: all undelivered.
        self::assertSame(['undelivered' => 4], ShortfallSplit::split(open: 4, ordered: 10, confirmed: null, damaged: 0));
        // Confirmed 6 of 10, all 6 in: the 4 open are unconfirmed.
        self::assertSame(['unconfirmed' => 4], ShortfallSplit::split(open: 4, ordered: 10, confirmed: 6, damaged: 0));
        // Confirmed 12 of 20, 8 good + 2 damaged in: 8 unconfirmed, 2 undelivered, 2 damaged.
        self::assertSame(['unconfirmed' => 8, 'undelivered' => 2, 'damaged' => 2], ShortfallSplit::split(open: 12, ordered: 20, confirmed: 12, damaged: 2));
        // Over-confirmed: nothing is unconfirmed.
        self::assertSame(['undelivered' => 5], ShortfallSplit::split(open: 5, ordered: 10, confirmed: 14, damaged: 0));
        // A part never exceeds what is left.
        self::assertSame(['unconfirmed' => 3], ShortfallSplit::split(open: 3, ordered: 10, confirmed: 2, damaged: 5));
        self::assertSame([], ShortfallSplit::split(open: 0, ordered: 10, confirmed: 2, damaged: 1));
    }

    public function testEachPartOffersOnlyTheCausesThatFitIt(): void
    {
        self::assertTrue(ShortfallPart::Unconfirmed->allows(ShortfallCause::Declined));
        self::assertFalse(ShortfallPart::Undelivered->allows(ShortfallCause::Declined), 'declining is before confirmation');
        self::assertFalse(ShortfallPart::Unconfirmed->allows(ShortfallCause::Unknown), 'an unconfirmed part is always told why');
        self::assertFalse(ShortfallPart::Damaged->allows(ShortfallCause::OutOfStock));
    }

    public function testLossAndDamageOnTheWayAnswerToWhoeverBoreTheRisk(): void
    {
        $lost = ShortfallCause::LostInTransit;
        self::assertSame(ShortfallParty::Supplier, ShortfallPart::Undelivered->defaultParty($lost, Incoterm::DAP));
        self::assertSame(ShortfallParty::Supplier, ShortfallPart::Undelivered->defaultParty($lost, Incoterm::DDP));
        self::assertSame(ShortfallParty::Carrier, ShortfallPart::Undelivered->defaultParty($lost, Incoterm::EXW));
        self::assertSame(ShortfallParty::Carrier, ShortfallPart::Damaged->defaultParty(ShortfallCause::DamagedInTransit, Incoterm::FOB));
        self::assertSame(ShortfallParty::Unknown, ShortfallPart::Undelivered->defaultParty($lost, null));
        // Not on the way: the supplier's, whatever the term; our own decisions, ours.
        self::assertSame(ShortfallParty::Supplier, ShortfallPart::Damaged->defaultParty(ShortfallCause::DamagedAtOrigin, Incoterm::EXW));
        self::assertSame(ShortfallParty::Us, ShortfallPart::Unconfirmed->defaultParty(ShortfallCause::BuyerReduced, Incoterm::DAP));
        self::assertSame(ShortfallParty::Unknown, ShortfallPart::Undelivered->defaultParty(ShortfallCause::Unknown, Incoterm::DAP));
    }

    public function testACauseThatDoesNotFitItsPartIsRefused(): void
    {
        $capture = new ShortfallCapture(parts: [ShortfallPart::Damaged->value => ['cause' => ShortfallCause::OutOfStock]]);

        $this->expectException(ShortfallCaptureException::class);
        $capture->resolve(1, ShortfallPart::Damaged, null);
    }

    public function testASingleReasonFitsEachPartOrFallsBack(): void
    {
        $capture = ShortfallCapture::fromReason('supplier_oos');
        self::assertSame(ShortfallCause::OutOfStock, $capture->resolve(1, ShortfallPart::Unconfirmed, null)['cause']);
        self::assertSame(ShortfallCause::OutOfStock, $capture->resolve(1, ShortfallPart::Undelivered, null)['cause']);
        // Out of stock is no cause for damage: the damaged part falls back to unknown, without a note.
        self::assertSame(ShortfallCause::Unknown, $capture->resolve(1, ShortfallPart::Damaged, null)['cause']);
        // The unconfirmed part has no unknown: a reason that does not fit takes its first cause.
        self::assertSame(ShortfallCause::OutOfStock, ShortfallCapture::fromReason('other')->resolve(1, ShortfallPart::Unconfirmed, null)['cause']);
    }
}
