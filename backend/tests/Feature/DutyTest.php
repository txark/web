<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DutyShift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** เข้า/ออกเวร: สภาต้องเข้าเวรก่อนใช้ส่วนงานสภา (หน้าแรก/กฎ/ออกจากระบบใช้ได้เสมอ) */
class DutyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'council.duty.enforced' => true,
            'council.duty.exempt_roles' => [],
            'council.duty.max_hours' => 12,
            'council.duty.short_shift_hours' => 3,
            'council.duty.heartbeat_grace_minutes' => 10,
        ]);
    }

    private function actAs(Role $role): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        Sanctum::actingAs($u);

        return $u;
    }

    /** ทุกจุดที่เป็นงานสภา */
    private function councilEndpoints(): array
    {
        return ['/api/council/groups', '/api/council/alerts', '/api/council/announcements'];
    }

    /** เวรที่เริ่มมาแล้ว N นาที และยังมีสัญญาณล่าสุดเมื่อกี้ */
    private function shiftStartedMinutesAgo(User $user, int $minutes): DutyShift
    {
        return DutyShift::create(['user_id' => $user->id, 'started_at' => now()->subMinutes($minutes), 'last_seen_at' => now()]);
    }

    // ───────── ต้องเข้าเวรก่อนใช้งานสภา ─────────

    public function test_council_is_blocked_from_council_work_until_on_duty(): void
    {
        $this->actAs(Role::Member);

        foreach ($this->councilEndpoints() as $url) {
            $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'off_duty');
        }
        $this->postJson('/api/council/groups', ['type' => 'gang', 'name' => 'Nope'])->assertForbidden()->assertJsonPath('code', 'off_duty');

        $this->postJson('/api/duty/start')->assertOk()->assertJsonPath('data.on_duty', true);

        foreach ($this->councilEndpoints() as $url) {
            $this->getJson($url)->assertOk();
        }
    }

    public function test_user_management_and_logs_also_require_duty(): void
    {
        $this->actAs(Role::VicePresident);

        $this->getJson('/api/users')->assertForbidden()->assertJsonPath('code', 'off_duty');
        $this->getJson('/api/logs')->assertForbidden()->assertJsonPath('code', 'off_duty');

        $this->postJson('/api/duty/start')->assertOk();
        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/logs')->assertOk();
    }

    public function test_always_available_endpoints_do_not_need_duty(): void
    {
        $this->actAs(Role::Member);

        $this->getJson('/api/user')->assertOk();
        $this->getJson('/api/public/rules')->assertOk();
        $this->getJson('/api/public/groups')->assertOk();
        $this->getJson('/api/duty')->assertOk()->assertJsonPath('data.on_duty', false);
        $this->postJson('/api/duty/heartbeat')->assertOk()->assertJsonPath('data.on_duty', false);
        $this->postJson('/api/logout')->assertSuccessful();
    }

    public function test_start_is_idempotent_and_keeps_the_original_start_time(): void
    {
        $user = $this->actAs(Role::Member);

        $first = $this->postJson('/api/duty/start')->json('data.started_at');
        $this->travel(2)->minutes();
        $second = $this->postJson('/api/duty/start')->assertOk()->json('data.started_at');

        $this->assertSame($first, $second);
        $this->assertSame(1, DutyShift::where('user_id', $user->id)->count());
    }

    public function test_status_reports_start_time_server_time_and_thresholds(): void
    {
        $this->actAs(Role::Member);
        $this->postJson('/api/duty/start');
        $this->travel(90)->seconds();

        $d = $this->getJson('/api/duty')->assertOk()->json('data');
        $this->assertTrue($d['on_duty']);
        $this->assertSame(90, (int) Carbon::parse($d['started_at'])->diffInSeconds(Carbon::parse($d['server_time'])));
        $this->assertTrue($d['required']);
        $this->assertSame(3, $d['short_shift_hours']);
    }

    public function test_shifts_are_per_user(): void
    {
        $a = $this->actAs(Role::Member);
        $this->postJson('/api/duty/start');

        $this->actAs(Role::Member); // อีกคน ยังไม่เข้าเวร
        $this->getJson('/api/council/groups')->assertForbidden();
        $this->getJson('/api/duty')->assertJsonPath('data.on_duty', false);
        $this->assertSame(1, DutyShift::whereNull('ended_at')->where('user_id', $a->id)->count());
    }

    public function test_gang_and_family_cannot_use_duty_endpoints(): void
    {
        foreach ([Role::Gang, Role::Family] as $role) {
            $this->actAs($role);
            $this->postJson('/api/duty/start')->assertForbidden();
            $this->postJson('/api/duty/heartbeat')->assertForbidden();
            $this->getJson('/api/duty')->assertForbidden();
        }
        $this->assertSame(0, DutyShift::count());
    }

    public function test_exempt_roles_and_disabled_enforcement(): void
    {
        config(['council.duty.exempt_roles' => ['secretary']]);
        $this->actAs(Role::Secretary);
        $this->getJson('/api/council/groups')->assertOk();
        $this->getJson('/api/duty')->assertOk()->assertJsonPath('data.required', false);

        $this->actAs(Role::Member);
        $this->getJson('/api/council/groups')->assertForbidden();

        config(['council.duty.enforced' => false]);
        $this->getJson('/api/council/groups')->assertOk();
    }

    public function test_double_open_shift_is_impossible_at_the_database_level(): void
    {
        $user = User::factory()->role(Role::Member)->create();
        DutyShift::create(['user_id' => $user->id, 'started_at' => now()]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DutyShift::create(['user_id' => $user->id, 'started_at' => now()]);
    }

    // ───────── ออกเวร: ก่อนครบ 3 ชั่วโมงต้องมีหมายเหตุ ─────────

    public function test_ending_before_three_hours_requires_a_note(): void
    {
        $user = $this->actAs(Role::Member);
        $this->postJson('/api/duty/start');
        $this->travel(2)->minutes();

        $this->postJson('/api/duty/end')->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson('/api/duty/end', ['note' => '   '])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->getJson('/api/council/groups')->assertOk(); // ปฏิเสธแล้วยังอยู่ในเวร

        $this->postJson('/api/duty/end', ['note' => 'ติดธุระด่วน'])->assertOk()->assertJsonPath('data.on_duty', false);

        $shift = DutyShift::where('user_id', $user->id)->first();
        $this->assertSame('manual', $shift->ended_reason);
        $this->assertSame('ติดธุระด่วน', $shift->note);
        $this->getJson('/api/council/groups')->assertForbidden();
    }

    public function test_ending_after_three_hours_needs_no_note_but_accepts_one(): void
    {
        $a = $this->actAs(Role::Member);
        $this->shiftStartedMinutesAgo($a, 3 * 60 + 5);
        $this->postJson('/api/duty/end')->assertOk()->assertJsonPath('data.on_duty', false);
        $this->assertNull(DutyShift::first()->note);

        $b = $this->actAs(Role::Member);
        $this->shiftStartedMinutesAgo($b, 4 * 60);
        $this->postJson('/api/duty/end', ['note' => 'ส่งเวรให้คนต่อไป'])->assertOk();
        $this->assertSame('ส่งเวรให้คนต่อไป', DutyShift::where('user_id', $b->id)->first()->note);
    }

    public function test_exactly_three_hours_does_not_need_a_note_and_just_under_does(): void
    {
        $a = $this->actAs(Role::Member);
        $this->shiftStartedMinutesAgo($a, 179);
        $this->postJson('/api/duty/end')->assertUnprocessable();

        $this->shiftStartedMinutesAgo($b = $this->actAs(Role::Member), 180);
        $this->postJson('/api/duty/end')->assertOk();
    }

    public function test_note_is_limited_in_length(): void
    {
        $this->actAs(Role::Member);
        $this->postJson('/api/duty/start');
        $this->postJson('/api/duty/end', ['note' => str_repeat('ก', 501)])->assertUnprocessable()->assertJsonValidationErrors('note');
    }

    public function test_end_is_written_to_the_activity_log_with_duration_and_note(): void
    {
        $user = $this->actAs(Role::Member);
        $this->shiftStartedMinutesAgo($user, 3 * 60 + 5);
        $this->postJson('/api/duty/end', ['note' => 'ส่งเวร']);

        $end = Activity::where('log_name', 'duty')->where('event', 'end')->first();
        $this->assertSame($user->id, $end->causer_id);
        $this->assertStringContainsString('03:05:00', $end->description);
        $this->assertStringContainsString('ส่งเวร', $end->description);
        $this->assertSame('เข้าเวร', Activity::where('log_name', 'duty')->where('event', 'start')->value('description') ?? 'เข้าเวร');
    }

    // ───────── ปิดเว็บ/เบราว์เซอร์/คอม → บังคับออกเวรโดยระบบ ─────────

    public function test_heartbeat_keeps_the_shift_alive_beyond_the_grace_period(): void
    {
        $user = $this->actAs(Role::Member);
        $this->postJson('/api/duty/start');

        foreach (range(1, 4) as $_) {
            $this->travel(8)->minutes();                          // ทุกรอบเงียบไม่เกิน 10 นาที
            $this->postJson('/api/duty/heartbeat')->assertJsonPath('data.on_duty', true);
        }

        $this->getJson('/api/council/groups')->assertOk();
        $this->assertNull(DutyShift::where('user_id', $user->id)->first()->ended_at);
    }

    public function test_silence_beyond_grace_forces_the_shift_to_end_with_the_system_note(): void
    {
        $user = $this->actAs(Role::Member);
        $this->postJson('/api/duty/start');
        $this->travel(2)->minutes();
        $this->postJson('/api/duty/heartbeat');                   // สัญญาณสุดท้าย ณ นาทีที่ 2
        $lastSeen = DutyShift::first()->fresh()->last_seen_at;

        $this->travel(15)->minutes();                             // แล้วปิดเว็บ/เบราว์เซอร์/คอมไป

        $this->getJson('/api/council/groups')->assertForbidden()->assertJsonPath('code', 'off_duty');

        $shift = DutyShift::first();
        $this->assertSame('abandoned', $shift->ended_reason);
        $this->assertSame('บังคับออกเวรโดยระบบ', $shift->note);
        $this->assertTrue($shift->ended_at->equalTo($lastSeen), 'นับเวลาถึงสัญญาณสุดท้าย ไม่นับช่วงที่หายไป');

        $log = Activity::where('log_name', 'duty')->where('event', 'system_end')->first();
        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->causer_id);
        $this->assertStringContainsString('บังคับออกเวรโดยระบบ', $log->description);
    }

    public function test_using_council_endpoints_also_counts_as_a_sign_of_life(): void
    {
        $user = $this->actAs(Role::Member);
        $this->postJson('/api/duty/start');

        foreach (range(1, 4) as $_) {
            $this->travel(2)->minutes();
            $this->getJson('/api/council/groups')->assertOk(); // ไม่มี heartbeat แต่ใช้งานอยู่
        }

        $this->assertNull(DutyShift::where('user_id', $user->id)->first()->ended_at);
    }

    public function test_user_who_returns_later_sees_off_duty_and_the_forced_end_notice(): void
    {
        $this->actAs(Role::Member);
        $this->postJson('/api/duty/start');
        $this->travel(15)->minutes();                             // ปิดคอมทิ้งไว้เกิน 10 นาที

        $d = $this->getJson('/api/duty')->assertOk()->json('data');

        $this->assertFalse($d['on_duty']);
        $this->assertSame('บังคับออกเวรโดยระบบ', $d['last_forced_end']['note']);
        $this->assertSame('abandoned', $d['last_forced_end']['reason']);

        $this->postJson('/api/duty/start')->assertOk()->assertJsonPath('data.on_duty', true)->assertJsonPath('data.last_forced_end', null);
    }

    public function test_manual_end_does_not_report_a_forced_end(): void
    {
        $this->actAs(Role::Member);
        $this->postJson('/api/duty/start');
        $this->postJson('/api/duty/end', ['note' => 'ธุระ']);

        $this->getJson('/api/duty')->assertJsonPath('data.last_forced_end', null);
    }

    public function test_scheduled_command_closes_abandoned_shifts_without_any_request(): void
    {
        $stale = User::factory()->role(Role::Member)->create();
        $fresh = User::factory()->role(Role::Member)->create();
        DutyShift::create(['user_id' => $stale->id, 'started_at' => now()->subMinutes(30), 'last_seen_at' => now()->subMinutes(20)]);
        DutyShift::create(['user_id' => $fresh->id, 'started_at' => now()->subMinutes(30), 'last_seen_at' => now()->subSeconds(30)]);

        $this->artisan('duty:close-abandoned')->assertSuccessful();

        $s = DutyShift::where('user_id', $stale->id)->first();
        $this->assertSame('บังคับออกเวรโดยระบบ', $s->note);
        $this->assertSame(20, (int) $s->ended_at->diffInMinutes(now()), 'ออกเวร ณ เวลาสัญญาณสุดท้าย');
        $this->assertNull(DutyShift::where('user_id', $fresh->id)->first()->ended_at, 'คนที่ยังส่งสัญญาณอยู่ไม่ถูกแตะ');
    }

    public function test_a_shift_without_any_heartbeat_is_measured_from_its_start(): void
    {
        $user = $this->actAs(Role::Member);
        DutyShift::create(['user_id' => $user->id, 'started_at' => now()->subMinutes(40)]); // ไม่มี last_seen_at เลย

        $this->getJson('/api/council/groups')->assertForbidden();
        $s = DutyShift::first();
        $this->assertSame('abandoned', $s->ended_reason);
        $this->assertSame(0, (int) $s->started_at->diffInSeconds($s->ended_at), 'ไม่เคยมีสัญญาณ: นับเป็น 0 ไม่ใช่ครึ่งชั่วโมง');
    }

    public function test_shift_open_longer_than_the_maximum_is_closed_even_if_the_tab_stays_open(): void
    {
        $user = $this->actAs(Role::Member);
        DutyShift::create(['user_id' => $user->id, 'started_at' => now()->subHours(13), 'last_seen_at' => now()]);

        $this->getJson('/api/council/groups')->assertForbidden()->assertJsonPath('code', 'off_duty');

        $shift = DutyShift::first();
        $this->assertSame('max_hours', $shift->ended_reason);
        $this->assertSame('บังคับออกเวรโดยระบบ', $shift->note);
        $this->assertSame(12, (int) $shift->started_at->diffInHours($shift->ended_at), 'นับเวรได้ไม่เกิน 12 ชั่วโมง');

        $this->postJson('/api/duty/start')->assertOk()->assertJsonPath('data.on_duty', true);
        $this->assertSame(2, DutyShift::where('user_id', $user->id)->count());
    }
}
