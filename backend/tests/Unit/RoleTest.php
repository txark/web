<?php

namespace Tests\Unit;

use App\Enums\Role;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    public function test_badge_labels_are_the_six_names_shown_in_the_ui(): void
    {
        $this->assertSame(
            ['Gang', 'Family', 'Trainee', 'Council', 'Operations', 'Senior', 'Secretary', 'Sub-CC', 'Head-CC', 'Admin'],
            array_map(fn (Role $r) => $r->label(), Role::cases()),
        );
    }

    public function test_thai_names_are_available_for_sentences(): void
    {
        $this->assertSame(
            ['แก๊ง', 'ครอบครัว', 'สภาฝึกหัด', 'สภา', 'สภาปฏิบัติการ', 'สภาอาวุโส', 'เลขาสภา', 'รองประธานสภา', 'ประธานสภา', 'แอดมินดูแลเว็บ'],
            array_map(fn (Role $r) => $r->thaiName(), Role::cases()),
        );
    }

    public function test_gang_and_family_have_equal_rights_and_rank_below_council(): void
    {
        $this->assertSame(Role::Gang->level(), Role::Family->level());
        $this->assertTrue(Role::Family->atLeast(Role::Gang));
        $this->assertTrue(Role::Gang->atLeast(Role::Family));
        $this->assertFalse(Role::Family->atLeast(Role::Member));
        $this->assertFalse(Role::Gang->atLeast(Role::Member));
    }

    public function test_hierarchy_order_from_lowest_to_highest(): void
    {
        $this->assertTrue(Role::Member->atLeast(Role::Family));
        $this->assertTrue(Role::VicePresident->atLeast(Role::Member));
        $this->assertTrue(Role::President->atLeast(Role::VicePresident));
        $this->assertFalse(Role::VicePresident->atLeast(Role::Admin));
        $this->assertFalse(Role::President->atLeast(Role::Admin));
        $this->assertFalse(Role::VicePresident->atLeast(Role::President));
    }

    public function test_secretary_senior_operations_and_trainee_work_like_council(): void
    {
        foreach ([Role::Trainee, Role::Operations, Role::Senior, Role::Secretary] as $r) {
            $this->assertSame(Role::Member->level(), $r->level());
            $this->assertTrue($r->atLeast(Role::Member));
            $this->assertFalse($r->atLeast(Role::VicePresident));
            $this->assertSame([], array_filter($r->assignable(), fn ($x) => $x->atLeast(Role::Member)), 'ยศสภาตั้งยศสภาให้ใครไม่ได้');
        }
    }

    public function test_only_vice_president_up_can_assign_council_ranks(): void
    {
        foreach ([Role::Secretary, Role::Senior, Role::Operations, Role::Member, Role::Trainee] as $rank) {
            $this->assertTrue(Role::VicePresident->canAssign($rank));
            $this->assertTrue(Role::President->canAssign($rank));
            $this->assertFalse(Role::Secretary->canAssign($rank));
            $this->assertFalse(Role::Member->canAssign($rank));
        }
    }

    public function test_sort_weights_are_unique_and_follow_the_seven_council_ranks(): void
    {
        $w = array_map(fn (Role $r) => $r->sortWeight(), Role::cases());
        $this->assertSame($w, array_values(array_unique($w)));
        $this->assertTrue(Role::President->sortWeight() > Role::VicePresident->sortWeight());
        $this->assertTrue(Role::VicePresident->sortWeight() > Role::Secretary->sortWeight());
        $this->assertTrue(Role::Secretary->sortWeight() > Role::Senior->sortWeight());
        $this->assertTrue(Role::Senior->sortWeight() > Role::Operations->sortWeight());
        $this->assertTrue(Role::Operations->sortWeight() > Role::Member->sortWeight());
        $this->assertTrue(Role::Member->sortWeight() > Role::Trainee->sortWeight());
    }

    public function test_values_are_unique_and_round_trip(): void
    {
        $values = array_map(fn (Role $r) => $r->value, Role::cases());

        $this->assertSame($values, array_unique($values));
        foreach (Role::cases() as $role) {
            $this->assertSame($role, Role::from($role->value));
        }
    }
}
