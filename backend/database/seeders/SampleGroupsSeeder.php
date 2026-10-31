<?php

namespace Database\Seeders;

use App\Enums\GroupStatus;
use App\Enums\GroupType;
use App\Enums\MemberPosition;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * แก๊งและครอบครัวตัวอย่างสำหรับทดสอบหน้าทะเบียน / ธุรกรรม / สตอรี่ — ไม่ได้รันอัตโนมัติ และรันซ้ำได้ (ข้ามชื่อที่มีอยู่แล้ว)
 * php artisan db:seed --class=SampleGroupsSeeder
 */
class SampleGroupsSeeder extends Seeder
{
    /** ชื่อ, ชื่อย่อ, สี HEX, ชื่อสี, ชนิด (gang | lady | family) */
    private const GROUPS = [
        ['Ravens', 'RVN', '#1E5BD8', 'น้ำเงิน', 'gang'],
        ['Vipers', 'VPR', '#D32F2F', 'แดง', 'gang'],
        ['Hawks', 'HWK', '#F2C21B', 'เหลือง', 'gang'],
        ['Wolves', 'WLV', '#222222', 'ดำ', 'gang'],
        ['Cobras', 'CBR', '#2E9E4F', 'เขียว', 'gang'],
        ['Lady Roses', 'LRS', '#E91E8C', 'ชมพู', 'lady'],
        ['Falcon Family', 'FLC', '#7B3FA0', 'ม่วง', 'family'],
        ['Bear Family', 'BRF', '#8D6E63', 'น้ำตาล', 'family'],
        ['Fox Family', 'FOX', '#F28C28', 'ส้ม', 'family'],
        ['Dolphin Family', 'DLP', '#00A7B5', 'ฟ้า', 'family'],
    ];

    private const NAMES = ['Arthur', 'Bruno', 'Carlos', 'Dante', 'Elias', 'Felix', 'Gordon', 'Hector', 'Ivan', 'Jasper', 'Kane', 'Leon', 'Marco', 'Nolan', 'Oscar', 'Pedro', 'Quinn', 'Rafael', 'Simon', 'Tobias'];

    private const SURNAMES = ['Stone', 'Vega', 'Cruz', 'Black', 'Reed', 'Knight', 'Moore', 'Fox', 'Hale', 'Cole', 'Wade', 'Ross', 'Gray', 'Cain', 'Pike'];

    public function run(): void
    {
        $by = User::query()->orderBy('id')->value('id');
        $created = 0;

        foreach (self::GROUPS as $i => [$name, $short, $hex, $colorName, $kind]) {
            if (Group::withTrashed()->where('name', $name)->exists()) {
                continue;
            }

            $family = $kind === 'family';
            $group = Group::create([
                'type' => $family ? GroupType::Family : GroupType::Gang,
                'is_lady' => $kind === 'lady',
                'name' => $name,
                'short_name' => $short,
                'color_hex' => $hex,
                'color_name' => $colorName,
                'status' => GroupStatus::Normal,
                'status_changed_at' => now(),
                'registered_at' => now()->subDays(30 + $i * 7), // ขึ้นทะเบียนนานพอให้เปลี่ยนชื่อ/ทำธุรกรรมได้
                'outfit_counts' => $family ? ['hoodie' => 1, 'polo' => 1] : ['suit' => 2, 'armor' => 1, 'hoodie' => 1, 'polo' => 1],
            ]);

            if ($by) {
                $group->statusHistory()->create(['status' => GroupStatus::Normal, 'reason' => 'ขึ้นทะเบียน (ข้อมูลตัวอย่าง)', 'changed_by' => $by, 'started_at' => $group->registered_at]);
            }

            // หัวหน้า + รองหัวหน้า 2 คน (ครอบครัวมี 2 ตามกฎ) + สมาชิก (แก๊งมีตัวสำรองด้วย)
            $people = [[MemberPosition::Leader, false], [MemberPosition::CoLeader, false], [MemberPosition::CoLeader, false]];
            foreach (range(1, $family ? 4 : 9) as $n) {
                $people[] = [MemberPosition::Member, false];
            }
            if (! $family) {
                $people[] = [MemberPosition::Member, true];
                $people[] = [MemberPosition::Member, true];
            }

            foreach ($people as $k => [$position, $reserve]) {
                $group->members()->create([
                    'character_name' => self::NAMES[($i * 3 + $k) % count(self::NAMES)].' '.self::SURNAMES[($i * 5 + $k * 2) % count(self::SURNAMES)],
                    'ic_phone' => '555-'.str_pad((string) (1000 + $i * 20 + $k), 4, '0', STR_PAD_LEFT),
                    'position' => $position,
                    'is_reserve' => $reserve,
                    'joined_at' => $group->registered_at,
                ]);
            }
            $created++;
        }

        $this->command?->info("สร้างแก๊ง/ครอบครัวตัวอย่าง {$created} รายการ (ข้ามที่มีชื่ออยู่แล้ว)");
    }
}
