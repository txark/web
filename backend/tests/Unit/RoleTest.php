<?php

namespace Tests\Unit;

use App\Enums\Role;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    public function test_badge_labels_are_the_six_names_shown_in_the_ui(): void
    {
        $this->assertSame(
            ['Gang', 'Family', 'Council', 'Sub-CC', 'Head-CC', 'Admin'],
            array_map(fn (Role $r) => $r->label(), Role::cases()),
        );
    }

    public function test_thai_names_are_available_for_sentences(): void
    {
        $this->assertSame(
            ['แก๊ง', 'ครอบครัว', 'สมาชิกสภา', 'รองประธาน', 'ประธาน', 'แอดมินดูแลเว็บ'],
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
        $this->assertTrue(Role::Admin->atLeast(Role::President));
        $this->assertFalse(Role::President->atLeast(Role::Admin));
        $this->assertFalse(Role::VicePresident->atLeast(Role::President));
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
