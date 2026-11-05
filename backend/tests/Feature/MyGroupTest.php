<?php

namespace Tests\Feature;

use App\Enums\MemberPosition;
use App\Enums\Role;
use App\Models\Group;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ส่วนของแก๊ง/ครอบครัว: เวลาเปิดใช้งาน 18:30–03:00 · ยื่นเรื่องให้สภาอนุมัติ · สิทธิ์ */
class MyGroupTest extends TestCase
{
    use RefreshDatabase;

    private const DISCORD = '123456789012345678';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-11-05 20:00:00', 'Asia/Bangkok')); // ช่วงเปิดใช้งาน
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gangUser(Role $role = Role::Gang): User
    {
        $u = User::factory()->role($role)->loggedIn()->create(['discord_id' => self::DISCORD]);
        Sanctum::actingAs($u);

        return $this->user = $u;
    }

    private function ownGroup(string $type = 'gang'): Group
    {
        $g = Group::factory()->state(['type' => $type, 'registered_at' => now()->subDays(100)])->create();
        $g->members()->create(['character_name' => 'Boss One', 'position' => MemberPosition::Leader, 'discord_id' => self::DISCORD, 'joined_at' => now()->subDays(50)]);
        $this->user->update(['group_id' => $g->id]);
        Sanctum::actingAs($this->user->fresh());

        return $g;
    }

    /** @return array<string, mixed> */
    private function registerPayload(array $over = []): array
    {
        return array_merge([
            'name' => 'Night Owls', 'short_name' => 'NOW', 'color_hex' => '#FF0000', 'color_name' => 'แดง',
            'leader' => ['name' => 'Boss One', 'discord_id' => self::DISCORD, 'phone' => '0812345678'],
            'co_leaders' => [],
        ], $over);
    }

    public function test_portal_is_open_only_between_1830_and_0300_thai_time(): void
    {
        $this->gangUser();
        foreach (['18:29' => false, '18:30' => true, '23:59' => true, '00:30' => true, '02:59' => true, '03:00' => false, '12:00' => false] as $time => $open) {
            // 00:xx ของเช้าวันถัดไปอยู่ในช่วงเดียวกับคืนก่อนหน้า
            Carbon::setTestNow(Carbon::parse("2026-11-06 {$time}:00", 'Asia/Bangkok'));
            $this->getJson('/api/my-group')->assertOk()->assertJsonPath('data.portal.open', $open);
        }
    }

    public function test_requests_are_blocked_outside_hours(): void
    {
        $this->gangUser();
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00', 'Asia/Bangkok'));

        $this->postJson('/api/my-group/requests', ['kind' => 'register', 'payload' => $this->registerPayload()])->assertForbidden();
        $this->getJson('/api/my-group')->assertOk()->assertJsonMissingPath('data.group');
    }

    public function test_only_gang_and_family_use_the_portal(): void
    {
        $u = User::factory()->role(Role::Member)->loggedIn()->create();
        Sanctum::actingAs($u);
        $this->getJson('/api/my-group')->assertForbidden();
    }

    public function test_register_request_waits_for_council_and_creates_nothing_yet(): void
    {
        $this->gangUser();

        $res = $this->postJson('/api/my-group/requests', ['kind' => 'register', 'payload' => $this->registerPayload()])->assertCreated();
        $res->assertJsonPath('data.status', 'pending')->assertJsonPath('data.requested_role', 'gang');
        $this->assertSame(0, Group::count());

        // ยื่นซ้ำไม่ได้ขณะยังรออนุมัติ
        $this->postJson('/api/my-group/requests', ['kind' => 'register', 'payload' => $this->registerPayload(['name' => 'Other Name', 'short_name' => 'OTH'])])->assertUnprocessable();
    }

    public function test_register_needs_the_submitter_in_the_request_and_forces_own_type(): void
    {
        $this->gangUser(Role::Family);

        $bad = $this->registerPayload(['leader' => ['name' => 'Boss One', 'discord_id' => '999999999999999999', 'phone' => '0812345678']]);
        $this->postJson('/api/my-group/requests', ['kind' => 'register', 'payload' => $bad])->assertUnprocessable();

        $res = $this->postJson('/api/my-group/requests', ['kind' => 'register', 'payload' => $this->registerPayload(['type' => 'gang'])])->assertCreated();
        $this->assertSame('family', Transaction::first()->payload['type']); // ประเภทตามบทบาทเสมอ
    }

    public function test_council_can_approve_a_gang_request(): void
    {
        $gang = $this->gangUser();
        $id = $this->postJson('/api/my-group/requests', ['kind' => 'register', 'payload' => $this->registerPayload()])->assertCreated()->json('data.id');

        $this->assertSame($gang->id, Transaction::find($id)->requested_by);
        $council = User::factory()->role(Role::Member)->loggedIn()->create();
        $this->assertSame(Role::Member, $council->role);

        app(\App\Services\TransactionService::class)->approve(Transaction::find($id), $council);
        $this->assertDatabaseHas('groups', ['name' => 'Night Owls']);

        // ได้กลุ่มแล้ว ผูกสังกัดให้ผู้ยื่นอัตโนมัติ หน้าส่วนตัวเห็นกลุ่ม
        $this->assertSame(Group::first()->id, $gang->fresh()->group_id);
        Sanctum::actingAs($gang->fresh());
        $this->getJson('/api/my-group')->assertOk()->assertJsonPath('data.group.name', 'Night Owls');
    }

    public function test_requests_list_shows_only_own_group_and_own_group_role_requests(): void
    {
        $user = $this->gangUser();
        $other = Group::factory()->state(['type' => 'gang', 'registered_at' => now()->subDays(100)])->create();

        // ธุรกรรมที่บัญชีนี้เคยทำสมัยเป็นสภา และธุรกรรมของกลุ่มอื่น ต้องไม่โผล่
        Transaction::create(['number' => 'WC-OLD-1', 'group_id' => $other->id, 'kind' => 'remove_protect', 'payload' => [], 'status' => 'approved', 'requested_by' => $user->id, 'requested_role' => 'member', 'approval_required' => false]);
        $this->assertCount(0, $this->getJson('/api/my-group')->assertOk()->json('data.requests'));

        $mine = $this->ownGroup();
        Transaction::create(['number' => 'WC-MINE-1', 'group_id' => $mine->id, 'kind' => 'remove_protect', 'payload' => [], 'status' => 'approved', 'requested_by' => $user->id, 'requested_role' => 'member', 'approval_required' => false]);
        $numbers = collect($this->getJson('/api/my-group')->json('data.requests'))->pluck('number')->all();
        $this->assertSame(['WC-MINE-1'], $numbers); // ของกลุ่มที่สังกัดเห็นได้ ของกลุ่มอื่นไม่เห็น
    }

    private function openRound(array $over = []): \App\Models\ActivityReportRound
    {
        $creator = User::factory()->role(Role::Member)->create();

        return \App\Models\ActivityReportRound::create($over + ['title' => 'รอบสัปดาห์นี้', 'opens_at' => now()->subHour(), 'closes_at' => now()->addDay(), 'created_by' => $creator->id]);
    }

    /** @return array<string, mixed> */
    private function activityPayload(int $roundId, array $over = []): array
    {
        return array_merge([
            'round_id' => $roundId,
            'leader' => ['name' => 'Boss One', 'phone' => '0811111111'],
            'co_leaders' => [],
            'member_name' => 'Some Member',
            'photo' => UploadedFile::fake()->createWithContent('crew.png', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR"),
            'confirm_characters' => '1',
        ], $over);
    }

    private function postActivity(array $payload)
    {
        return $this->post('/api/my-group/requests', ['kind' => 'activity_report', 'payload' => $payload], ['Accept' => 'application/json']);
    }

    public function test_activity_report_only_while_a_round_is_open_and_once_per_round(): void
    {
        $this->gangUser();
        $this->ownGroup();

        // ไม่มีรอบเปิด → ยื่นไม่ได้ (รอบที่หมดเวลาแล้วไม่นับ)
        $past = $this->openRound(['opens_at' => now()->subDays(3), 'closes_at' => now()->subDay()]);
        $this->postActivity($this->activityPayload($past->id))->assertUnprocessable()->assertJsonValidationErrors('round_id');

        $round = $this->openRound();
        $info = $this->getJson('/api/my-group')->assertOk()->json('data.activity');
        $this->assertSame($round->id, $info['open']['id']);
        $this->assertFalse($info['reported']);

        $this->postActivity($this->activityPayload($round->id))->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.has_photo', true);
        $this->assertTrue($this->getJson('/api/my-group')->json('data.activity.reported'));
        $this->postActivity($this->activityPayload($round->id))->assertUnprocessable(); // รอบเดียวยื่นได้ครั้งเดียว
    }

    public function test_activity_report_needs_leader_matching_co_leader_count_member_photo_and_confirmation(): void
    {
        $this->gangUser();
        $g = $this->ownGroup();
        $g->members()->create(['character_name' => 'Deputy A', 'position' => MemberPosition::CoLeader, 'joined_at' => now()->subDays(40)]);
        $g->members()->create(['character_name' => 'Deputy B', 'position' => MemberPosition::CoLeader, 'joined_at' => now()->subDays(40)]);
        $round = $this->openRound();

        // มีรอง 2 คน ต้องกรอกทั้งคู่
        $this->postActivity($this->activityPayload($round->id))->assertUnprocessable()->assertJsonValidationErrors('co_leaders');
        $one = $this->activityPayload($round->id, ['co_leaders' => [['name' => 'Deputy A', 'phone' => '0822222222']]]);
        $this->postActivity($one)->assertUnprocessable()->assertJsonValidationErrors('co_leaders');

        $co = [['name' => 'Deputy A', 'phone' => '0822222222'], ['name' => 'Deputy B', 'phone' => '0833333333']];
        $this->postActivity($this->activityPayload($round->id, ['co_leaders' => $co, 'member_name' => '']))->assertUnprocessable()->assertJsonValidationErrors('member_name');
        $this->postActivity($this->activityPayload($round->id, ['co_leaders' => $co, 'confirm_characters' => '0']))->assertUnprocessable()->assertJsonValidationErrors('confirm_characters');
        $this->postActivity($this->activityPayload($round->id, ['co_leaders' => $co, 'photo' => UploadedFile::fake()->createWithContent('x.zip', "PK\x03\x04")]))->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->postActivity($this->activityPayload($round->id, ['co_leaders' => $co, 'leader' => ['name' => 'Boss One', 'phone' => 'abc']]))->assertUnprocessable();

        $this->postActivity($this->activityPayload($round->id, ['co_leaders' => $co]))->assertCreated();
    }

    public function test_council_approval_records_report_reactivates_group_even_after_round_closed(): void
    {
        $this->gangUser();
        $g = $this->ownGroup();
        $g->update(['status' => 'inactive', 'status_reason' => 'ขาดการเคลื่อนไหว']);
        $round = $this->openRound();

        $id = $this->postActivity($this->activityPayload($round->id))->assertCreated()->json('data.id');
        $this->assertSame('inactive', $g->fresh()->status->value);

        $round->update(['closed_early_at' => now()]); // สภาปิดรอบก่อนอนุมัติ ก็อนุมัติได้
        $council = User::factory()->role(Role::Member)->loggedIn()->create();
        app(\App\Services\TransactionService::class)->approve(Transaction::find($id), $council);

        $this->assertSame('normal', $g->fresh()->status->value);
        $rec = $g->records()->where('kind', 'report')->first();
        $this->assertNotNull($rec);
        $this->assertSame('Some Member', $rec->data['member_name']);
    }

    public function test_council_opens_and_closes_rounds_and_views_the_photo(): void
    {
        $this->gangUser();
        $this->ownGroup();
        $round = $this->openRound();
        $id = $this->postActivity($this->activityPayload($round->id))->assertCreated()->json('data.id');

        // แก๊งจัดการรอบ/ดูรูปไม่ได้
        $this->getJson('/api/council/activity-rounds')->assertForbidden();
        $this->getJson("/api/council/transactions/{$id}/files/photo")->assertForbidden();

        $council = User::factory()->role(Role::Member)->loggedIn()->create();
        \App\Models\DutyShift::create(['user_id' => $council->id, 'started_at' => now()->subMinutes(20), 'last_seen_at' => now()]);
        Sanctum::actingAs($council);

        $this->getJson('/api/council/activity-rounds')->assertOk()->assertJsonPath('data.0.phase', 'open')->assertJsonPath('data.0.reports_count', 1);
        $this->getJson("/api/council/transactions/{$id}/files/photo")->assertOk();

        // เปิดรอบใหม่ทับช่วงเดิมไม่ได้ · ช่วงเวลาผิดไม่ได้
        $this->postJson('/api/council/activity-rounds', ['title' => 'ทับ', 'opens_at' => now()->toIso8601String(), 'closes_at' => now()->addHours(5)->toIso8601String()])->assertUnprocessable();
        $this->postJson('/api/council/activity-rounds', ['title' => 'ผิด', 'opens_at' => now()->addDays(3)->toIso8601String(), 'closes_at' => now()->addDays(2)->toIso8601String()])->assertUnprocessable();
        $this->postJson('/api/council/activity-rounds', ['title' => 'รอบหน้า', 'opens_at' => now()->addDays(3)->toIso8601String(), 'closes_at' => now()->addDays(4)->toIso8601String()])->assertCreated();

        $this->postJson("/api/council/activity-rounds/{$round->id}/close")->assertOk();
        $this->assertNull(\App\Models\ActivityReportRound::open()->first());
        $this->postJson("/api/council/activity-rounds/{$round->id}/close")->assertStatus(409);
    }

    public function test_group_users_cannot_submit_council_only_kinds(): void
    {
        $this->gangUser();
        $this->ownGroup();

        $this->postJson('/api/my-group/requests', ['kind' => 'dissolve', 'payload' => ['reason' => 'x']])->assertUnprocessable();
        $this->postJson('/api/council/transactions', ['kind' => 'rename'])->assertForbidden();
    }

    public function test_change_co_leader_request_and_approval(): void
    {
        $gang = $this->gangUser();
        $g = $this->ownGroup();
        $g->members()->create(['character_name' => 'Old Deputy', 'position' => MemberPosition::CoLeader, 'joined_at' => now()->subDays(40)]);

        $payload = ['position' => 'co_leader', 'replace_name' => 'Old Deputy', 'name' => 'New Deputy', 'phone' => '0899999999'];
        $res = $this->postJson('/api/my-group/requests', ['kind' => 'change_leader', 'payload' => $payload])->assertCreated();
        $res->assertJsonPath('data.status', 'pending');
        $this->assertStringContainsString('Old Deputy → New Deputy', $res->json('data.summary'));

        $council = User::factory()->role(Role::Member)->loggedIn()->create();
        app(\App\Services\TransactionService::class)->approve(Transaction::find($res->json('data.id')), $council);

        $this->assertSame(MemberPosition::Member, $g->members()->where('character_name', 'Old Deputy')->first()->position);
        $this->assertSame(MemberPosition::CoLeader, $g->members()->where('character_name', 'New Deputy')->first()->position);
    }

    public function test_change_leader_to_someone_already_leader_is_rejected_when_submitting(): void
    {
        $this->gangUser();
        $this->ownGroup(); // Leader = Boss One

        $same = ['name' => 'boss one', 'discord_id' => '222222222222222222', 'phone' => '0811111111']; // ชื่อซ้ำกับหัวหน้าเดิม (ต่างตัวพิมพ์)
        $this->postJson('/api/my-group/requests', ['kind' => 'change_leader', 'payload' => $same])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame(0, Transaction::count());
    }

    public function test_change_leader_request_and_duplicate_pending_blocked(): void
    {
        $this->gangUser();
        $this->ownGroup();

        $payload = ['name' => 'Next Boss', 'discord_id' => '222222222222222222', 'phone' => '0811111111'];
        $this->postJson('/api/my-group/requests', ['kind' => 'change_leader', 'payload' => $payload])->assertCreated();
        $this->postJson('/api/my-group/requests', ['kind' => 'change_leader', 'payload' => $payload])->assertUnprocessable();
    }

    public function test_rename_request_with_logo_and_quote_and_cancel(): void
    {
        $this->gangUser();
        $g = $this->ownGroup();

        $res = $this->post('/api/my-group/requests', ['kind' => 'rename', 'payload' => ['name' => 'Brand New', 'short_name' => 'BNW', 'logo_url' => 'https://images.wavevycity.com/logos/test.png']], ['Accept' => 'application/json'])->assertCreated();
        $res->assertJsonPath('data.status', 'pending');
        $this->assertSame('ยังไม่เปลี่ยน', $g->fresh()->name === 'Brand New' ? 'เปลี่ยนแล้ว' : 'ยังไม่เปลี่ยน');

        $this->postJson('/api/my-group/requests/'.$res->json('data.id').'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_quote_returns_outfit_fee_before_submitting(): void
    {
        $this->gangUser();
        $this->ownGroup();

        $res = $this->postJson('/api/my-group/quote', ['kind' => 'outfit', 'payload' => ['add' => ['suit' => 2, 'mod1' => 1]]])->assertOk();
        $this->assertSame(2 * 500000 + 1000000, $res->json('data.total'));
        $this->assertSame(0, Transaction::count());
    }
}
