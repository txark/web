<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\MemberPosition;
use App\Enums\Role;
use App\Models\DutyShift;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** ธุรกรรมกลางของแก๊ง/ครอบครัว: สิทธิ์ ผลต่อกลุ่มตามชนิด กติกา ขั้นอนุมัติ และ log ก่อน-หลัง */
class TransactionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/council/transactions';

    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR";

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'council.duty.enforced' => true,
            'council.duty.exempt_roles' => [],
            'council.duty.max_hours' => 12,
            'council.duty.heartbeat_grace_minutes' => 10,
        ]);
        Storage::fake('public');
        Storage::fake('local');
    }

    /** สภาที่ล็อกอินและ (ค่าเริ่มต้น) เข้าเวรอยู่ */
    private function council(Role $role = Role::Member, bool $onDuty = true): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        if ($onDuty) {
            DutyShift::create(['user_id' => $u->id, 'started_at' => now()->subMinutes(20), 'last_seen_at' => now()]);
        }
        Sanctum::actingAs($u);

        return $u;
    }

    private function group(array $attrs = [], string $type = 'gang'): Group
    {
        $g = Group::factory()->state(array_merge(['type' => $type, 'registered_at' => now()->subDays(100)], $attrs))->create();
        $g->members()->create(['character_name' => 'Boss One', 'position' => MemberPosition::Leader, 'joined_at' => now()->subDays(50)]);

        return $g;
    }

    private function member(Group $g, string $name, MemberPosition $pos = MemberPosition::Member, bool $reserve = false): GroupMember
    {
        return $g->members()->create(['character_name' => $name, 'position' => $pos, 'is_reserve' => $reserve, 'joined_at' => now()->subDays(30)]);
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PNG);
    }

    /** @return array<string, mixed> */
    private function registerPayload(array $over = []): array
    {
        return array_merge([
            'type' => 'gang',
            'name' => 'Wolves',
            'short_name' => 'WLV',
            'color_hex' => '#ff0000',
            'color_name' => 'แดง',
            'leader' => ['name' => 'Boss One', 'discord_id' => '123456789012345678', 'phone' => '555-0100'],
            'co_leaders' => [['name' => 'Deputy One', 'discord_id' => '223456789012345678', 'phone' => '555-0101']],
            'declared_members' => 12,
        ], $over);
    }

    private function send(string $kind, ?Group $g, array $payload, array $extra = [])
    {
        return $this->postJson(self::URL, array_merge(['kind' => $kind, 'group_id' => $g?->id, 'payload' => $payload], $extra));
    }

    private function sendForm(string $kind, ?Group $g, array $payload, array $extra = [])
    {
        return $this->post(self::URL, array_merge(['kind' => $kind, 'group_id' => $g?->id, 'payload' => $payload], $extra), ['Accept' => 'application/json']);
    }

    // ───────── สิทธิ์ ─────────

    public function test_guests_gang_family_and_admin_cannot_use_transactions(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        foreach ([Role::Gang, Role::Family, Role::Admin] as $role) {
            $this->council($role);
            $this->getJson(self::URL)->assertForbidden();
            $this->send('dissolve', $this->group(), ['reason' => 'x'])->assertForbidden();
        }
    }

    public function test_council_must_be_on_duty(): void
    {
        $g = $this->group();
        $this->council(Role::Member, onDuty: false);

        $this->getJson(self::URL)->assertForbidden()->assertJsonPath('code', 'off_duty');
        $this->send('dissolve', $g, ['reason' => 'x'])->assertForbidden()->assertJsonPath('code', 'off_duty');
        $this->assertDatabaseCount('transactions', 0);
        $this->assertNull($g->fresh()->deleted_at);
    }

    public function test_every_council_rank_from_trainee_up_can_submit(): void
    {
        foreach ([Role::Trainee, Role::Member, Role::Operations, Role::Senior, Role::Secretary, Role::VicePresident, Role::President] as $role) {
            $this->council($role);
            $g = $this->group();
            $this->send('recolor', $g, ['color_hex' => '#00FF00', 'color_name' => 'เขียว'])->assertCreated()->assertJsonPath('data.status', 'approved');
        }
    }

    // ───────── ผลต่อกลุ่มตามชนิด + เลขธุรกรรม ─────────

    public function test_register_creates_the_group_with_leader_and_deputies_and_links_the_transaction(): void
    {
        $user = $this->council();

        $r = $this->send('register', null, $this->registerPayload())->assertCreated()
            ->assertJsonPath('data.kind', 'register')->assertJsonPath('data.kind_label', 'ขึ้นทะเบียน')
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.status_label', 'อนุมัติแล้ว')
            ->assertJsonPath('data.requested_by.id', $user->id)->assertJsonPath('data.requested_role', 'member')
            ->assertJsonPath('data.group.name', 'Wolves');

        $g = Group::where('name', 'Wolves')->firstOrFail();
        $this->assertSame('WLV', $g->short_name);
        $this->assertSame('#FF0000', $g->color_hex);
        $this->assertSame('Boss One', $g->leader->character_name);
        $this->assertSame(2, $g->activeMembers()->count());
        $this->assertSame(GroupStatus::Normal, $g->status);
        $this->assertDatabaseHas('group_status_history', ['group_id' => $g->id, 'status' => 'normal']);
        $this->assertSame($g->id, Transaction::firstOrFail()->group_id);
        $this->assertSame($g->id, $r->json('data.group.id'));
    }

    public function test_transaction_number_format_uses_bangkok_date_and_is_unique(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 15)->setTime(20, 0)); // 20:00 UTC = 03:00 วันที่ 16 เวลาไทย
        $this->council();
        $g = $this->group();

        $numbers = [];
        for ($i = 0; $i < 12; $i++) {
            $numbers[] = $this->send('recolor', $g, ['color_hex' => sprintf('#00%02X00', 128 + $i), 'color_name' => 'เขียว'])->assertCreated()->json('data.number');
        }

        foreach ($numbers as $n) {
            $this->assertMatchesRegularExpression('/^WC-20260616-[A-Z0-9]{6}$/', $n);
        }
        $this->assertSame($numbers, array_values(array_unique($numbers)));
    }

    public function test_rename_applies_name_short_name_and_logo_and_starts_the_cooldown(): void
    {
        $this->council();
        $g = $this->group(['name' => 'OldName']);

        $this->sendForm('rename', $g, ['name' => 'NewName', 'short_name' => 'NN', 'logo' => $this->png()])
            ->assertCreated()->assertJsonPath('data.status', 'approved');

        $g->refresh();
        $this->assertSame('NewName', $g->name);
        $this->assertSame('NN', $g->short_name);
        $this->assertNotNull($g->renamed_at);
        Storage::disk('public')->assertExists($g->logo_path);
        $this->assertStringContainsString('/storage/groups/logos/', $g->logo_url);
        $this->assertEmpty(Storage::disk('local')->allFiles(), 'ไฟล์ชั่วคราวต้องถูกย้ายออกเมื่อธุรกรรมมีผล');

        // เปลี่ยนซ้ำทันที → ติดคูลดาวน์ 30 วัน และไม่มีอะไรเปลี่ยน
        $this->sendForm('rename', $g, ['name' => 'Third', 'short_name' => 'TT', 'logo' => $this->png()])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('NewName', $g->fresh()->name);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_rename_is_blocked_before_twenty_days_after_registration(): void
    {
        $this->council();
        $g = $this->group(['registered_at' => now()->subDays(5)]);

        $this->sendForm('rename', $g, ['name' => 'Fresh', 'short_name' => 'FR', 'logo' => $this->png()])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertNotSame('Fresh', $g->fresh()->name);
    }

    public function test_recolor_changes_the_color(): void
    {
        $this->council();
        $g = $this->group(['color_hex' => '#111111', 'color_name' => 'ดำ']);

        $this->send('recolor', $g, ['color_hex' => '#abcdef', 'color_name' => 'ฟ้า'])->assertCreated();

        $this->assertSame('#ABCDEF', $g->fresh()->color_hex);
        $this->assertSame('ฟ้า', $g->fresh()->color_name);
        $this->send('recolor', $g, ['color_hex' => '#ABCDEF', 'color_name' => 'ฟ้า'])->assertUnprocessable()->assertJsonValidationErrors('color_hex');
    }

    public function test_change_leader_creates_a_new_leader_and_keeps_the_old_one_as_member(): void
    {
        $this->council();
        $g = $this->group();

        $this->send('change_leader', $g, ['name' => 'New Boss', 'discord_id' => '323456789012345678', 'phone' => '555-0200'])->assertCreated();

        $g->refresh();
        $this->assertSame('New Boss', $g->leader->character_name);
        $this->assertSame('323456789012345678', $g->leader->discord_id);
        $this->assertSame(MemberPosition::Member, $g->activeMembers()->where('character_name', 'Boss One')->first()->position);
        $this->assertSame(1, $g->activeMembers()->where('position', 'leader')->count());
    }

    public function test_change_leader_can_promote_an_existing_member(): void
    {
        $this->council();
        $g = $this->group();
        $m = $this->member($g, 'Sam');

        $this->send('change_leader', $g, ['name' => 'sam', 'discord_id' => '323456789012345678', 'phone' => '555-0200'])->assertCreated();

        $this->assertSame($m->id, $g->fresh()->leader->id);
        $this->send('change_leader', $g, ['name' => 'Sam', 'discord_id' => '323456789012345678', 'phone' => '555-0200'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_release_slot_removes_the_member_when_the_slot_matches(): void
    {
        $this->council();
        $g = $this->group();
        $main = $this->member($g, 'Main Guy');
        $res = $this->member($g, 'Reserve Guy', MemberPosition::Member, true);

        $this->send('release_slot', $g, ['member_name' => 'Main Guy', 'reason' => 'มายืนยันตัวตนที่หน้าสภา', 'slot' => 'reserve'])
            ->assertUnprocessable()->assertJsonValidationErrors('slot');
        $this->assertNull($main->fresh()->left_at);

        $this->send('release_slot', $g, ['member_name' => 'main guy', 'reason' => 'มายืนยันตัวตนที่หน้าสภา', 'slot' => 'main'])->assertCreated();
        $this->send('release_slot', $g, ['member_name' => 'Reserve Guy', 'reason' => 'ยืนยันตัวตนแล้ว', 'slot' => 'reserve'])->assertCreated();

        $this->assertNotNull($main->fresh()->left_at);
        $this->assertNotNull($res->fresh()->left_at);
        $this->assertSame(1, $g->activeMembers()->count());
    }

    public function test_release_only_needs_the_member_name_and_reason_and_slot_are_optional(): void
    {
        $this->council();
        $g = $this->group();
        $a = $this->member($g, 'Deputy A', MemberPosition::CoLeader);
        $b = $this->member($g, 'Deputy B', MemberPosition::CoLeader);

        $this->send('release_slot', $g, ['member_name' => 'deputy a'])->assertCreated();
        $this->send('release_free', $g, ['member_name' => 'Deputy B'])->assertCreated();

        $this->assertNotNull($a->fresh()->left_at);
        $this->assertNotNull($b->fresh()->left_at);
        $this->assertSame(1, $g->activeMembers()->count());
    }

    public function test_release_accepts_many_names_and_charges_unit_price_times_count(): void
    {
        $this->council();
        $g = $this->group();
        $a = $this->member($g, 'Deputy A', MemberPosition::CoLeader);
        $b = $this->member($g, 'Deputy B', MemberPosition::CoLeader);

        // ออกสล็อต 3 ชื่อ (2 คนอยู่ในรายชื่อ + 1 ชื่อที่ไม่อยู่) = 300,000 x 3
        $r = $this->send('release_slot', $g, ['member_names' => ['Deputy A', 'deputy b', 'Somebody Else']])->assertCreated()
            ->assertJsonPath('data.payload.fee.unit_price', 300000)->assertJsonPath('data.payload.fee.count', 3)
            ->assertJsonPath('data.payload.fee.total', 900000)->assertJsonPath('data.payload.fee.currency', 'WVDL');
        $this->assertStringContainsString('900,000', $r->json('data.summary'));
        $this->assertNotNull($a->fresh()->left_at);
        $this->assertNotNull($b->fresh()->left_at);
        $this->assertSame(1, $g->activeMembers()->count());

        // ออกลอย 2 ชื่อ = 500,000 x 2 (ส่งเป็นข้อความหลายบรรทัดได้)
        $this->send('release_free', $g, ['member_names' => "X One\nX Two"])->assertCreated()
            ->assertJsonPath('data.payload.fee.unit_price', 500000)->assertJsonPath('data.payload.fee.total', 1000000);

        // ชื่อเดียวแบบเดิม (member_name) ยังใช้ได้
        $this->send('release_free', $g, ['member_name' => 'Solo'])->assertCreated()->assertJsonPath('data.payload.fee.total', 500000);
    }

    public function test_release_total_is_computed_by_the_server_not_the_client(): void
    {
        $this->council();
        $g = $this->group();

        $this->send('release_slot', $g, ['member_names' => ['A', 'B'], 'fee' => ['unit_price' => 1, 'count' => 99, 'total' => 1]])->assertCreated()
            ->assertJsonPath('data.payload.fee.total', 600000)->assertJsonPath('data.payload.fee.count', 2);

        config(['council.fees.release_slot' => 250000]);
        $this->send('release_slot', $g, ['member_names' => ['C', 'D', 'E']])->assertCreated()->assertJsonPath('data.payload.fee.total', 750000);
    }

    public function test_release_names_are_trimmed_distinct_and_limited(): void
    {
        $this->council();
        $g = $this->group();

        $this->send('release_slot', $g, ['member_names' => ['Same', 'same']])->assertUnprocessable()->assertJsonValidationErrors('member_names.1');
        $this->send('release_slot', $g, ['member_names' => []])->assertUnprocessable()->assertJsonValidationErrors('member_names');
        $this->send('release_slot', $g, ['member_names' => ['  ', '']])->assertUnprocessable()->assertJsonValidationErrors('member_names');
        $this->send('release_slot', $g, ['member_names' => array_map(fn ($i) => "Name $i", range(1, 51))])->assertUnprocessable()->assertJsonValidationErrors('member_names');
        $this->assertDatabaseCount('transactions', 0);

        $this->send('release_slot', $g, ['member_names' => ['  Spaced  ', '', 'Other']])->assertCreated()->assertJsonPath('data.payload.fee.count', 2);
    }

    public function test_one_leader_among_many_names_blocks_the_whole_release(): void
    {
        $this->council();
        $g = $this->group();
        $dep = $this->member($g, 'Deputy A', MemberPosition::CoLeader);

        $this->send('release_free', $g, ['member_names' => ['Deputy A', 'Boss One']])->assertUnprocessable()->assertJsonValidationErrors('member_names');
        $this->assertNull($dep->fresh()->left_at, 'ไม่มีใครออกเลยถ้ามีชื่อหัวหน้าปนอยู่');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_index_exposes_the_fee_prices_for_the_ui(): void
    {
        $this->council();
        $this->getJson(self::URL)->assertOk()->assertJsonPath('fees.release_slot', 300000)->assertJsonPath('fees.release_free', 500000)->assertJsonPath('fees.currency', 'WVDL');
    }

    public function test_every_transaction_kind_computes_its_service_fee(): void
    {
        config(['council.fees.register' => 100000, 'council.fees.change_leader' => 250000, 'council.fees.dissolve' => 400000]);
        $this->council();
        $g = $this->group(['name' => 'Old Name']);
        $this->member($g, 'Deputy A', MemberPosition::CoLeader);

        // ขึ้นทะเบียน
        $this->send('register', null, $this->registerPayload())->assertCreated()
            ->assertJsonPath('data.payload.fee.total', 100000)->assertJsonPath('data.payload.fee.items.0.label', 'ขึ้นทะเบียน');

        // เปลี่ยนชื่อ = ค่าเปลี่ยนชื่อ 1,000,000 + ค่าเปลี่ยนโลโก้ 2,000,000
        $r = $this->sendForm('rename', $g, ['name' => 'New Name', 'short_name' => 'NN', 'logo' => $this->png()])->assertCreated()
            ->assertJsonPath('data.payload.fee.total', 3000000)->assertJsonPath('data.payload.fee.currency', 'WVDL')
            ->assertJsonCount(2, 'data.payload.fee.items')
            ->assertJsonPath('data.payload.fee.items.0.amount', 1000000)->assertJsonPath('data.payload.fee.items.1.amount', 2000000);
        $this->assertStringContainsString('3,000,000', $r->json('data.summary'));

        // เปลี่ยนสี
        $this->send('recolor', $g, ['color_hex' => '#0000FF', 'color_name' => 'น้ำเงิน'])->assertCreated()->assertJsonPath('data.payload.fee.total', 1000000);

        // เปลี่ยน Leader
        $this->send('change_leader', $g, ['name' => 'New Boss', 'discord_id' => '323456789012345678', 'phone' => '555-0200'])->assertCreated()
            ->assertJsonPath('data.payload.fee.total', 250000);

        // ออกสล็อต / ออกลอย ต่อคน
        $this->send('release_slot', $g, ['member_names' => ['A', 'B']])->assertCreated()->assertJsonPath('data.payload.fee.total', 600000);
        $this->send('release_free', $g, ['member_names' => ['C']])->assertCreated()->assertJsonPath('data.payload.fee.total', 500000);

        // ยุบ
        $this->send('dissolve', $g, ['reason' => 'x'])->assertCreated()->assertJsonPath('data.payload.fee.total', 400000);
    }

    public function test_a_kind_without_a_listed_price_costs_zero_and_the_client_cannot_set_the_fee(): void
    {
        $this->council();
        $g = $this->group();

        $r = $this->send('change_leader', $g, ['name' => 'New Boss', 'discord_id' => '323456789012345678', 'phone' => '555-0200', 'fee' => ['total' => 1]])->assertCreated()
            ->assertJsonPath('data.payload.fee.total', 0)->assertJsonPath('data.payload.fee.items.0.amount', 0);
        $this->assertStringNotContainsString('ค่าบริการ', $r->json('data.summary'), 'ไม่มีค่าบริการ = ไม่ต้องต่อท้ายสรุป');

        $this->send('recolor', $g, ['color_hex' => '#00FF00', 'color_name' => 'เขียว', 'fee' => ['total' => 5]])->assertCreated()->assertJsonPath('data.payload.fee.total', 1000000);
    }

    public function test_fee_prices_are_applied_from_config_and_kept_with_the_transaction(): void
    {
        $this->council();
        $g = $this->group();

        $id = $this->send('recolor', $g, ['color_hex' => '#00FF00', 'color_name' => 'เขียว'])->assertCreated()->json('data.id');
        config(['council.fees.recolor' => 9000000]); // ราคาเปลี่ยนทีหลังไม่กระทบรายการเก่า
        $this->getJson(self::URL."/{$id}")->assertOk()->assertJsonPath('data.payload.fee.total', 1000000);
        $this->send('recolor', $g, ['color_hex' => '#0000FF', 'color_name' => 'น้ำเงิน'])->assertCreated()->assertJsonPath('data.payload.fee.total', 9000000);

        $fees = $this->getJson(self::URL)->assertOk()->json('fees');
        foreach (['register', 'rename', 'rename_logo', 'recolor', 'change_leader', 'release_slot', 'release_free', 'dissolve', 'currency'] as $k) {
            $this->assertArrayHasKey($k, $fees);
        }
    }

    public function test_release_slot_respects_the_slot_lock_but_release_free_does_not(): void
    {
        $this->council();
        $g = $this->group(['slots_locked' => true, 'locked_reason' => 'ตรวจสอบ']);
        $m = $this->member($g, 'Locked Guy');

        $this->send('release_slot', $g, ['member_name' => 'Locked Guy', 'reason' => 'x', 'slot' => 'main'])->assertStatus(409);
        $this->assertNull($m->fresh()->left_at);
        $this->assertDatabaseCount('transactions', 0);

        $this->send('release_free', $g, ['member_name' => 'Locked Guy', 'reason' => 'ผู้ออกไม่มายืนยันตัวตน'])->assertCreated();
        $this->assertNotNull($m->fresh()->left_at);
    }

    public function test_release_rejects_unknown_members_and_the_leader(): void
    {
        $this->council();
        $g = $this->group();

        // ชื่อที่ไม่อยู่ในรายชื่อ (ระบบเก็บเฉพาะหัวหน้า/รอง): บันทึกธุรกรรมไว้ แต่ไม่แก้รายชื่อ
        $this->send('release_free', $g, ['member_name' => 'Nobody'])->assertCreated();
        $this->assertSame(1, $g->activeMembers()->count());
        $this->send('release_free', $g, ['member_name' => 'Boss One', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('member_names');
        $this->assertSame(1, $g->activeMembers()->count());
    }

    public function test_dissolve_soft_deletes_the_group_and_blocks_later_transactions(): void
    {
        $this->council();
        $g = $this->group();
        $this->member($g, 'Sam');

        $this->send('dissolve', $g, ['reason' => 'ไม่มีกิจกรรม'])->assertCreated()->assertJsonPath('data.group.dissolved', true);

        $this->assertSoftDeleted($g);
        $this->assertSame(0, $g->activeMembers()->count());
        $this->send('recolor', $g, ['color_hex' => '#00FF00', 'color_name' => 'เขียว'])->assertUnprocessable()->assertJsonValidationErrors('group_id');
        $this->send('dissolve', $g, ['reason' => 'ซ้ำ'])->assertUnprocessable()->assertJsonValidationErrors('group_id');
    }

    // ───────── ตรวจข้อมูลตามชนิด ─────────

    public function test_register_validation(): void
    {
        $this->council();
        $bad = fn (array $over, string $field) => $this->send('register', null, $this->registerPayload($over))->assertUnprocessable()->assertJsonValidationErrors($field);

        $bad(['name' => 'ชื่อไทย'], 'name');
        $bad(['short_name' => 'ABCDE'], 'short_name');
        $bad(['color_hex' => 'red'], 'color_hex');
        $bad(['color_name' => ''], 'color_name');
        $bad(['leader' => ['name' => 'Boss', 'discord_id' => '123', 'phone' => '555']], 'leader.discord_id');
        $bad(['leader' => ['name' => 'Boss', 'discord_id' => '123456789012345678', 'phone' => 'abc']], 'leader.phone');
        $dep = fn ($i) => ['name' => "Dep $i", 'discord_id' => '22345678901234567'.$i, 'phone' => '555-010'.$i];
        $bad(['co_leaders' => [$dep(1), $dep(2), $dep(3)]], 'co_leaders');                     // รองไม่เกิน 2
        $bad(['co_leaders' => [['name' => 'boss one', 'discord_id' => '223456789012345678', 'phone' => '555-0101']]], 'co_leaders'); // ชื่อซ้ำหัวหน้า
        $bad(['protect_from' => '2026-07-10', 'protect_until' => '2026-07-01'], 'protect_until');   // วัน Protect กลับด้าน
        $bad(['protect_from' => '2026-07-10', 'protect_until' => '2026-07-10'], 'protect_until');
        $bad(['protect_until' => '2026-07-01'], 'protect_from');

        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('transactions', 0);

        $this->send('register', null, $this->registerPayload(['protect_from' => '2026-07-01', 'protect_until' => '2026-07-15', 'co_leaders' => [$dep(1), $dep(2)]]))->assertCreated();
        $this->assertSame('2026-07-15', Group::first()->protect_until->toDateString());
        $this->assertSame(3, Group::first()->activeMembers()->count());
    }

    public function test_register_family_has_one_deputy_and_deputy_discord_id_is_optional(): void
    {
        $this->council();
        $dep = fn ($i) => ['name' => "Dep $i", 'discord_id' => '22345678901234567'.$i, 'phone' => '555-010'.$i];
        $family = ['type' => 'family', 'name' => 'Home', 'declared_members' => 8];

        $this->send('register', null, $this->registerPayload($family + ['co_leaders' => [$dep(1), $dep(2)]]))->assertUnprocessable()->assertJsonValidationErrors('co_leaders');
        $this->send('register', null, $this->registerPayload($family + ['co_leaders' => [['name' => 'Dep', 'phone' => '555-0101']]]))->assertCreated();
        $this->assertNull(GroupMember::where('character_name', 'Dep')->first()->discord_id);
    }

    public function test_names_short_name_case_and_color_name_rules_apply_to_register_rename_and_recolor(): void
    {
        $this->council();
        $g = $this->group(['name' => 'Old Name']);

        // ขึ้นทะเบียน
        foreach (['wlv', 'Wlv', 'ไทย'] as $bad) {
            $this->send('register', null, $this->registerPayload(['short_name' => $bad]))->assertUnprocessable()->assertJsonValidationErrors('short_name');
        }
        $this->send('register', null, $this->registerPayload(['name' => '999']))->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->send('register', null, $this->registerPayload(['color_hex' => '#FF0000', 'color_name' => 'เขียว']))->assertUnprocessable()->assertJsonValidationErrors('color_name');

        // เปลี่ยนชื่อ: ชื่อย่อต้องพิมพ์ใหญ่
        $this->sendForm('rename', $g, ['name' => 'NewName', 'short_name' => 'nn', 'logo' => $this->png()])->assertUnprocessable()->assertJsonValidationErrors('short_name');

        // เปลี่ยนสี: ชื่อสีต้องตรงโค้ด
        $this->send('recolor', $g, ['color_hex' => '#0000FF', 'color_name' => 'เหลือง'])->assertUnprocessable()->assertJsonValidationErrors('color_name');
        $this->send('recolor', $g, ['color_hex' => '#0000FF', 'color_name' => 'มรกต'])->assertUnprocessable()->assertJsonValidationErrors('color_name');
        $this->send('recolor', $g, ['color_hex' => '#0000FF', 'color_name' => 'น้ำเงินเข้ม'])->assertCreated();

        $this->assertDatabaseCount('groups', 1);
    }

    public function test_register_rejects_duplicate_names_and_family_gang_word(): void
    {
        $this->council();
        $this->group(['name' => 'Wolves']);

        $this->send('register', null, $this->registerPayload(['name' => 'wolves']))->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->send('register', null, $this->registerPayload(['type' => 'family', 'name' => 'Big Gang', 'declared_members' => 8]))->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_rename_validation_requires_a_real_png_logo(): void
    {
        $this->council();
        $g = $this->group();

        $this->sendForm('rename', $g, ['name' => 'NewName', 'short_name' => 'NN'])->assertUnprocessable()->assertJsonValidationErrors('logo');
        $this->sendForm('rename', $g, ['name' => 'NewName', 'short_name' => 'NN', 'logo' => UploadedFile::fake()->createWithContent('logo.png', 'not a png')])
            ->assertUnprocessable()->assertJsonValidationErrors('logo');
        $this->sendForm('rename', $g, ['name' => 'ชื่อไทย', 'short_name' => 'NN', 'logo' => $this->png()])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->sendForm('rename', $g, ['name' => 'Boss', 'short_name' => 'toolong', 'logo' => $this->png()])->assertUnprocessable()->assertJsonValidationErrors('short_name');
        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_other_kinds_validate_required_fields_and_group(): void
    {
        $this->council();
        $g = $this->group();

        $this->send('recolor', $g, ['color_hex' => 'nope', 'color_name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('color_hex');
        $this->send('change_leader', $g, ['name' => 'X', 'discord_id' => 'abc', 'phone' => '555'])->assertUnprocessable()->assertJsonValidationErrors('discord_id');
        $this->send('release_slot', $g, ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('member_names');
        $this->send('release_free', $g, [])->assertUnprocessable()->assertJsonValidationErrors('member_names');
        $this->send('release_slot', $g, ['member_name' => 'X', 'slot' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('slot');
        $this->send('dissolve', $g, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->send('recolor', null, ['color_hex' => '#00FF00', 'color_name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('group_id');
        $this->postJson(self::URL, ['kind' => 'nope', 'payload' => []])->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    // ───────── ขั้นอนุมัติ ─────────

    public function test_approval_required_transaction_waits_and_does_not_touch_the_group(): void
    {
        $this->council();
        $g = $this->group(['color_hex' => '#111111', 'color_name' => 'ดำ']);

        $r = $this->send('recolor', $g, ['color_hex' => '#00FF00', 'color_name' => 'เขียว'], ['approval_required' => true])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.approval_required', true)->assertJsonPath('data.reviewed_by', null);

        $this->assertSame('#111111', $g->fresh()->color_hex);
        $this->assertNotNull($r->json('data.number'));
    }

    public function test_another_council_member_approves_and_the_effect_is_applied(): void
    {
        $submitter = $this->council();
        $g = $this->group(['color_hex' => '#111111', 'color_name' => 'ดำ']);
        $id = $this->send('recolor', $g, ['color_hex' => '#00FF00', 'color_name' => 'เขียว'], ['approval_required' => true])->json('data.id');

        $reviewer = $this->council(Role::Senior);
        $this->postJson(self::URL."/{$id}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.reviewed_by.id', $reviewer->id);

        $this->assertSame('#00FF00', $g->fresh()->color_hex);
        $this->assertNotNull(Transaction::find($id)->reviewed_at);
        $this->assertNotSame($submitter->id, Transaction::find($id)->reviewed_by);
    }

    public function test_cannot_approve_your_own_transaction_and_reviewer_must_be_on_duty(): void
    {
        $me = $this->council();
        $g = $this->group();
        $id = $this->send('dissolve', $g, ['reason' => 'x'], ['approval_required' => true])->json('data.id');

        $this->postJson(self::URL."/{$id}/approve")->assertForbidden();
        $this->postJson(self::URL."/{$id}/reject", ['reason' => 'x'])->assertForbidden();
        $this->assertSame('pending', Transaction::find($id)->status->value);

        $this->council(Role::Member, onDuty: false);
        $this->postJson(self::URL."/{$id}/approve")->assertForbidden()->assertJsonPath('code', 'off_duty');
        $this->assertNull($g->fresh()->deleted_at);
        $this->assertNotNull($me);
    }

    public function test_approving_twice_returns_409_and_applies_only_once(): void
    {
        $this->council();
        $g = $this->group();
        $this->member($g, 'Sam');
        $id = $this->send('release_free', $g, ['member_name' => 'Sam', 'reason' => 'x'], ['approval_required' => true])->json('data.id');

        $this->council(Role::Senior);
        $this->postJson(self::URL."/{$id}/approve")->assertOk();
        $this->postJson(self::URL."/{$id}/approve")->assertStatus(409);
        $this->postJson(self::URL."/{$id}/reject", ['reason' => 'x'])->assertStatus(409);
        $this->postJson(self::URL."/{$id}/cancel")->assertStatus(409);
        $this->assertSame('approved', Transaction::find($id)->status->value);
    }

    public function test_reject_requires_a_reason_and_leaves_the_group_untouched(): void
    {
        $this->council();
        $g = $this->group();
        $id = $this->send('dissolve', $g, ['reason' => 'x'], ['approval_required' => true])->json('data.id');

        $this->council(Role::Senior);
        $this->postJson(self::URL."/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(self::URL."/{$id}/reject", ['reason' => 'ข้อมูลไม่ครบ'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.review_reason', 'ข้อมูลไม่ครบ');

        $this->assertNull($g->fresh()->deleted_at);
        $this->postJson(self::URL."/{$id}/approve")->assertStatus(409);
    }

    public function test_cancel_is_for_the_submitter_or_vice_president_up_and_only_while_pending(): void
    {
        $submitter = $this->council();
        $g = $this->group();
        $make = fn () => $this->send('dissolve', $g, ['reason' => 'x'], ['approval_required' => true])->json('data.id');

        // ผู้ส่งยกเลิกเองได้
        $a = $make();
        $this->postJson(self::URL."/{$a}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        // สภาคนอื่น (ไม่ใช่รองประธาน) ยกเลิกไม่ได้
        $b = $make();
        $this->council(Role::Senior);
        $this->postJson(self::URL."/{$b}/cancel")->assertForbidden();

        // รองประธานยกเลิกได้
        $this->council(Role::VicePresident);
        $this->postJson(self::URL."/{$b}/cancel")->assertOk();

        $this->assertNull($g->fresh()->deleted_at);
        $this->assertNotNull($submitter);
    }

    public function test_rejected_or_cancelled_rename_discards_the_uploaded_logo_and_changes_nothing(): void
    {
        $this->council();
        $g = $this->group(['name' => 'OldName']);

        $a = $this->sendForm('rename', $g, ['name' => 'NameA', 'short_name' => 'NA', 'logo' => $this->png()], ['approval_required' => '1'])->assertCreated()->json('data.id');
        $b = $this->sendForm('rename', $g, ['name' => 'NameB', 'short_name' => 'NB', 'logo' => $this->png()], ['approval_required' => '1'])->assertCreated()->json('data.id');
        $this->assertCount(2, Storage::disk('local')->allFiles());

        $this->postJson(self::URL."/{$a}/cancel")->assertOk();
        $this->council(Role::Senior);
        $this->postJson(self::URL."/{$b}/reject", ['reason' => 'โลโก้ไม่ชัด'])->assertOk();

        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->assertEmpty(Storage::disk('public')->allFiles());
        $this->assertSame('OldName', $g->fresh()->name);
    }

    public function test_approving_a_pending_register_creates_the_group_only_then(): void
    {
        $this->council();
        $id = $this->send('register', null, $this->registerPayload(), ['approval_required' => true])->assertCreated()->json('data.id');
        $this->assertDatabaseCount('groups', 0);
        $this->assertNull(Transaction::find($id)->group_id);

        $this->council(Role::Secretary);
        $this->postJson(self::URL."/{$id}/approve")->assertOk()->assertJsonPath('data.group.name', 'Wolves');

        $this->assertDatabaseCount('groups', 1);
        $this->assertSame(Group::first()->id, Transaction::find($id)->group_id);
    }

    public function test_approval_rechecks_the_rules_and_stays_pending_when_they_no_longer_hold(): void
    {
        $this->council();
        $g = $this->group();
        $m = $this->member($g, 'Sam');
        $id = $this->send('release_slot', $g, ['member_name' => 'Sam', 'reason' => 'x', 'slot' => 'main'], ['approval_required' => true])->json('data.id');

        $g->update(['slots_locked' => true]); // ระหว่างรอ มีการล็อกสล็อต
        $this->council(Role::Senior);
        $this->postJson(self::URL."/{$id}/approve")->assertStatus(409);

        $this->assertSame('pending', Transaction::find($id)->status->value);
        $this->assertNull($m->fresh()->left_at);

        $g->update(['slots_locked' => false]);
        $g->delete(); // กลุ่มถูกยุบไปก่อน
        $this->postJson(self::URL."/{$id}/approve")->assertUnprocessable()->assertJsonValidationErrors('group_id');
        $this->assertSame('pending', Transaction::find($id)->status->value);
    }

    // ───────── log ก่อน-หลัง ─────────

    public function test_applying_a_transaction_logs_the_group_before_and_after(): void
    {
        $user = $this->council();
        $g = $this->group(['color_hex' => '#111111', 'color_name' => 'ดำ']);

        $number = $this->send('recolor', $g, ['color_hex' => '#00FF00', 'color_name' => 'เขียว'])->json('data.number');

        $log = Activity::where('log_name', 'transactions')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $log->causer_id);
        $this->assertSame(Transaction::class, $log->subject_type);
        $this->assertStringContainsString($number, $log->description);
        $this->assertSame('#111111', $log->properties['before']['color_hex']);
        $this->assertSame('#00FF00', $log->properties['after']['color_hex']);
        $this->assertSame($g->id, $log->properties['group_id']);
    }

    public function test_pending_does_not_log_a_group_change_until_approved(): void
    {
        $this->council();
        $g = $this->group();
        $id = $this->send('recolor', $g, ['color_hex' => '#00FF00', 'color_name' => 'เขียว'], ['approval_required' => true])->json('data.id');
        $this->assertSame(0, Activity::where('log_name', 'transactions')->count());

        $this->council(Role::Senior);
        $this->postJson(self::URL."/{$id}/approve")->assertOk();
        $this->assertSame(1, Activity::where('log_name', 'transactions')->count());
    }

    public function test_register_dissolve_log_have_null_before_and_dissolved_after(): void
    {
        $this->council();
        $this->send('register', null, $this->registerPayload())->assertCreated();
        $reg = Activity::where('log_name', 'transactions')->latest('id')->firstOrFail();
        $this->assertNull($reg->properties['before']);
        $this->assertSame('Wolves', $reg->properties['after']['name']);

        $this->send('dissolve', Group::first(), ['reason' => 'x'])->assertCreated();
        $dis = Activity::where('log_name', 'transactions')->latest('id')->firstOrFail();
        $this->assertSame('Wolves', $dis->properties['before']['name']);
        $this->assertTrue($dis->properties['after']['dissolved']);
    }

    // ───────── รายการ ─────────

    public function test_transaction_resource_exposes_before_and_after_for_applied_transactions(): void
    {
        $this->council();
        $g = $this->group(['color_hex' => '#111111', 'color_name' => 'ดำ']);
        $id = $this->send('recolor', $g, ['color_hex' => '#00FF00', 'color_name' => 'เขียว'])->json('data.id');
        $pending = $this->send('recolor', $g, ['color_hex' => '#0000FF', 'color_name' => 'น้ำเงิน'], ['approval_required' => true])->json('data.id');

        $this->getJson(self::URL."/{$id}")->assertOk()
            ->assertJsonPath('data.before.color_hex', '#111111')->assertJsonPath('data.after.color_hex', '#00FF00');
        $this->getJson(self::URL."/{$pending}")->assertOk()->assertJsonPath('data.before', null)->assertJsonPath('data.after', null);
        $this->getJson(self::URL."?group_id={$g->id}")->assertOk()->assertJsonPath('data.1.after.color_name', 'เขียว');
    }

    public function test_index_filters_by_kind_status_and_group(): void
    {
        $this->council();
        $a = $this->group();
        $b = $this->group();
        $this->send('recolor', $a, ['color_hex' => '#00FF00', 'color_name' => 'เขียว']);
        $this->send('recolor', $b, ['color_hex' => '#0000FF', 'color_name' => 'น้ำเงิน'], ['approval_required' => true]);
        $this->send('dissolve', $b, ['reason' => 'x'], ['approval_required' => true]);

        $this->getJson(self::URL)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson(self::URL.'?kind=dissolve')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'?status=pending')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson(self::URL."?group_id={$a->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'?kind=nope')->assertUnprocessable();

        $id = Transaction::where('kind', 'dissolve')->value('id');
        $this->getJson(self::URL."/{$id}")->assertOk()->assertJsonPath('data.kind_label', 'ยุบ')->assertJsonPath('data.group.id', $b->id);
    }
}
