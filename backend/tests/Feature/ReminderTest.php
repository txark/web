<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CouncilReminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** บันทึกเตือนความจำของสภา: เพิ่ม / เสร็จ / ลบ และการขึ้นในแจ้งเตือนงานค้าง */
class ReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['council.duty.enforced' => false]);
        Carbon::setTestNow(Carbon::parse('2026-11-05 12:00:00', 'Asia/Bangkok'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actAs(Role $role = Role::Member): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        Sanctum::actingAs($u);

        return $u;
    }

    private function reminders(): array
    {
        return collect($this->getJson('/api/council/alerts')->assertOk()->json('data'))->where('type', 'reminder')->values()->all();
    }

    public function test_only_council_can_use_reminders(): void
    {
        $this->postJson('/api/council/reminders', ['title' => 'x'])->assertUnauthorized();
        $this->actAs(Role::Gang);
        $this->postJson('/api/council/reminders', ['title' => 'x'])->assertForbidden();
    }

    public function test_reminder_validation(): void
    {
        $this->actAs();
        $this->postJson('/api/council/reminders', [])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson('/api/council/reminders', ['title' => 'x', 'due_on' => 'พรุ่งนี้'])->assertUnprocessable()->assertJsonValidationErrors('due_on');
        $this->postJson('/api/council/reminders', ['title' => str_repeat('ก', 151)])->assertUnprocessable();
    }

    public function test_reminders_show_in_alerts_with_severity_by_due_date_until_done(): void
    {
        $me = $this->actAs();
        $this->postJson('/api/council/reminders', ['title' => 'ไม่มีกำหนด'])->assertCreated();
        $this->postJson('/api/council/reminders', ['title' => 'เลยกำหนด', 'due_on' => '2026-11-03', 'body' => 'โทรตาม'])->assertCreated();
        $this->postJson('/api/council/reminders', ['title' => 'พรุ่งนี้', 'due_on' => '2026-11-06'])->assertCreated();
        $this->postJson('/api/council/reminders', ['title' => 'อีกไกล', 'due_on' => '2026-11-20'])->assertCreated();

        $by = collect($this->reminders())->keyBy('title');
        $this->assertSame('low', $by['ไม่มีกำหนด']['severity']);
        $this->assertSame('high', $by['เลยกำหนด']['severity']);
        $this->assertStringContainsString('เกินกำหนด 2 วัน', $by['เลยกำหนด']['detail']);
        $this->assertStringContainsString('โทรตาม', $by['เลยกำหนด']['detail']);
        $this->assertSame('medium', $by['พรุ่งนี้']['severity']);
        $this->assertSame('low', $by['อีกไกล']['severity']);
        $this->assertSame($me->id, $by['อีกไกล']['created_by']);

        // ทำเครื่องหมายเสร็จแล้วหายไป (สภาคนอื่นทำได้) และทำซ้ำไม่ได้
        $id = $by['เลยกำหนด']['reminder_id'];
        $this->actAs(Role::Senior);
        $this->postJson("/api/council/reminders/{$id}/done")->assertOk();
        $this->postJson("/api/council/reminders/{$id}/done")->assertStatus(409);
        $this->assertNotContains('เลยกำหนด', array_column($this->reminders(), 'title'));
        $this->assertNotNull(CouncilReminder::find($id)->done_by);
    }

    public function test_only_creator_or_vice_president_can_delete(): void
    {
        $creator = $this->actAs();
        $id = $this->postJson('/api/council/reminders', ['title' => 'ลบทดสอบ'])->assertCreated()->json('data.id');

        $this->actAs(Role::Senior);
        $this->deleteJson("/api/council/reminders/{$id}")->assertForbidden();

        Sanctum::actingAs($creator);
        $this->deleteJson("/api/council/reminders/{$id}")->assertNoContent();

        $id2 = $this->postJson('/api/council/reminders', ['title' => 'รองประธานลบได้'])->assertCreated()->json('data.id');
        $this->actAs(Role::VicePresident);
        $this->deleteJson("/api/council/reminders/{$id2}")->assertNoContent();
        $this->assertSame(0, CouncilReminder::count());
    }
}
