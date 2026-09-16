<?php

declare(strict_types=1);

namespace Tests\Unit\License;

use Nandan108\InvFlux\License\AccessMode;
use Nandan108\InvFlux\License\Entitlement;
use Nandan108\InvFlux\License\EntitlementKind;
use Nandan108\InvFlux\License\FeatureManifest;
use Nandan108\InvFlux\License\LicenseState;
use PHPUnit\Framework\TestCase;

/**
 * Entitlement is decided by **state**, never by a date — and this is the tripwire for it.
 *
 * `paidThrough` and `graceUntil` ride every entitlement, and neither is read by
 * {@see Entitlement::permits()}. They are display data: the renewal line on a licence card, the
 * countdown on a lapse notice. A licence stops entitling when something *happens* to it — a failed
 * payment, a cancellation, a revocation — not because a timestamp went by.
 *
 * ## Why this is worth a test rather than a comment
 *
 * The property is invisible and looks like an oversight. An unused `paidThrough` sitting on the
 * object is a standing invitation to "fix" the gate by checking it, and the change would look like
 * a bug fix while quietly introducing a dead window at every renewal.
 *
 * That window is not hypothetical. A merchant of record can confirm a renewal well after it is
 * charged — India's RBI mandate flow settles roughly 48-51 hours after the scheduled charge date,
 * and the success webhook only lands then. Between the charge and the webhook there is no event at
 * all: no success to extend `paid_through`, and no failure either, because nothing has failed. A
 * date-driven gate would strip every Indian subscriber of their paid features for two days at every
 * renewal — the steady state for that market, not an edge case. A state-driven one notices nothing,
 * because nothing happened.
 *
 * The lapse path is the second layer and is unaffected either way: a genuine failure moves the
 * licence to `lapsed`, which still grants the full manifest for the whole grace window.
 */
final class EntitlementIsStateDrivenTest extends TestCase
{
    private const KEY = 'procurement.multi-supplier';

    public function testAnActiveEntitlementOutlivesItsPaidThrough(): void
    {
        // Paid through a week ago, and no event has arrived to say otherwise. The renewal may be
        // mid-settlement at the merchant of record; from here that is indistinguishable from a
        // licence whose webhook is simply slow, and both deserve the same answer.
        $entitlement = $this->entitlement(LicenseState::Active, paidThrough: time() - 7 * 86400);

        self::assertTrue($entitlement->permits(self::KEY, AccessMode::Write));
        self::assertTrue($entitlement->permits(self::KEY, AccessMode::Read));
    }

    public function testALapsedEntitlementKeepsTheWholeManifestThroughGrace(): void
    {
        // The second layer: even a payment that genuinely failed retains full features while the
        // grace window runs, which is what makes a slow confirmation a non-event twice over.
        $entitlement = $this->entitlement(
            LicenseState::Lapsed,
            paidThrough: time() - 7 * 86400,
            graceUntil: time() + 83 * 86400,
        );

        self::assertTrue($entitlement->permits(self::KEY, AccessMode::Write));
    }

    public function testAnElapsedGraceIsStillNotWhatDecides(): void
    {
        // `graceUntil` is display data too. What ends a lapse is the server moving the licence to
        // `frozen` on its scheduled pass and issuing a blob that says so — not the plugin watching
        // a clock. Until that blob arrives the install keeps working, deliberately: a licence must
        // never change meaning in the field while the thing that decides it is unreachable.
        $entitlement = $this->entitlement(
            LicenseState::Lapsed,
            paidThrough: time() - 200 * 86400,
            graceUntil: time() - 100 * 86400,
        );

        self::assertTrue($entitlement->permits(self::KEY, AccessMode::Write));
    }

    public function testRevocationIsWhatActuallyWithdrawsAFeature(): void
    {
        // The one state that does not grant membership — and it is a state, reached by an event,
        // with dates that say nothing about it either way.
        $entitlement = $this->entitlement(LicenseState::Revoked, paidThrough: time() + 365 * 86400);

        self::assertFalse($entitlement->permits(self::KEY, AccessMode::Write));
    }

    private function entitlement(
        LicenseState $state,
        ?int $paidThrough = null,
        ?int $graceUntil = null,
    ): Entitlement {
        return new Entitlement(
            kind: EntitlementKind::Addon,
            sku: 'pro',
            state: $state,
            manifest: new FeatureManifest(version: 1, sku: 'pro', featureKeys: [self::KEY]),
            paidThrough: $paidThrough,
            graceUntil: $graceUntil,
            grandfatheredPrice: null,
            quantity: null,
        );
    }
}
