<?php

declare(strict_types=1);

namespace Tests\Domain\Tag;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Tag\GovernanceFlag;
use Nandan108\InvFlux\Domain\Tag\ManageAuthority;
use Nandan108\InvFlux\Domain\Tag\Tag;
use PHPUnit\Framework\TestCase;

final class TagTest extends TestCase
{
    public function testValidTagConstructs(): void
    {
        $tag = (new Tag())->set(['scope' => Tag::SCOPE_ORDER, 'slug' => 'vip', 'name' => 'VIP', 'color_id' => 11]);

        $this->assertSame('order', $tag->scope);
        $this->assertSame('vip', $tag->slug);
        $this->assertSame('VIP', $tag->name);
        $this->assertSame(11, $tag->color_id);
    }

    public function testScopeAndColorDefault(): void
    {
        $tag = (new Tag())->set(['slug' => 'fragile', 'name' => 'Fragile']);
        $this->assertSame('order', $tag->scope);
        $this->assertSame(0, $tag->color_id);
    }

    public function testEmptySlugRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('slug must not be empty');

        (new Tag())->set(['slug' => '  ', 'name' => 'X']);
    }

    public function testEmptyNameRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('name must not be empty');

        (new Tag())->set(['slug' => 'x', 'name' => '']);
    }

    public function testEmptyScopeRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('scope must not be empty');

        (new Tag())->set(['scope' => '', 'slug' => 'x', 'name' => 'X']);
    }

    public function testOutOfRangeColorIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('out of palette range');

        (new Tag())->set(['slug' => 'x', 'name' => 'X', 'color_id' => 99]);
    }

    public function testPlainTagIsNotGovernance(): void
    {
        $tag = (new Tag())->set(['slug' => 'vip', 'name' => 'VIP']);

        $this->assertSame([], $tag->governance_flags);
        $this->assertSame(0, $tag->priority);
        $this->assertFalse($tag->isGovernance());
        $this->assertFalse($tag->hasFlag(GovernanceFlag::SuppressActive));
    }

    public function testFlaggedTagIsGovernance(): void
    {
        $tag = (new Tag())->set([
            'slug'             => 'parked',
            'name'             => 'Parked',
            'governance_flags' => [GovernanceFlag::SuppressActive, GovernanceFlag::RequireNoteOnAdd],
        ]);

        $this->assertTrue($tag->isGovernance());
        $this->assertTrue($tag->hasFlag(GovernanceFlag::SuppressActive));
        $this->assertTrue($tag->hasFlag(GovernanceFlag::RequireNoteOnAdd));
        $this->assertFalse($tag->hasFlag(GovernanceFlag::PromotedAffordance));
    }

    public function testNonZeroPriorityAloneIsGovernance(): void
    {
        // The seeded Urgent tag: pure priority, no flags.
        $tag = (new Tag())->set(['slug' => 'urgent', 'name' => 'Urgent', 'priority' => 30]);

        $this->assertSame([], $tag->governance_flags);
        $this->assertTrue($tag->isGovernance());
    }

    public function testDefaultManageAuthorityIsAnyoneAndNotGovernance(): void
    {
        $tag = (new Tag())->set(['slug' => 'vip', 'name' => 'VIP']);

        $this->assertSame(ManageAuthority::Anyone, $tag->manage_authority);
        $this->assertFalse($tag->isGovernance());
    }

    public function testRestrictedManageAuthorityAloneIsGovernance(): void
    {
        // Managed access (no flags, neutral priority) still makes it a governance tag.
        $tag = (new Tag())->set([
            'slug'             => 'urgent',
            'name'             => 'Urgent',
            'manage_authority' => ManageAuthority::Managed,
        ]);

        $this->assertSame([], $tag->governance_flags);
        $this->assertSame(0, $tag->priority);
        $this->assertTrue($tag->isGovernance());
    }

    public function testGovernanceFlagBackingValuesAreDistinctPowersOfTwo(): void
    {
        $bits = array_map(static fn (GovernanceFlag $f): int => $f->value, GovernanceFlag::cases());

        // BitmaskCaster requires distinct positive powers of two.
        foreach ($bits as $bit) {
            $this->assertGreaterThan(0, $bit);
            $this->assertSame(0, $bit & ($bit - 1), "bit {$bit} is not a power of two");
        }
        $this->assertSame($bits, array_values(array_unique($bits)));
    }

    public function testOutOfRangePriorityRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('out of range (-128..127)');

        (new Tag())->set(['slug' => 'x', 'name' => 'X', 'priority' => 200]);
    }
}
