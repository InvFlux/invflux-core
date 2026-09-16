<?php

declare(strict_types=1);

namespace Tests\Settings;

use Nandan108\InvFlux\Settings\AdvisoryKind;
use Nandan108\InvFlux\Settings\AdvisorySeverity;
use Nandan108\InvFlux\Settings\ReplicationPolicy;
use Nandan108\InvFlux\Settings\SettingAdvisory;
use Nandan108\InvFlux\Settings\SettingDefinition;
use PHPUnit\Framework\TestCase;

final class SettingAdvisoryTest extends TestCase
{
    public function testDefinitionCarriesNoAdvisoryByDefault(): void
    {
        $def = new SettingDefinition(
            name: 'workbench.export_filename',
            defaultValue: 'export',
            defaultPolicy: ReplicationPolicy::Local,
        );

        $this->assertNull($def->advisory);
    }

    public function testAdvisoryIsAskedOfTheEffectiveValue(): void
    {
        $def = new SettingDefinition(
            name: 'orders.trash_policy',
            defaultValue: 'park',
            defaultPolicy: ReplicationPolicy::Local,
            advisory: static fn (mixed $value): ?SettingAdvisory => 'ignore' === $value
                ? new SettingAdvisory(AdvisoryKind::WrongValue, 'Trashed orders keep their place in the dispatch queue.')
                : null,
        );

        $this->assertNotNull($def->advisory);
        // The same definition advises or stays quiet depending on the value it is shown, which
        // is what lets a save clear the advice with no per-setting code.
        $this->assertNull(($def->advisory)('park'));
        $this->assertInstanceOf(SettingAdvisory::class, ($def->advisory)('ignore'));
        $this->assertSame(AdvisoryKind::WrongValue, ($def->advisory)('ignore')?->kind);
    }

    public function testEmptyMessageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SettingAdvisory(AdvisoryKind::Undecided, '   ');
    }

    public function testAnAdvisoryIsADecisionUnlessSaidOtherwise(): void
    {
        // Every advisory written before severities existed asks for a decision, and still does.
        $advisory = new SettingAdvisory(AdvisoryKind::WrongValue, 'Trashed orders stay in the queue.');

        $this->assertSame(AdvisorySeverity::Decision, $advisory->severity);
    }

    public function testAValueAdvisedAgainstCanBeANote(): void
    {
        // An informed choice with a cost: named, shown, and not something the merchant must clear.
        $advisory = new SettingAdvisory(
            AdvisoryKind::WrongValue,
            'Edits finish sooner, but the shop may respond slowly while they run.',
            AdvisorySeverity::Note,
        );

        $this->assertSame(AdvisorySeverity::Note, $advisory->severity);
    }

    public function testAnUnansweredQuestionIsAlwaysADecision(): void
    {
        // An undecided entry exists to be answered. As a note it would never prompt anyone, and the
        // tri-state value it depends on would have no reason to exist.
        $this->expectException(\InvalidArgumentException::class);

        new SettingAdvisory(AdvisoryKind::Undecided, 'Decide whether this store sells beyond on-hand.', AdvisorySeverity::Note);
    }
}
