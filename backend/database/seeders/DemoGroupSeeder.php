<?php

namespace Database\Seeders;

use App\Enums\GroupStatus;
use App\Enums\MemberPosition;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Database\Seeder;

/**
 * ข้อมูลตัวอย่างไว้ดูหน้าทำเนียบ — ไม่ได้รันอัตโนมัติ
 * php artisan db:seed --class=DemoGroupSeeder
 */
class DemoGroupSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            Group::factory()->create(['name' => 'DEMO Dragons', 'description' => 'แก๊งตัวอย่างสำหรับทดสอบหน้าทำเนียบ']),
            Group::factory()->family()->create(['name' => 'DEMO Family']),
            Group::factory()->status(GroupStatus::Blacklist, 'ตัวอย่าง')->create(['name' => 'DEMO Blacklisted']),
            Group::factory()->family()->status(GroupStatus::Sanction, 'ตัวอย่าง')->create(['name' => 'DEMO Sanctioned']),
            Group::factory()->create(['name' => 'DEMO Locked', 'slots_locked' => true, 'locked_reason' => 'ตัวอย่าง']),
        ];

        foreach ($groups as $group) {
            GroupMember::factory()->position(MemberPosition::Leader)->create(['group_id' => $group->id]);
            GroupMember::factory()->position(MemberPosition::CoLeader)->create(['group_id' => $group->id]);
            GroupMember::factory()->count(5)->create(['group_id' => $group->id]);
            GroupMember::factory()->left()->create(['group_id' => $group->id]);
        }
    }
}
