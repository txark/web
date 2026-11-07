<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\CouncilEntry;
use App\Models\Group;
use App\Models\GroupRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** รหัสรายการ WC-ชนิด-วันที่-สุ่ม ของรายการการเงิน/ประกาศ/บันทึกทะเบียน */
class DocNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_get_unique_typed_numbers_automatically(): void
    {
        $u = User::factory()->create();
        $g = Group::factory()->create();

        $e1 = CouncilEntry::create(['type' => 'income', 'spent_at' => now(), 'amount' => 100, 'category' => 'other', 'title' => 'a', 'created_by' => $u->id]);
        $e2 = CouncilEntry::create(['type' => 'income', 'spent_at' => now(), 'amount' => 100, 'category' => 'other', 'title' => 'b', 'created_by' => $u->id]);
        $this->assertMatchesRegularExpression('/^WC-ENT-\d{8}-[A-Z0-9]{6}$/', $e1->number);
        $this->assertNotSame($e1->number, $e2->number);

        $fine = GroupRecord::create(['group_id' => $g->id, 'kind' => 'fine', 'data' => ['penalty_type' => 'fine'], 'recorded_by' => $u->id]);
        $pen = GroupRecord::create(['group_id' => $g->id, 'kind' => 'fine', 'data' => ['penalty_type' => 'penance'], 'recorded_by' => $u->id]);
        $this->assertStringStartsWith('WC-FINE-', $fine->number);
        $this->assertStringStartsWith('WC-PEN-', $pen->number);
    }

    public function test_activity_log_details_show_the_code_of_finance_records(): void
    {
        $admin = User::factory()->role(\App\Enums\Role::Admin)->loggedIn()->create();
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $e = CouncilEntry::create(['type' => 'expense', 'spent_at' => now(), 'amount' => 2500, 'category' => 'other', 'title' => 'ซื้อของ', 'created_by' => $admin->id]);

        $rows = $this->getJson('/api/logs?per_page=100')->assertOk()->json('data');
        $row = collect($rows)->first(fn ($r) => ($r['subject']['label'] ?? null) === 'รายรับ/รายจ่าย');

        $this->assertNotNull($row);
        $this->assertContains(['รหัสรายการ', $e->number], $row['details']);
        $this->assertContains(['รายจ่าย', '2,500 WVDL'], $row['details']);
    }
}
