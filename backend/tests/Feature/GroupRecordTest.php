<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\GroupStatus;
use App\Models\DutyShift;
use App\Models\Group;
use App\Models\GroupRecord;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** บันทึกของทะเบียน: รายงานตัว / ค่าปรับ / ปรับ Protect */
class GroupRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['council.duty.enforced' => true, 'council.duty.exempt_roles' => [], 'council.duty.max_hours' => 12, 'council.duty.heartbeat_grace_minutes' => 10]);
    }

    private function council(Role $role = Role::Member, bool $onDuty = true): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        if ($onDuty) {
            DutyShift::create(['user_id' => $u->id, 'started_at' => now()->subMinutes(20), 'last_seen_at' => now()]);
        }
        Sanctum::actingAs($u);

        return $u;
    }

    private function url(Group $g, string $suffix = '/records'): string
    {
        return "/api/council/groups/{$g->id}{$suffix}";
    }

    public function test_only_on_duty_council_can_read_or_write_records(): void
    {
        $g = Group::factory()->create();

        $this->council(Role::Gang);
        $this->getJson($this->url($g))->assertForbidden();
        $this->postJson($this->url($g), ['kind' => 'report', 'status' => 'normal'])->assertForbidden();

        $this->council(Role::Admin);
        $this->postJson($this->url($g), ['kind' => 'report', 'status' => 'normal'])->assertForbidden();

        $this->council(Role::Member, onDuty: false);
        $this->postJson($this->url($g), ['kind' => 'report', 'status' => 'normal'])->assertForbidden()->assertJsonPath('code', 'off_duty');
        $this->assertDatabaseCount('group_records', 0);
    }

    public function test_report_is_recorded_and_updates_last_report(): void
    {
        $user = $this->council();
        $g = Group::factory()->create(['registered_at' => now()->subDays(30)]);

        // ยังไม่เคยรายงานตัว: ใช้วันขึ้นทะเบียน
        $this->getJson($this->url($g, ''))->assertOk()->assertJsonPath('data.last_report_at', $g->registered_at->toJSON());

        $this->postJson($this->url($g), ['kind' => 'report', 'status' => 'normal', 'note' => 'มารายงานตัวที่หน้าสภา'])->assertCreated()
            ->assertJsonPath('data.kind', 'report')->assertJsonPath('data.note', 'มารายงานตัวที่หน้าสภา')->assertJsonPath('data.recorded_by', $user->character_name ?: $user->name);

        $this->getJson($this->url($g, ''))->assertOk();
        $last = $this->getJson($this->url($g, ''))->json('data.last_report_at');
        $this->assertTrue(\Illuminate\Support\Carbon::parse($last)->gt($g->registered_at));
        $this->assertTrue(Activity::where('log_name', 'groups')->where('description', 'like', 'บันทึกรายงานตัว%')->exists());
    }

    public function test_report_is_a_status_button_normal_or_inactive(): void
    {
        $user = $this->council();
        $g = Group::factory()->create(['registered_at' => now()->subDays(30)]);

        // ต้องเลือกสถานะ และเลือกได้แค่ ปกติ / ขาดการเคลื่อนไหว
        $this->postJson($this->url($g), ['kind' => 'report'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($this->url($g), ['kind' => 'report', 'status' => 'blacklist'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('group_records', 0);

        // ขาดการเคลื่อนไหว: เปลี่ยนสถานะกลุ่ม + ประวัติสถานะ + ไม่นับเป็นรายงานตัวล่าสุด
        $this->postJson($this->url($g), ['kind' => 'report', 'status' => 'inactive', 'note' => 'ไม่เข้าเกมมา 2 สัปดาห์'])->assertCreated()
            ->assertJsonPath('data.kind', 'inactive')->assertJsonPath('data.data.status', 'inactive')->assertJsonPath('data.data.previous_status', 'normal');
        $g->refresh();
        $this->assertSame(GroupStatus::Inactive, $g->status);
        $this->assertSame('ไม่เข้าเกมมา 2 สัปดาห์', $g->status_reason);
        $this->assertDatabaseHas('group_status_history', ['group_id' => $g->id, 'status' => 'inactive', 'changed_by' => $user->id, 'reason' => 'ไม่เข้าเกมมา 2 สัปดาห์']);
        $this->getJson($this->url($g, ''))->assertOk()->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.status_label', 'ขาดการเคลื่อนไหว')
            ->assertJsonPath('data.last_report_at', $g->registered_at->toJSON());

        // กลับเป็นปกติ = รายงานตัว: นับเป็นรายงานตัวล่าสุด และเคลียร์เหตุผลสถานะ
        $this->postJson($this->url($g), ['kind' => 'report', 'status' => 'normal'])->assertCreated()->assertJsonPath('data.kind', 'report');
        $g->refresh();
        $this->assertSame(GroupStatus::Normal, $g->status);
        $this->assertNull($g->status_reason);
        $last = $this->getJson($this->url($g, ''))->json('data.last_report_at');
        $this->assertTrue(\Illuminate\Support\Carbon::parse($last)->gt($g->registered_at));
        $this->assertSame(1, $g->statusHistory()->whereNull('ended_at')->count(), 'มีสถานะที่กำลังใช้อยู่ 1 แถวเสมอ');

        // เลือกสถานะเดิมซ้ำ: ไม่เพิ่มประวัติสถานะ แต่บันทึกรายงานตัวได้
        $before = $g->statusHistory()->count();
        $this->postJson($this->url($g), ['kind' => 'report', 'status' => 'normal'])->assertCreated();
        $this->assertSame($before, $g->statusHistory()->count());
    }

    public function test_report_cannot_override_blacklist_or_sanction(): void
    {
        $this->council();
        foreach ([GroupStatus::Blacklist, GroupStatus::Sanction] as $status) {
            $g = Group::factory()->status($status, 'ผิดกฎ')->create();

            $this->postJson($this->url($g), ['kind' => 'report', 'status' => 'normal'])->assertUnprocessable();
            $this->postJson($this->url($g), ['kind' => 'report', 'status' => 'inactive'])->assertUnprocessable();
            $this->assertSame($status, $g->fresh()->status);
        }
        $this->assertDatabaseCount('group_records', 0);
    }

    public function test_fine_validation_and_creation(): void
    {
        $this->council();
        $g = Group::factory()->create();

        $this->postJson($this->url($g), ['kind' => 'fine'])->assertUnprocessable()->assertJsonValidationErrors(['category', 'amount', 'charge']);
        $this->postJson($this->url($g), ['kind' => 'fine', 'category' => 'nope', 'amount' => 100, 'charge' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->postJson($this->url($g), ['kind' => 'fine', 'category' => 'general', 'amount' => 0, 'charge' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($this->url($g), ['kind' => 'fine', 'category' => 'general', 'amount' => 'abc', 'charge' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('group_records', 0);

        $this->postJson($this->url($g), ['kind' => 'fine', 'category' => 'general', 'amount' => 250000, 'charge' => 'ผิดกฎข้อ 3', 'note' => 'ยังไม่ชำระ'])->assertCreated()
            ->assertJsonPath('data.data.amount', 250000)->assertJsonPath('data.data.category_label', 'กฎสภา')->assertJsonPath('data.data.charge', 'ผิดกฎข้อ 3');

        $list = $this->getJson($this->url($g))->assertOk();
        $list->assertJsonCount(1, 'data');
        $this->assertContains('general', collect($list->json('meta.fine_categories'))->pluck('value')->all());
        $this->assertSame('กฎสภา', collect($list->json('meta.fine_categories'))->firstWhere('value', 'general')['label']);
    }

    public function test_fine_choices_come_from_the_rules_table(): void
    {
        $this->council();
        $g = Group::factory()->create();
        $a = Rule::create(['section' => 'general', 'sort_order' => 1, 'kind' => 'rule', 'body' => 'ห้ามถืออาวุธในเขตสภา', 'penalty' => 'ฝ่าฝืนปรับ 500,000 WVDL / รายบุคคล หรือบำเพ็ญ 2,000 ไม้', 'is_published' => true]);
        $range = Rule::create(['section' => 'story', 'sort_order' => 1, 'kind' => 'rule', 'body' => 'ห้ามออกนอกวงสตอรี่', 'penalty' => 'ปรับ 500,000 - 5,000,000 WVDL ต่อครั้ง', 'is_published' => true]);
        Rule::create(['section' => 'general', 'sort_order' => 2, 'kind' => 'rule', 'body' => 'ข้อที่ไม่มีบทลงโทษ', 'penalty' => null, 'is_published' => true]);
        Rule::create(['section' => 'general', 'sort_order' => 3, 'kind' => 'text', 'body' => 'ย่อหน้าธรรมดา', 'penalty' => 'ปรับ 1,000,000 WVDL', 'is_published' => true]);
        $hidden = Rule::create(['section' => 'staff', 'sort_order' => 1, 'kind' => 'rule', 'body' => 'ข้อที่ยังไม่เผยแพร่', 'penalty' => 'ปรับ 1,000,000 WVDL', 'is_published' => false]);

        $meta = $this->getJson($this->url($g))->assertOk()->json('meta');

        // หมวด: เฉพาะที่มีข้อกฎเป็นเหตุปรับได้ (เผยแพร่ + kind=rule + มีบทลงโทษ)
        $this->assertSame(['general', 'story'], collect($meta['fine_sections'])->pluck('value')->all());
        $this->assertSame('กฎสภา', collect($meta['fine_sections'])->firstWhere('value', 'general')['label']);

        $rules = collect($meta['fine_rules']);
        $this->assertSame([$a->id, $range->id], $rules->pluck('id')->all());
        $this->assertNotContains($hidden->id, $rules->pluck('id')->all());
        $this->assertSame(500000, $rules->firstWhere('id', $a->id)['amount'], 'มีจำนวนเงินเดียว → เสนอให้อัตโนมัติ');
        $this->assertNull($rules->firstWhere('id', $range->id)['amount'], 'เป็นช่วง → ให้ผู้ใช้เลือกเอง');
        $this->assertSame(500000, $rules->firstWhere('id', $range->id)['amount_min']);
        $this->assertSame(5000000, $rules->firstWhere('id', $range->id)['amount_max']);
        $this->assertSame('ห้ามถืออาวุธในเขตสภา', $rules->firstWhere('id', $a->id)['text']);
    }

    public function test_fine_from_a_rule_takes_category_and_charge_from_the_rule_and_keeps_a_snapshot(): void
    {
        $this->council();
        $g = Group::factory()->create();
        $rule = Rule::create(['section' => 'story', 'sort_order' => 1, 'kind' => 'rule', 'body' => 'ห้ามออกนอกวงสตอรี่', 'penalty' => 'ปรับ 1,000,000 WVDL ต่อคน', 'is_published' => true]);

        // ไม่ต้องส่งหมวด/ข้อหา: ดึงจากข้อกฎ (ค่าที่ส่งมาทับไม่ได้)
        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => $rule->id, 'amount' => 1000000, 'category' => 'general', 'charge' => 'อย่างอื่น'])->assertCreated()
            ->assertJsonPath('data.data.category', 'story')->assertJsonPath('data.data.category_label', 'กฎการเล่นสตอรี่')
            ->assertJsonPath('data.data.charge', 'ห้ามออกนอกวงสตอรี่')->assertJsonPath('data.data.rule_id', $rule->id)
            ->assertJsonPath('data.data.rule_penalty', 'ปรับ 1,000,000 WVDL ต่อคน')->assertJsonPath('data.data.amount', 1000000);

        // แก้กฎทีหลัง ประวัติยังเป็นข้อความตอนบันทึก
        $rule->update(['body' => 'แก้ข้อความใหม่', 'penalty' => 'ปรับ 9,000,000 WVDL']);
        $this->getJson($this->url($g))->assertOk()->assertJsonPath('data.0.data.charge', 'ห้ามออกนอกวงสตอรี่')->assertJsonPath('data.0.data.rule_penalty', 'ปรับ 1,000,000 WVDL ต่อคน');
    }

    public function test_fine_rule_must_exist_and_be_published_and_other_still_needs_category_and_charge(): void
    {
        $this->council();
        $g = Group::factory()->create();
        $hidden = Rule::create(['section' => 'general', 'sort_order' => 1, 'kind' => 'rule', 'body' => 'x', 'penalty' => 'ปรับ 500,000 WVDL', 'is_published' => false]);

        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => 999999, 'amount' => 100])->assertUnprocessable()->assertJsonValidationErrors('rule_id');
        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => $hidden->id, 'amount' => 100])->assertUnprocessable()->assertJsonValidationErrors('rule_id');
        $this->postJson($this->url($g), ['kind' => 'fine', 'amount' => 100])->assertUnprocessable()->assertJsonValidationErrors(['category', 'charge']);
        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => $hidden->id])->assertUnprocessable();
        $this->assertDatabaseCount('group_records', 0);

        // ข้อกฎที่เลือกแล้วยังต้องใส่จำนวนเงิน
        $ok = Rule::create(['section' => 'general', 'sort_order' => 2, 'kind' => 'rule', 'body' => 'y', 'penalty' => 'ปรับ 500,000 WVDL', 'is_published' => true]);
        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => $ok->id])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => $ok->id, 'amount' => 500000])->assertCreated();
    }

    public function test_fine_can_be_a_money_fine_or_a_penance(): void
    {
        $this->council();
        $g = Group::factory()->create();
        $rule = Rule::create(['section' => 'general', 'sort_order' => 1, 'kind' => 'rule', 'body' => 'ห้ามถืออาวุธ', 'penalty' => 'ฝ่าฝืนปรับ 500,000 WVDL / รายบุคคล หรือบำเพ็ญ 2,000 ไม้', 'is_published' => true]);

        // ส่งบำเพ็ญ: ต้องระบุจำนวนไม้ ไม่ต้องมีจำนวนเงิน
        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => $rule->id, 'penalty_type' => 'penance'])->assertUnprocessable()->assertJsonValidationErrors('penance_units');
        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => $rule->id, 'penalty_type' => 'penance', 'penance_units' => 0])->assertUnprocessable()->assertJsonValidationErrors('penance_units');
        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => $rule->id, 'penalty_type' => 'penance', 'penance_units' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('penance_units');
        $this->assertDatabaseCount('group_records', 0);

        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => $rule->id, 'penalty_type' => 'penance', 'penance_units' => 2000, 'amount' => 999])->assertCreated()
            ->assertJsonPath('data.data.penalty_type', 'penance')->assertJsonPath('data.data.penance_units', 2000)->assertJsonPath('data.data.amount', null)
            ->assertJsonPath('data.data.charge', 'ห้ามถืออาวุธ');

        // ค่าปรับ (ค่าเริ่มต้น): ต้องมีจำนวนเงิน และไม่เก็บจำนวนไม้
        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => $rule->id, 'penalty_type' => 'fine'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => $rule->id, 'penalty_type' => 'fine', 'amount' => 500000, 'penance_units' => 5])->assertCreated()
            ->assertJsonPath('data.data.penalty_type', 'fine')->assertJsonPath('data.data.amount', 500000)->assertJsonPath('data.data.penance_units', null);
        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => $rule->id, 'amount' => 500000])->assertCreated()->assertJsonPath('data.data.penalty_type', 'fine');

        $this->postJson($this->url($g), ['kind' => 'fine', 'rule_id' => $rule->id, 'penalty_type' => 'nope', 'amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('penalty_type');
        $this->assertTrue(Activity::where('log_name', 'groups')->where('description', 'like', 'บันทึกส่งบำเพ็ญ%')->exists());
    }

    public function test_fine_rules_expose_the_penance_suggestion_from_the_rule(): void
    {
        $this->council();
        $g = Group::factory()->create();
        $both = Rule::create(['section' => 'general', 'sort_order' => 1, 'kind' => 'rule', 'body' => 'a', 'penalty' => 'ปรับ 500,000 WVDL หรือบำเพ็ญ 2,000 ไม้', 'is_published' => true]);
        $only = Rule::create(['section' => 'general', 'sort_order' => 2, 'kind' => 'rule', 'body' => 'b', 'penalty' => 'ฝ่าฝืนบำเพ็ญ 1,000 ไม้ / 1 คำ', 'is_published' => true]);
        $money = Rule::create(['section' => 'general', 'sort_order' => 3, 'kind' => 'rule', 'body' => 'c', 'penalty' => 'ปรับ 1,000,000 WVDL', 'is_published' => true]);

        $rules = collect($this->getJson($this->url($g))->json('meta.fine_rules'));
        $this->assertSame(['amount' => 500000, 'penance' => 2000], $rules->firstWhere('id', $both->id) ? ['amount' => $rules->firstWhere('id', $both->id)['amount'], 'penance' => $rules->firstWhere('id', $both->id)['penance']] : []);
        $this->assertNull($rules->firstWhere('id', $only->id)['amount']);
        $this->assertSame(1000, $rules->firstWhere('id', $only->id)['penance']);
        $this->assertNull($rules->firstWhere('id', $money->id)['penance']);
    }

    public function test_protect_can_be_set_extended_and_cancelled_with_before_after_log(): void
    {
        $this->council();
        $g = Group::factory()->create(['protect_requested' => false]);
        $until = now()->addDays(10)->toIso8601String();

        $this->postJson($this->url($g), ['kind' => 'protect', 'protect_until' => $until])->assertCreated();
        $g->refresh();
        $this->assertTrue($g->protect_requested);
        $this->assertNotNull($g->protect_from);
        $this->assertEqualsWithDelta(now()->addDays(10)->timestamp, $g->protect_until->timestamp, 5);

        $log = Activity::where('log_name', 'groups')->latest('id')->firstOrFail();
        $this->assertNull($log->properties['before']['protect_until']);
        $this->assertNotNull($log->properties['after']['protect_until']);

        // เลื่อนวันสิ้นสุด
        $this->postJson($this->url($g), ['kind' => 'protect', 'protect_until' => now()->addDays(20)->toIso8601String()])->assertCreated();
        $this->assertEqualsWithDelta(now()->addDays(20)->timestamp, $g->fresh()->protect_until->timestamp, 5);

        // เว้นว่าง = ยกเลิก
        $this->postJson($this->url($g), ['kind' => 'protect', 'protect_until' => null])->assertCreated();
        $g->refresh();
        $this->assertNull($g->protect_until);
        $this->assertFalse($g->protect_requested);
        $this->assertSame(3, GroupRecord::where('kind', 'protect')->count());
    }

    public function test_protect_end_must_be_in_the_future_and_after_the_start(): void
    {
        $this->council();
        $g = Group::factory()->create(['protect_from' => now()->addDays(5), 'protect_until' => now()->addDays(10)]);

        $this->postJson($this->url($g), ['kind' => 'protect', 'protect_until' => now()->subDay()->toIso8601String()])->assertUnprocessable();
        $this->postJson($this->url($g), ['kind' => 'protect', 'protect_until' => now()->addDays(2)->toIso8601String()])->assertUnprocessable(); // ก่อนวันเริ่ม
        $this->postJson($this->url($g), ['kind' => 'protect', 'protect_until' => 'not a date'])->assertUnprocessable()->assertJsonValidationErrors('protect_until');
        $this->assertEqualsWithDelta(now()->addDays(10)->timestamp, $g->fresh()->protect_until->timestamp, 5);
    }

    public function test_outfit_counts_can_be_edited_and_are_logged(): void
    {
        $this->council();
        $g = Group::factory()->create();

        $this->postJson($this->url($g), ['kind' => 'outfit', 'counts' => ['suit' => 1, 'hoodie' => 1, 'gun' => '', 'mod1' => null, 'polo' => 0, 'accessories' => 1]])->assertCreated();
        $this->assertSame(['suit' => 1, 'hoodie' => 1, 'polo' => 0, 'accessories' => 1], $g->fresh()->outfit_counts);
        $this->getJson($this->url($g, ''))->assertOk()->assertJsonPath('data.outfit_counts.suit', 1)->assertJsonPath('data.outfit_counts.polo', 0);

        // แก้ใหม่: ช่องที่เว้นว่างกลับเป็น "ยังไม่ลงทะเบียน"
        $this->postJson($this->url($g), ['kind' => 'outfit', 'counts' => ['suit' => 2]])->assertCreated();
        $this->assertSame(['suit' => 2], $g->fresh()->outfit_counts);

        $log = Activity::where('log_name', 'groups')->latest('id')->firstOrFail();
        $this->assertSame(1, $log->properties['before']['suit']);
        $this->assertSame(2, $log->properties['after']['suit']);
        $this->assertSame(2, GroupRecord::where('kind', 'outfit')->count());
    }

    public function test_outfit_edit_writes_one_activity_log_that_says_what_changed(): void
    {
        $this->council();
        $g = Group::factory()->create(['outfit_counts' => ['suit' => 1]]);

        $this->postJson($this->url($g), ['kind' => 'outfit', 'counts' => ['suit' => 2, 'polo' => 1]])->assertCreated();

        // เดิมเขียนซ้ำเป็น "บันทึกค่าปรับ" อีกแถว: ต้องมีแถวเดียว
        $logs = Activity::where('log_name', 'groups')->get();
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('แก้ไขจำนวนชุด', $logs[0]->description);

        $vp = $this->council(Role::VicePresident);
        $row = collect($this->getJson('/api/logs?log=groups')->assertOk()->json('data'))->firstWhere('log', 'groups');
        $this->assertContains(['Suit', '1 ชิ้น → 2 ชิ้น'], $row['details']);
        $this->assertContains(['Polo', '— → 1 ชิ้น'], $row['details']);
    }

    public function test_fine_and_report_logs_have_details(): void
    {
        $this->council();
        $g = Group::factory()->create();

        $this->postJson($this->url($g), ['kind' => 'fine', 'category' => 'gang', 'charge' => 'ทะเลาะในเมือง', 'amount' => 500000, 'note' => 'ครั้งแรก'])->assertCreated();

        $this->council(Role::VicePresident);
        $row = collect($this->getJson('/api/logs?log=groups')->assertOk()->json('data'))->firstWhere('log', 'groups');
        $lines = collect($row['details'])->map(fn ($l) => $l[0].': '.$l[1])->implode(' | ');
        $this->assertStringContainsString('ประเภท: ค่าปรับ', $lines);
        $this->assertStringContainsString('ข้อหา: ทะเลาะในเมือง', $lines);
        $this->assertStringContainsString('จำนวนเงิน: 500,000 WVDL', $lines);
        $this->assertStringContainsString('หมายเหตุ: ครั้งแรก', $lines);
    }

    public function test_outfit_counts_validation_and_family_limits(): void
    {
        $this->council();
        $gang = Group::factory()->create();
        $family = Group::factory()->family()->create();

        $this->postJson($this->url($gang), ['kind' => 'outfit', 'counts' => ['suit' => -1]])->assertUnprocessable()->assertJsonValidationErrors('counts.suit');
        $this->postJson($this->url($gang), ['kind' => 'outfit', 'counts' => ['suit' => 'abc']])->assertUnprocessable()->assertJsonValidationErrors('counts.suit');
        $this->postJson($this->url($gang), ['kind' => 'outfit'])->assertUnprocessable()->assertJsonValidationErrors('counts');

        $this->postJson($this->url($family), ['kind' => 'outfit', 'counts' => ['suit' => 3]])->assertUnprocessable()->assertJsonValidationErrors('counts.suit');
        $this->postJson($this->url($family), ['kind' => 'outfit', 'counts' => ['hoodie' => 5, 'tshirt' => 6, 'polo' => 7, 'accessories' => 1]])->assertUnprocessable()->assertJsonValidationErrors(['counts.hoodie', 'counts.tshirt', 'counts.polo']);
        $this->postJson($this->url($family), ['kind' => 'outfit', 'counts' => ['hoodie' => 1, 'tshirt' => 1, 'polo' => 1, 'accessories' => 1]])->assertCreated();
        $this->assertSame(['hoodie' => 1, 'tshirt' => 1, 'polo' => 1, 'accessories' => 1], $family->fresh()->outfit_counts);
        $this->assertNull($gang->fresh()->outfit_counts);
    }

    public function test_invalid_kind_and_records_are_append_only(): void
    {
        $this->council();
        $g = Group::factory()->create();

        $this->postJson($this->url($g), ['kind' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $id = $this->postJson($this->url($g), ['kind' => 'report', 'status' => 'normal'])->json('data.id');

        $this->patchJson($this->url($g, "/records/{$id}"), ['note' => 'x'])->assertStatus(404);
        $this->deleteJson($this->url($g, "/records/{$id}"))->assertStatus(404);
        $this->assertDatabaseCount('group_records', 1);
    }
}
