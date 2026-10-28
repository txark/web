<?php

namespace Tests\Feature;

use App\Enums\MemberPosition;
use App\Enums\Role;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** แจ้งเตือนงานค้างของสภา: คำนวณจากข้อมูลจริง */
class AlertTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->loggedIn()->create());
    }

    private function ids(): array
    {
        return collect($this->getJson('/api/council/alerts')->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_only_council_can_read_alerts(): void
    {
        $this->getJson('/api/council/alerts')->assertUnauthorized();

        foreach ([Role::Gang, Role::Family] as $role) {
            $this->actAs($role);
            $this->getJson('/api/council/alerts')->assertForbidden();
        }

        $this->actAs(Role::Member);
        $this->getJson('/api/council/alerts')->assertOk()->assertJsonPath('meta.count', 0);
    }

    public function test_group_without_leader_and_below_minimum_are_reported(): void
    {
        $this->actAs(Role::Member);
        $g = Group::factory()->create();
        GroupMember::factory()->count(3)->create(['group_id' => $g->id]);

        $ids = $this->ids();
        $this->assertContains("no_leader:{$g->id}", $ids);
        $this->assertContains("below_minimum:{$g->id}", $ids);

        $res = $this->getJson('/api/council/alerts')->json();
        $this->assertSame('high', $res['data'][0]['severity'], 'งานเร่งด่วนต้องขึ้นก่อน');
        $this->assertSame("/manage-groups.html?id={$g->id}", $res['data'][0]['link']);
        $this->assertStringContainsString('มีสมาชิก 3 คน ต้องมีอย่างน้อย 10 คน', collect($res['data'])->firstWhere('type', 'below_minimum')['detail']);
    }

    public function test_alerts_disappear_when_the_problem_is_fixed(): void
    {
        $this->actAs(Role::Member);
        $family = Group::factory()->family()->create();
        GroupMember::factory()->position(MemberPosition::Leader)->create(['group_id' => $family->id]);
        GroupMember::factory()->count(5)->create(['group_id' => $family->id]);

        $this->assertSame([], $this->ids(), 'ครอบครัวมี 6 คนและมีหัวหน้า → ครบเกณฑ์');

        // ครอบครัวขั้นต่ำ 6 ต่างจากแก๊ง 10
        $gang = Group::factory()->create();
        GroupMember::factory()->position(MemberPosition::Leader)->create(['group_id' => $gang->id]);
        GroupMember::factory()->count(5)->create(['group_id' => $gang->id]);
        $this->assertContains("below_minimum:{$gang->id}", $this->ids());

        GroupMember::factory()->count(4)->create(['group_id' => $gang->id]);
        $this->assertSame([], $this->ids());
    }

    public function test_members_who_left_do_not_count(): void
    {
        $this->actAs(Role::Member);
        $family = Group::factory()->family()->create();
        GroupMember::factory()->position(MemberPosition::Leader)->create(['group_id' => $family->id]);
        GroupMember::factory()->count(5)->create(['group_id' => $family->id]);
        GroupMember::factory()->left()->count(3)->create(['group_id' => $family->id]);

        $this->assertSame([], $this->ids());

        GroupMember::where('group_id', $family->id)->whereNull('left_at')->where('position', 'member')->first()->update(['left_at' => now()]);
        $this->assertContains("below_minimum:{$family->id}", $this->ids());
    }

    public function test_long_locked_slots_are_flagged_but_recent_locks_are_not(): void
    {
        $this->actAs(Role::Member);

        $recent = Group::factory()->family()->create(['slots_locked' => true]);
        $old = Group::factory()->family()->create(['slots_locked' => true]);
        foreach ([$recent, $old] as $g) {
            GroupMember::factory()->position(MemberPosition::Leader)->create(['group_id' => $g->id]);
            GroupMember::factory()->count(5)->create(['group_id' => $g->id]);
        }
        Group::whereKey($old->id)->toBase()->update(['updated_at' => now()->subDays(10)]);

        $ids = $this->ids();
        $this->assertContains("locked_long:{$old->id}", $ids);
        $this->assertNotContains("locked_long:{$recent->id}", $ids);
    }

    public function test_reading_alerts_is_not_written_to_the_access_log(): void
    {
        $this->actAs(Role::Member);
        $this->getJson('/api/council/alerts')->assertOk();

        $this->assertFalse(Activity::where('log_name', 'access')->where('description', 'like', '%งานค้าง%')->exists());
    }
}
