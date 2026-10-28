<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\MemberPosition;
use App\Enums\Role;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** จัดการแก๊ง/ครอบครัวโดยสภา: สิทธิ์ กติกา คูลดาวน์ สถานะ ล็อกสล็อต */
class GroupManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(Role $role): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        Sanctum::actingAs($u);

        return $u;
    }

    private function group(array $attrs = [], string $type = 'gang'): Group
    {
        return Group::factory()->state(array_merge(['type' => $type], $attrs))->create();
    }

    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR";

    private const ZIP = "PK\x03\x04\0\0\0\0";

    /** ข้อมูลฟอร์มขึ้นทะเบียนที่ถูกต้องครบถ้วน (ทับด้วย $over ได้) */
    private function reg(array $over = []): array
    {
        return array_merge([
            'type' => 'gang',
            'declared_members' => 12,
            'name' => 'Wolves',
            'short_name' => 'WLV',
            'color_hex' => '#ff0000',
            'color_name' => 'แดง',
            'logo' => UploadedFile::fake()->createWithContent('logo.png', self::PNG),
            'leader' => ['ic_name' => 'Boss One', 'ic_phone' => '555-0100', 'discord_id' => '123456789012345678'],
            'co_leaders' => [['ic_name' => 'Deputy One', 'ic_phone' => '', 'discord_id' => '223456789012345678']],
            'temp_outfit_image' => UploadedFile::fake()->createWithContent('temp.png', self::PNG),
            'temp_outfit_male' => '101',
            'temp_outfit_female' => '202',
            'outfit_zip' => UploadedFile::fake()->createWithContent('outfit.zip', self::ZIP),
        ], $over);
    }

    private function url(Group|string $g = '', string $suffix = ''): string
    {
        return '/api/council/groups'.($g instanceof Group ? '/'.$g->id : $g).$suffix;
    }

    // ───────── สิทธิ์ ─────────

    public function test_guests_and_non_council_cannot_manage_groups(): void
    {
        $this->getJson($this->url())->assertUnauthorized();

        foreach ([Role::Gang, Role::Family] as $role) {
            $this->actAs($role);
            $this->getJson($this->url())->assertForbidden();
            $this->postJson($this->url(), ['type' => 'gang', 'name' => 'ABC'])->assertForbidden();
        }
    }

    public function test_council_member_can_list_with_internal_fields(): void
    {
        $g = $this->group(['status' => GroupStatus::Blacklist, 'status_reason' => 'ภายใน']);
        $this->actAs(Role::Member);

        $this->getJson($this->url())->assertOk();
        $this->getJson($this->url($g))->assertOk()->assertJsonPath('data.status_reason', 'ภายใน');
    }

    public function test_only_vice_president_up_can_delete_a_group(): void
    {
        $g = $this->group();
        $this->actAs(Role::Member);
        $this->deleteJson($this->url($g))->assertForbidden();

        $this->actAs(Role::VicePresident);
        $this->deleteJson($this->url($g))->assertNoContent();
        $this->assertSoftDeleted($g);
    }

    // ───────── สร้าง / แก้ไข ─────────

    public function test_create_group_with_leader_and_initial_history(): void
    {
        $user = $this->actAs(Role::Member);

        Storage::fake('public');
        Storage::fake('local');

        $this->post($this->url(), $this->reg(), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Wolves')
            ->assertJsonPath('data.short_name', 'WLV')
            ->assertJsonPath('data.color_hex', '#FF0000')
            ->assertJsonPath('data.leader', 'Boss One')
            ->assertJsonPath('data.members_count', 2)
            ->assertJsonPath('data.protect_requested', false)
            ->assertJsonPath('data.has_outfit_zip', true)
            ->assertJsonPath('data.history.0.status', 'normal');

        $this->assertDatabaseHas('group_status_history', ['changed_by' => $user->id, 'status' => 'normal']);
        $this->assertTrue(Activity::where('subject_type', Group::class)->exists(), 'การสร้างกลุ่มต้องอยู่ใน log');
    }

    public function test_name_rules(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->actAs(Role::Member);
        $this->group(['name' => 'Wolves']);

        $bad = [
            'ชื่อไทย' => 'ภาษาอังกฤษ',
            'ABCDEFGHIJKLMNOPQRSTU' => 'ยาวเกิน 20',
            'wolves' => 'ซ้ำ (ไม่สนตัวพิมพ์)',
        ];
        foreach ($bad as $name => $why) {
            $this->post($this->url(), $this->reg(['name' => $name]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('name');
        }

        // ครอบครัวห้ามมีคำว่า Gang
        $this->post($this->url(), $this->reg(['type' => 'family', 'declared_members' => 8, 'name' => 'Big Gang']), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->post($this->url(), $this->reg(['type' => 'family', 'declared_members' => 8, 'name' => 'Sunrise']), ['Accept' => 'application/json'])->assertCreated();
    }

    // ───────── ฟอร์มขึ้นทะเบียน ─────────

    private function register(array $over = [])
    {
        return $this->post($this->url(), $this->reg($over), ['Accept' => 'application/json']);
    }

    public function test_registration_validates_files_by_real_content(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->actAs(Role::Member);

        // ชื่อ .png แต่เนื้อไม่ใช่ PNG / .zip ปลอม
        $this->register(['logo' => UploadedFile::fake()->createWithContent('logo.png', 'not a png')])
            ->assertUnprocessable()->assertJsonValidationErrors('logo');
        $this->register(['temp_outfit_image' => UploadedFile::fake()->createWithContent('t.png', 'GIF89a')])
            ->assertUnprocessable()->assertJsonValidationErrors('temp_outfit_image');
        $this->register(['outfit_zip' => UploadedFile::fake()->createWithContent('o.zip', 'plain text')])
            ->assertUnprocessable()->assertJsonValidationErrors('outfit_zip');
        $this->assertDatabaseCount('groups', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_registration_enforces_minimum_members_and_deputy_limit(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->actAs(Role::Member);

        $this->register(['declared_members' => 9])->assertUnprocessable()->assertJsonValidationErrors('declared_members');
        $this->register(['type' => 'family', 'declared_members' => 5, 'name' => 'Home'])->assertUnprocessable()->assertJsonValidationErrors('declared_members');
        $this->register(['declared_members' => 31])->assertUnprocessable()->assertJsonValidationErrors('declared_members');

        $dep = fn ($i) => ['ic_name' => "Deputy $i", 'discord_id' => '22345678901234567'.$i];
        $this->register(['co_leaders' => []])->assertUnprocessable()->assertJsonValidationErrors('co_leaders');
        $this->register(['co_leaders' => [$dep(1), $dep(2), $dep(3)]])->assertUnprocessable()->assertJsonValidationErrors('co_leaders');
        $this->register(['co_leaders' => [$dep(1), $dep(2)]])->assertCreated()->assertJsonPath('data.members_count', 3);
    }

    public function test_registration_validates_short_name_color_and_discord_ids(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->actAs(Role::Member);

        $this->register(['short_name' => 'ABCDE'])->assertUnprocessable()->assertJsonValidationErrors('short_name');
        $this->register(['short_name' => 'ไทย'])->assertUnprocessable()->assertJsonValidationErrors('short_name');
        $this->register(['color_hex' => 'red'])->assertUnprocessable()->assertJsonValidationErrors('color_hex');
        $this->register(['leader' => ['ic_name' => 'Boss', 'discord_id' => '123']])->assertUnprocessable()->assertJsonValidationErrors('leader.discord_id');
        $this->register(['temp_outfit_male' => null, 'temp_outfit_female' => null])->assertUnprocessable()->assertJsonValidationErrors('temp_outfit_male');
        $this->register(['co_leaders' => [['ic_name' => 'Boss One', 'discord_id' => '223456789012345678']]])
            ->assertUnprocessable()->assertJsonValidationErrors('co_leaders');
    }

    public function test_protect_option_counts_fourteen_days_from_registration(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->actAs(Role::Member);

        $this->register(['protect' => true])->assertCreated()
            ->assertJsonPath('data.protect_requested', true)->assertJsonPath('data.protect_days_left', 14);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, Group::first()->protect_until->timestamp, 5);

        $this->register(['name' => 'Other', 'short_name' => 'OTH'])->assertCreated()
            ->assertJsonPath('data.protect_requested', false)->assertJsonPath('data.protect_days_left', 0);
    }

    public function test_registration_files_are_stored_and_private_files_need_council_access(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->actAs(Role::Member);

        $id = $this->register()->assertCreated()->json('data.id');
        $g = Group::find($id);
        Storage::disk('public')->assertExists($g->logo_path);
        Storage::disk('local')->assertExists($g->temp_outfit_path);
        Storage::disk('local')->assertExists($g->outfit_zip_path);

        $this->get($this->url($g, '/files/outfit_zip'))->assertOk();
        $this->get($this->url($g, '/files/temp_outfit'))->assertOk();
        $this->getJson($this->url($g, '/files/other'))->assertNotFound();

        // ข้อมูลติดต่อและไฟล์ส่วนตัวไม่รั่วออก API สาธารณะ
        $this->actAs(Role::Gang);
        $this->getJson('/api/public/groups/'.$g->id)->assertOk()
            ->assertJsonPath('data.short_name', 'WLV')
            ->assertJsonMissingPath('data.protect_until')
            ->assertJsonMissingPath('data.members.0.discord_id');
        $this->getJson($this->url($g, '/files/outfit_zip'))->assertForbidden();
    }
    public function test_rename_respects_cooldowns_and_can_be_unlocked(): void
    {
        $this->actAs(Role::Member);

        $fresh = $this->group(['registered_at' => now()->subDays(5)]);
        $this->patchJson($this->url($fresh), ['name' => 'NewName'])->assertUnprocessable()->assertJsonValidationErrors('name');

        $old = $this->group(['registered_at' => now()->subDays(40)]);
        $this->patchJson($this->url($old), ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
        // เพิ่งเปลี่ยน → ต้องรอ 30 วัน
        $this->patchJson($this->url($old), ['name' => 'Again'])->assertUnprocessable();
        // ปลดคูลดาวน์ (จ่ายค่าบริการแล้ว) → ผ่านและถูกบันทึก
        $this->patchJson($this->url($old), ['name' => 'Again', 'skip_cooldown' => true])->assertOk()->assertJsonPath('data.name', 'Again');
        $this->assertTrue(Activity::where('description', 'like', 'ปลดคูลดาวน์เปลี่ยนชื่อ%')->exists());

        // แก้คำอธิบายไม่ติดคูลดาวน์ชื่อ
        $this->patchJson($this->url($old), ['description' => 'ข้อความ'])->assertOk();
    }

    // ───────── สถานะ ─────────

    public function test_status_change_keeps_history(): void
    {
        $this->actAs(Role::Member);
        $g = $this->group();
        $g->statusHistory()->create(['status' => 'normal', 'started_at' => now()->subDays(3)]);

        $this->patchJson($this->url($g, '/status'), ['status' => 'blacklist'])->assertUnprocessable(); // ต้องมีเหตุผล

        $this->patchJson($this->url($g, '/status'), ['status' => 'blacklist', 'reason' => 'ผิดกฎข้อ 3'])
            ->assertOk()
            ->assertJsonPath('data.status', 'blacklist')
            ->assertJsonPath('data.status_reason', 'ผิดกฎข้อ 3')
            ->assertJsonCount(2, 'data.history');

        $this->patchJson($this->url($g, '/status'), ['status' => 'blacklist', 'reason' => 'x'])->assertUnprocessable(); // ซ้ำสถานะเดิม

        $this->patchJson($this->url($g, '/status'), ['status' => 'normal'])
            ->assertOk()
            ->assertJsonPath('data.status', 'normal')
            ->assertJsonPath('data.status_reason', null);

        $this->assertSame(1, $g->statusHistory()->whereNull('ended_at')->count(), 'ต้องมีสถานะที่ยังเปิดอยู่เพียงรายการเดียว');
    }

    public function test_public_api_never_exposes_status_reason_or_lock_reason(): void
    {
        $g = $this->group(['status' => GroupStatus::Sanction, 'status_reason' => 'ลับ', 'slots_locked' => true, 'locked_reason' => 'ลับเหมือนกัน']);

        $json = $this->getJson('/api/public/groups/'.$g->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('ลับ', $json);
    }

    // ───────── สมาชิก ─────────

    public function test_add_change_position_and_remove_member(): void
    {
        $this->actAs(Role::Member);
        $g = $this->group();

        $res = $this->postJson($this->url($g, '/members'), ['character_name' => 'Alice', 'position' => 'leader'])
            ->assertOk()->assertJsonPath('data.leader', 'Alice');
        $id = $res->json('data.members.0.id');

        $this->patchJson($this->url($g, "/members/{$id}"), ['position' => 'co_leader'])->assertOk()->assertJsonPath('data.leader', null);

        $this->deleteJson($this->url($g, "/members/{$id}"))
            ->assertOk()
            ->assertJsonPath('data.members_count', 0)
            ->assertJsonPath('data.members.0.character_name', 'Alice'); // ยังอยู่ในประวัติ มี left_at

        $this->assertNotNull(GroupMember::find($id)->left_at);
        $this->deleteJson($this->url($g, "/members/{$id}"))->assertNotFound(); // นำออกซ้ำไม่ได้
    }

    public function test_leader_and_co_leader_limits(): void
    {
        $this->actAs(Role::Member);
        $g = $this->group();

        $this->postJson($this->url($g, '/members'), ['character_name' => 'L1', 'position' => 'leader'])->assertOk();
        $this->postJson($this->url($g, '/members'), ['character_name' => 'L2', 'position' => 'leader'])->assertUnprocessable()->assertJsonValidationErrors('position');

        $this->postJson($this->url($g, '/members'), ['character_name' => 'C1', 'position' => 'co_leader'])->assertOk();
        $this->postJson($this->url($g, '/members'), ['character_name' => 'C2', 'position' => 'co_leader'])->assertOk();
        $this->postJson($this->url($g, '/members'), ['character_name' => 'C3', 'position' => 'co_leader'])->assertUnprocessable();
    }

    public function test_member_cap_per_type(): void
    {
        $this->actAs(Role::Member);
        $family = $this->group([], 'family');
        GroupMember::factory()->count(10)->create(['group_id' => $family->id]);

        $this->postJson($this->url($family, '/members'), ['character_name' => 'Eleventh'])
            ->assertUnprocessable()->assertJsonValidationErrors('character_name');
    }

    public function test_character_cannot_be_in_two_groups_of_same_type_but_gang_plus_family_is_ok(): void
    {
        $this->actAs(Role::Member);
        $gangA = $this->group();
        $gangB = $this->group();
        $family = $this->group([], 'family');

        $this->postJson($this->url($gangA, '/members'), ['character_name' => 'Bob'])->assertOk();
        $this->postJson($this->url($gangB, '/members'), ['character_name' => 'bob'])->assertUnprocessable(); // ไม่สนตัวพิมพ์
        $this->postJson($this->url($family, '/members'), ['character_name' => 'Bob'])->assertOk();
    }

    public function test_rejoin_cooldown_after_leaving(): void
    {
        $this->actAs(Role::Member);
        $a = $this->group();
        $b = $this->group();

        $m = $this->postJson($this->url($a, '/members'), ['character_name' => 'Carl'])->json('data.members.0.id');
        $this->deleteJson($this->url($a, "/members/{$m}"))->assertOk();

        $this->postJson($this->url($b, '/members'), ['character_name' => 'Carl'])->assertUnprocessable()->assertJsonValidationErrors('character_name');
        $this->postJson($this->url($b, '/members'), ['character_name' => 'Carl', 'skip_cooldown' => true])->assertOk();

        // ครบ 7 วันแล้วเข้าได้ปกติ
        $old = $this->group();
        GroupMember::factory()->create(['group_id' => $a->id, 'character_name' => 'Dana', 'left_at' => now()->subDays(8)]);
        $this->postJson($this->url($old, '/members'), ['character_name' => 'Dana'])->assertOk();
    }

    public function test_locked_slots_block_member_changes_until_unlocked(): void
    {
        $this->actAs(Role::Member);
        $g = $this->group();
        $m = GroupMember::factory()->create(['group_id' => $g->id]);

        $this->patchJson($this->url($g, '/lock'), ['slots_locked' => true, 'reason' => 'ตรวจสอบ'])
            ->assertOk()->assertJsonPath('data.slots_locked', true)->assertJsonPath('data.locked_reason', 'ตรวจสอบ');

        $this->postJson($this->url($g, '/members'), ['character_name' => 'New'])->assertStatus(409);
        $this->patchJson($this->url($g, "/members/{$m->id}"), ['position' => 'co_leader'])->assertStatus(409);
        $this->deleteJson($this->url($g, "/members/{$m->id}"))->assertStatus(409);

        $this->patchJson($this->url($g, '/lock'), ['slots_locked' => false])->assertOk()->assertJsonPath('data.locked_reason', null);
        $this->postJson($this->url($g, '/members'), ['character_name' => 'New'])->assertOk();
    }

    public function test_member_of_another_group_is_not_reachable_through_this_group(): void
    {
        $this->actAs(Role::Member);
        $a = $this->group();
        $b = $this->group();
        $m = GroupMember::factory()->create(['group_id' => $b->id]);

        $this->deleteJson($this->url($a, "/members/{$m->id}"))->assertNotFound();
        $this->assertNull($m->fresh()->left_at);
    }

    public function test_opening_the_group_list_is_access_logged(): void
    {
        $this->actAs(Role::Member);
        $this->getJson($this->url())->assertOk();

        $this->assertTrue(Activity::where('log_name', 'access')->where('description', 'like', '%จัดการแก๊ง/ครอบครัว%')->exists());
    }

    // ───────── ตัวจริง/ตัวสำรอง/Lady/ขั้นต่ำ ─────────

    public function test_slot_limits_per_group_kind(): void
    {
        $this->actAs(Role::Member);

        // แก๊งทั่วไป: ตัวจริง 20 + สำรอง 5
        $gang = $this->group();
        GroupMember::factory()->count(20)->create(['group_id' => $gang->id]);
        $this->postJson($this->url($gang, '/members'), ['character_name' => 'Main21'])->assertUnprocessable()->assertJsonValidationErrors('character_name');
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson($this->url($gang, '/members'), ['character_name' => "Res{$i}", 'is_reserve' => true])->assertOk();
        }
        $this->postJson($this->url($gang, '/members'), ['character_name' => 'Res6', 'is_reserve' => true])->assertUnprocessable()->assertJsonValidationErrors('is_reserve');

        // Lady: ตัวจริง 25
        $lady = $this->group(['is_lady' => true]);
        GroupMember::factory()->count(24)->create(['group_id' => $lady->id]);
        $this->postJson($this->url($lady, '/members'), ['character_name' => 'Lady25'])->assertOk()->assertJsonPath('data.slots.main_count', 25)->assertJsonPath('data.slots.main_max', 25);
        $this->postJson($this->url($lady, '/members'), ['character_name' => 'Lady26'])->assertUnprocessable();

        // ครอบครัว: ตัวจริง 10 ไม่มีสำรอง
        $family = $this->group([], 'family');
        $this->postJson($this->url($family, '/members'), ['character_name' => 'Fam', 'is_reserve' => true])->assertUnprocessable()->assertJsonValidationErrors('is_reserve');
    }

    public function test_leaders_cannot_be_reserve_and_members_can_swap_slots(): void
    {
        $this->actAs(Role::Member);
        $g = $this->group();

        $this->postJson($this->url($g, '/members'), ['character_name' => 'Boss', 'position' => 'leader', 'is_reserve' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('position');

        $id = $this->postJson($this->url($g, '/members'), ['character_name' => 'Sub', 'is_reserve' => true])
            ->assertOk()->assertJsonPath('data.slots.reserve_count', 1)->json('data.members.0.id');

        // สำรอง → ตัวจริง
        $this->patchJson($this->url($g, "/members/{$id}"), ['is_reserve' => false])
            ->assertOk()->assertJsonPath('data.slots.main_count', 1)->assertJsonPath('data.slots.reserve_count', 0);
        // สำรองที่เลื่อนเป็นรองหัวหน้าต้องย้ายเป็นตัวจริงพร้อมกัน
        $this->patchJson($this->url($g, "/members/{$id}"), ['is_reserve' => true])->assertOk();
        $this->patchJson($this->url($g, "/members/{$id}"), ['position' => 'co_leader'])->assertUnprocessable();
        $this->patchJson($this->url($g, "/members/{$id}"), ['position' => 'co_leader', 'is_reserve' => false])->assertOk();
    }

    public function test_lady_flag_only_applies_to_gangs_and_reports_minimum(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->actAs(Role::Member);

        $this->post($this->url(), $this->reg(['name' => 'Roses', 'is_lady' => true]), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.is_lady', true)->assertJsonPath('data.slots.main_max', 25)->assertJsonPath('data.slots.min', 10);

        $this->post($this->url(), $this->reg(['type' => 'family', 'declared_members' => 8, 'name' => 'Home', 'is_lady' => true]), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.is_lady', false)->assertJsonPath('data.slots.min', 6)->assertJsonPath('data.slots.reserve_max', 0);
    }

    public function test_cannot_downgrade_lady_gang_with_too_many_main_members(): void
    {
        $this->actAs(Role::Member);
        $g = $this->group(['is_lady' => true]);
        GroupMember::factory()->count(22)->create(['group_id' => $g->id]);

        $this->patchJson($this->url($g), ['is_lady' => false])->assertUnprocessable();
        $this->assertTrue($g->fresh()->is_lady);
    }

    public function test_position_enum_is_validated(): void
    {
        $this->actAs(Role::Member);
        $g = $this->group();

        $this->postJson($this->url($g, '/members'), ['character_name' => 'X', 'position' => 'boss'])->assertUnprocessable();
        $this->assertSame(0, GroupMember::count());
        $this->assertSame(MemberPosition::Leader, MemberPosition::from('leader'));
    }
}
