<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\DutyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** บันทึกกิจกรรม: รายละเอียดของออกเวร (ช่วงเวลา ระยะเวลา สาเหตุออกก่อนเวลา) */
class LogDutyDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function rows(): array
    {
        Sanctum::actingAs(User::factory()->role(Role::Admin)->loggedIn()->create());

        return $this->getJson('/api/logs?per_page=100')->assertOk()->json('data');
    }

    public function test_early_exit_shows_reason_range_and_duration_but_start_has_no_details(): void
    {
        config(['council.duty.enforced' => true, 'council.duty.exempt_roles' => []]);
        $u = User::factory()->role(Role::Member)->loggedIn()->create();
        $svc = app(DutyService::class);

        $this->travelTo(now()->setTime(12, 0));
        $svc->start($u);
        $this->travelTo(now()->addMinutes(45));
        \App\Models\DutyShift::query()->update(['last_seen_at' => now()]); // ยังออนไลน์อยู่ ไม่ถูกปิดโดยระบบ
        $svc->end($u, 'ติดธุระด่วน');
        $this->travelBack();

        $rows = collect($this->rows());
        $end = $rows->firstWhere('event', 'end');
        $lines = collect($end['details'])->pluck(1, 0);

        $this->assertSame('ติดธุระด่วน', $lines['สาเหตุที่ออกก่อนเวลา']);
        $this->assertSame('45 นาที', $lines['ระยะเวลาที่เข้าเวร']);
        $this->assertStringContainsString('น.', $lines['ช่วงเวลาที่เข้าเวร']);
        $this->assertSame([], $rows->firstWhere('event', 'start')['details']);
    }

    public function test_full_shift_has_range_and_duration_without_a_reason(): void
    {
        config(['council.duty.enforced' => true, 'council.duty.exempt_roles' => []]);
        $u = User::factory()->role(Role::Member)->loggedIn()->create();
        $svc = app(DutyService::class);

        $this->travelTo(now()->setTime(12, 0));
        $svc->start($u);
        $this->travelTo(now()->addHours(3)->addMinutes(10));
        \App\Models\DutyShift::query()->update(['last_seen_at' => now()]);
        $svc->end($u);
        $this->travelBack();

        $end = collect($this->rows())->firstWhere('event', 'end');
        $lines = collect($end['details'])->pluck(1, 0);

        $this->assertArrayNotHasKey('สาเหตุที่ออกก่อนเวลา', $lines->all());
        $this->assertSame('3 ชม. 10 นาที', $lines['ระยะเวลาที่เข้าเวร']);
    }

    public function test_system_end_shows_range_and_duration_up_to_the_last_signal(): void
    {
        config(['council.duty.enforced' => true, 'council.duty.exempt_roles' => []]);
        $u = User::factory()->role(Role::Member)->loggedIn()->create();
        $svc = app(DutyService::class);

        $this->travelTo(now()->setTime(12, 0));
        $svc->start($u);
        $this->travelTo(now()->addHours(2)); // เงียบไปเลย → ระบบปิดเวรให้
        $svc->closeAllStale();
        $this->travelBack();

        $row = collect($this->rows())->firstWhere('event', 'system_end');
        $lines = collect($row['details'])->pluck(1, 0);

        $this->assertArrayHasKey('ช่วงเวลาที่เข้าเวร', $lines->all());
        $this->assertArrayHasKey('ระยะเวลาที่เข้าเวร', $lines->all());
        $this->assertArrayNotHasKey('สาเหตุที่ออกก่อนเวลา', $lines->all());
    }
}
