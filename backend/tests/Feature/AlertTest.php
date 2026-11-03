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

    public function test_pending_transactions_from_gangs_show_up_until_decided(): void
    {
        $gangUser = User::factory()->role(Role::Gang)->loggedIn()->create();
        $g = Group::factory()->state(['type' => 'gang'])->create();
        $g->members()->create(['character_name' => 'Boss', 'position' => MemberPosition::Leader, 'joined_at' => now()->subDays(40)]);
        $tx = \App\Models\Transaction::create(['number' => 'WC-T-1', 'group_id' => $g->id, 'kind' => 'rename', 'payload' => ['name' => 'X'], 'status' => 'pending', 'requested_by' => $gangUser->id, 'requested_role' => 'gang', 'approval_required' => true]);

        $this->actAs(Role::Member);
        $alerts = collect($this->getJson('/api/council/alerts')->assertOk()->json('data'))->where('type', 'txn_pending');
        $this->assertCount(1, $alerts);
        $this->assertSame("txn:{$tx->id}", $alerts->first()['id']);
        $this->assertSame('medium', $alerts->first()['severity']);
        $this->assertStringContainsString('แก๊ง/ครอบครัวยื่นเข้ามา', $alerts->first()['title']);

        $tx->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->assertSame('high', collect($this->getJson('/api/council/alerts')->json('data'))->firstWhere('type', 'txn_pending')['severity']);

        $tx->update(['status' => 'approved']); // อนุมัติแล้วหายไป
        $this->assertNotContains("txn:{$tx->id}", $this->ids());
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

    public function test_group_without_leader_is_reported(): void
    {
        $this->actAs(Role::Member);
        $g = Group::factory()->create();

        $res = $this->getJson('/api/council/alerts')->json();
        $this->assertSame("no_leader:{$g->id}", $res['data'][0]['id']);
        $this->assertSame('high', $res['data'][0]['severity']);
        $this->assertSame("/manage-groups.html?id={$g->id}", $res['data'][0]['link']);
    }

    public function test_alerts_disappear_when_the_problem_is_fixed(): void
    {
        $this->actAs(Role::Member);
        $gang = Group::factory()->create();
        $this->assertContains("no_leader:{$gang->id}", $this->ids());

        GroupMember::factory()->position(MemberPosition::Leader)->create(['group_id' => $gang->id]);
        $this->assertSame([], $this->ids());
    }

    public function test_member_count_is_never_alerted(): void
    {
        $this->actAs(Role::Member);
        // มีแค่หัวหน้า ไม่ถึงขั้นต่ำ 10 คน/6 คน ก็ไม่แจ้งเตือน (ระบบเก็บเฉพาะรายชื่อหัวหน้าและรอง)
        foreach ([Group::factory()->create(), Group::factory()->family()->create()] as $g) {
            GroupMember::factory()->position(MemberPosition::Leader)->create(['group_id' => $g->id]);
        }

        $this->assertSame([], $this->ids());
    }

    public function test_long_locked_slots_are_flagged_but_recent_locks_are_not(): void
    {
        $this->actAs(Role::Member);

        $recent = Group::factory()->family()->create(['slots_locked' => true]);
        $old = Group::factory()->family()->create(['slots_locked' => true]);
        foreach ([$recent, $old] as $g) {
            GroupMember::factory()->position(MemberPosition::Leader)->create(['group_id' => $g->id]);
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
