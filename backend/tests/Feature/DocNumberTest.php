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
}
