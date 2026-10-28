<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DutyShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** สรุปชั่วโมงเวรของสภาแต่ละคน (รองประธานขึ้นไป) */
class DutyReportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/council/duty-report';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'council.duty.enforced' => false, // เปิดเฉพาะเทสต์ที่ต้องการ
            'council.duty.short_shift_hours' => 3,
            'council.duty.heartbeat_grace_minutes' => 10,
            'council.duty.max_hours' => 12,
            'council.report_timezone' => 'Asia/Bangkok',
        ]);
        $this->travelTo(now()->setDate(2026, 6, 15)->setTime(12, 0)); // 15 มิ.ย. 12:00 UTC = 19:00 เวลาไทย
    }

    private function actAs(Role $role): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        Sanctum::actingAs($u);

        return $u;
    }

    private function shift(User $u, string $startUtc, ?string $endUtc, array $extra = []): DutyShift
    {
        return DutyShift::create($extra + [
            'user_id' => $u->id,
            'started_at' => $startUtc,
            'last_seen_at' => $endUtc ?? now(),
            'ended_at' => $endUtc,
            'ended_reason' => $endUtc ? DutyShift::MANUAL : null,
        ]);
    }

    private function row(array $json, User $u): ?array
    {
        return collect($json['data'])->firstWhere('user.id', $u->id);
    }

    public function test_only_vice_president_up_can_read_the_report(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        foreach ([Role::Gang, Role::Family, Role::Member] as $role) {
            $this->actAs($role);
            $this->getJson(self::URL)->assertForbidden();
        }

        $this->actAs(Role::VicePresident);
        $this->getJson(self::URL)->assertOk();
        $this->actAs(Role::President);
        $this->getJson(self::URL)->assertOk();
    }

    public function test_vice_president_must_be_on_duty_to_read_it_when_enforced(): void
    {
        config(['council.duty.enforced' => true]);
        $vp = $this->actAs(Role::VicePresident);

        $this->getJson(self::URL)->assertForbidden()->assertJsonPath('code', 'off_duty');

        $this->postJson('/api/duty/start');
        $this->getJson(self::URL)->assertOk();
        $this->assertNotNull($vp);
    }

    public function test_totals_counts_and_averages_per_member(): void
    {
        $this->actAs(Role::VicePresident);
        $a = User::factory()->role(Role::Member)->create(['character_name' => 'Alice']);

        $this->shift($a, '2026-06-10 10:00:00', '2026-06-10 13:00:00'); // 3 ชม.
        $this->shift($a, '2026-06-11 10:00:00', '2026-06-11 11:30:00'); // 1.5 ชม.

        $r = $this->getJson(self::URL.'?from=2026-06-01&to=2026-06-15')->assertOk()->json();
        $row = $this->row($r, $a);

        $this->assertSame(4.5 * 3600, (float) $row['total_seconds']);
        $this->assertSame(2, $row['shifts']);
        $this->assertSame((int) (2.25 * 3600), $row['avg_seconds']);
        $this->assertSame('Alice', $row['user']['display_name']);
        $this->assertFalse($row['on_duty']);
    }

    public function test_council_members_without_shifts_appear_with_zero_but_gang_and_family_do_not(): void
    {
        $this->actAs(Role::VicePresident);
        $idle = User::factory()->role(Role::Member)->create();
        $gang = User::factory()->role(Role::Gang)->create();
        $disabled = User::factory()->role(Role::Member)->create(['disabled_at' => now()]);

        $r = $this->getJson(self::URL)->json();

        $this->assertSame(0, $this->row($r, $idle)['total_seconds']);
        $this->assertNull($this->row($r, $gang));
        $this->assertNull($this->row($r, $disabled), 'ผู้ใช้ที่ถูกระงับและไม่มีเวรไม่ต้องอยู่ในรายการ');
        $this->assertGreaterThanOrEqual(1, $r['meta']['zero_members']);
    }

    public function test_former_member_with_shifts_in_range_is_still_listed(): void
    {
        $this->actAs(Role::VicePresident);
        $demoted = User::factory()->role(Role::Gang)->create(['disabled_at' => now()]);
        $this->shift($demoted, '2026-06-10 10:00:00', '2026-06-10 12:00:00');

        $row = $this->row($this->getJson(self::URL.'?from=2026-06-01&to=2026-06-15')->json(), $demoted);
        $this->assertSame(7200, $row['total_seconds']);
        $this->assertTrue($row['user']['disabled']);
    }

    public function test_shift_crossing_midnight_is_clipped_to_the_selected_days_in_thai_time(): void
    {
        $this->actAs(Role::VicePresident);
        $u = User::factory()->role(Role::Member)->create();
        // 23:00 → 02:00 เวลาไทย (16:00 → 19:00 UTC) คร่อมเที่ยงคืนของวันที่ 10 → 11
        $this->shift($u, '2026-06-10 16:00:00', '2026-06-10 19:00:00');

        $day10 = $this->row($this->getJson(self::URL.'?from=2026-06-10&to=2026-06-10')->json(), $u);
        $day11 = $this->row($this->getJson(self::URL.'?from=2026-06-11&to=2026-06-11')->json(), $u);
        $both = $this->row($this->getJson(self::URL.'?from=2026-06-10&to=2026-06-11')->json(), $u);

        $this->assertSame(3600, $day10['total_seconds'], 'วันที่ 10 ได้ 23:00–24:00');
        $this->assertSame(7200, $day11['total_seconds'], 'วันที่ 11 ได้ 00:00–02:00');
        $this->assertSame(10800, $both['total_seconds']);
    }

    public function test_shifts_outside_the_range_are_not_counted(): void
    {
        $this->actAs(Role::VicePresident);
        $u = User::factory()->role(Role::Member)->create();
        $this->shift($u, '2026-05-01 10:00:00', '2026-05-01 14:00:00');

        $row = $this->row($this->getJson(self::URL.'?from=2026-06-01&to=2026-06-15')->json(), $u);
        $this->assertSame(0, $row['total_seconds']);
        $this->assertSame(0, $row['shifts']);
    }

    public function test_open_shift_counts_until_now_and_is_marked_on_duty(): void
    {
        $this->actAs(Role::VicePresident);
        $u = User::factory()->role(Role::Member)->create();
        $this->shift($u, now()->subMinutes(90)->toDateTimeString(), null);

        $row = $this->row($this->getJson(self::URL)->json(), $u);

        $this->assertTrue($row['on_duty']);
        $this->assertSame(5400, $row['total_seconds']);
    }

    public function test_stale_open_shift_is_closed_before_summing_so_hours_are_not_inflated(): void
    {
        $this->actAs(Role::VicePresident);
        $u = User::factory()->role(Role::Member)->create();
        // เปิดเวรไว้ 5 ชม. ที่แล้ว แล้วเงียบไปตั้งแต่ชั่วโมงแรก (ปิดเว็บ)
        DutyShift::create(['user_id' => $u->id, 'started_at' => now()->subHours(5), 'last_seen_at' => now()->subHours(4)]);

        $row = $this->row($this->getJson(self::URL)->json(), $u);

        $this->assertFalse($row['on_duty']);
        $this->assertSame(3600, $row['total_seconds'], 'นับถึงสัญญาณสุดท้ายเท่านั้น');
        $this->assertSame(1, $row['forced_count']);
    }

    public function test_short_and_forced_shifts_are_counted_separately(): void
    {
        $this->actAs(Role::VicePresident);
        $u = User::factory()->role(Role::Member)->create();

        $this->shift($u, '2026-06-10 10:00:00', '2026-06-10 11:00:00', ['note' => 'ธุระ']);              // สั้น (ออกเอง < 3 ชม.)
        $this->shift($u, '2026-06-11 10:00:00', '2026-06-11 14:00:00');                                  // ปกติ
        $this->shift($u, '2026-06-12 10:00:00', '2026-06-12 10:30:00', ['ended_reason' => DutyShift::ABANDONED, 'note' => DutyShift::SYSTEM_NOTE]); // ระบบบังคับ

        $row = $this->row($this->getJson(self::URL.'?from=2026-06-01&to=2026-06-15')->json(), $u);

        $this->assertSame(3, $row['shifts']);
        $this->assertSame(1, $row['short_count']);
        $this->assertSame(1, $row['forced_count']);
    }

    public function test_rows_are_sorted_by_hours_and_meta_has_totals(): void
    {
        $this->actAs(Role::VicePresident);
        $low = User::factory()->role(Role::Member)->create(['character_name' => 'Low']);
        $high = User::factory()->role(Role::Member)->create(['character_name' => 'High']);
        $this->shift($low, '2026-06-10 10:00:00', '2026-06-10 11:00:00');
        $this->shift($high, '2026-06-10 10:00:00', '2026-06-10 14:00:00');

        $r = $this->getJson(self::URL.'?from=2026-06-01&to=2026-06-15')->json();
        $names = collect($r['data'])->pluck('user.display_name')->all();

        $this->assertLessThan(array_search('Low', $names), array_search('High', $names));
        $this->assertSame(5 * 3600, $r['meta']['total_seconds']);
        $this->assertSame('2026-06-01', $r['meta']['from']);
        $this->assertSame('Asia/Bangkok', $r['meta']['timezone']);
    }

    public function test_detail_lists_each_shift_with_note_and_flags(): void
    {
        $this->actAs(Role::VicePresident);
        $u = User::factory()->role(Role::Member)->create();
        $this->shift($u, '2026-06-10 10:00:00', '2026-06-10 11:00:00', ['note' => 'ติดธุระ']);
        $this->shift($u, '2026-06-11 10:00:00', '2026-06-11 10:20:00', ['ended_reason' => DutyShift::ABANDONED, 'note' => DutyShift::SYSTEM_NOTE]);

        $r = $this->getJson(self::URL."/{$u->id}?from=2026-06-01&to=2026-06-15")->assertOk()->json();

        $this->assertCount(2, $r['data']['shifts']);
        $newest = $r['data']['shifts'][0];
        $this->assertTrue($newest['forced']);
        $this->assertSame('บังคับออกเวรโดยระบบ', $newest['note']);
        $oldest = $r['data']['shifts'][1];
        $this->assertTrue($oldest['short']);
        $this->assertSame('ติดธุระ', $oldest['note']);
        $this->assertSame(3600, $oldest['seconds']);
    }

    public function test_detail_is_limited_to_vice_president_up(): void
    {
        $u = User::factory()->role(Role::Member)->create();
        $this->actAs(Role::Member);
        $this->getJson(self::URL."/{$u->id}")->assertForbidden();
    }

    public function test_date_validation_and_default_range(): void
    {
        $this->actAs(Role::VicePresident);

        $this->getJson(self::URL.'?from=2026-06-10&to=2026-06-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson(self::URL.'?from=10/06/2026')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson(self::URL.'?from=2024-01-01&to=2026-06-15')->assertUnprocessable();

        $meta = $this->getJson(self::URL)->assertOk()->json('meta');
        $this->assertSame('2026-06-15', $meta['to']);
        $this->assertSame('2026-05-17', $meta['from'], 'ค่าเริ่มต้น = 30 วันล่าสุดรวมวันนี้');
    }

    public function test_opening_the_report_is_access_logged(): void
    {
        $this->actAs(Role::VicePresident);
        $this->getJson(self::URL)->assertOk();

        $this->assertTrue(Activity::where('log_name', 'access')->where('description', 'like', '%สรุปชั่วโมงเวร%')->exists());
    }
}
