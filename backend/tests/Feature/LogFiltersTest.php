<?php

namespace Tests\Feature;

use App\Enums\MemberPosition;
use App\Enums\Role;
use App\Models\DutyShift;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** บันทึกกิจกรรม: กรองตามหมวดหมู่ (ธุรกรรม/แก๊ง/เวร) และสถานะ (อนุมัติ/ไม่อนุมัติ/ยกเลิก) */
class LogFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function onDuty(Role $role): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        DutyShift::create(['user_id' => $u->id, 'started_at' => now()->subMinutes(20), 'last_seen_at' => now()]);
        Sanctum::actingAs($u);

        return $u;
    }

    private function submit(Group $g, bool $approval)
    {
        return $this->post('/api/council/transactions', [
            'kind' => 'outfit', 'group_id' => $g->id, 'approval_required' => $approval,
            'payload' => ['add' => ['suit' => 1], 'outfit_zip' => UploadedFile::fake()->createWithContent('o.zip', "PK\x03\x04\0\0\0\0")],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    public function test_transaction_logs_carry_a_status_and_can_be_filtered(): void
    {
        config(['council.duty.enforced' => true, 'council.duty.exempt_roles' => []]);
        Storage::fake('local');
        $g = Group::factory()->create(['registered_at' => now()->subDays(100)]);
        $g->members()->create(['character_name' => 'Boss', 'position' => MemberPosition::Leader, 'joined_at' => now()->subDays(50)]);

        $this->onDuty(Role::Member);
        $this->submit($g, false);                       // อนุมัติ (มีผลทันที)
        $rejected = $this->submit($g, true);
        $cancelled = $this->submit($g, true);
        $this->postJson("/api/council/transactions/{$cancelled['id']}/cancel")->assertOk();

        $this->onDuty(Role::Member);
        $this->postJson("/api/council/transactions/{$rejected['id']}/reject", ['reason' => 'ไฟล์ไม่ผ่าน'])->assertOk();

        $this->onDuty(Role::VicePresident);
        $events = fn (array $q) => collect($this->getJson('/api/logs?'.http_build_query($q))->assertOk()->json('data'))->pluck('event')->sort()->values()->all();

        $this->assertSame(['approved', 'cancelled', 'rejected'], $events(['type' => 'transactions']));
        $this->assertSame(['approved'], $events(['type' => 'transactions', 'event' => 'approved']));
        $this->assertSame(['rejected'], $events(['type' => 'transactions', 'event' => 'rejected']));
        $this->assertSame(['cancelled'], $events(['type' => 'transactions', 'event' => 'cancelled']));

        $row = collect($this->getJson('/api/logs?type=transactions&event=rejected')->json('data'))->first();
        $this->assertSame('ไม่อนุมัติ', $row['event_label']);
        $this->assertSame('ธุรกรรม', $row['log_label']);
    }

    public function test_new_categories_are_accepted_and_unknown_ones_are_not(): void
    {
        config(['council.duty.enforced' => false]);
        $this->onDuty(Role::VicePresident);

        foreach (['auth', 'access', 'changes', 'transactions', 'groups', 'duty'] as $type) {
            $this->getJson("/api/logs?type={$type}")->assertOk();
        }
        $this->getJson('/api/logs?type=whatever')->assertUnprocessable();
    }
}
