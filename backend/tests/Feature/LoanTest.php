<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CouncilLoan;
use App\Models\DutyShift;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * สัญญากู้เงินของสภา (ประธานและรองประธาน): ตั้งค่ากลาง · ดอกเบี้ย % ต่อสัปดาห์ · ผ่อนรายสัปดาห์ · ค่าปรับเมื่อเลยกำหนด ·
 * บันทึกการคืน · แก้/ลบไม่ได้ (ยกเลิกด้วยรายการต่อท้าย) · แจ้งเตือนพร้อมข้อความทวง · เชื่อมสรุปการเงิน
 * "วันนี้" ในเทสต์ = 15 พ.ย. 2026 20:00 เวลาไทย
 */
class LoanTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/council/finance';

    protected function setUp(): void
    {
        parent::setUp();
        config(['council.duty.enforced' => true, 'council.duty.exempt_roles' => []]);
        Carbon::setTestNow(Carbon::parse('2026-11-15 20:00:00', 'Asia/Bangkok'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function council(Role $role = Role::VicePresident): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        DutyShift::create(['user_id' => $u->id, 'started_at' => now()->subMinutes(20), 'last_seen_at' => now()]);
        Sanctum::actingAs($u);

        return $u;
    }

    private function loan(Group $g, array $over = [])
    {
        return $this->postJson(self::URL.'/loans', $over + [
            'group_id' => $g->id, 'borrower_name' => 'สมชาย กิตติ', 'reason' => 'ซื้ออุปกรณ์ของแก๊ง',
            'principal' => 1000000, 'loaned_at' => '2026-11-10', 'due_at' => '2026-11-20',
        ]);
    }

    private function pay(int $loanId, array $over = [])
    {
        return $this->postJson(self::URL."/loans/{$loanId}/payments", $over + ['amount' => 400000, 'paid_at' => '2026-11-12']);
    }

    private function show(int $id): array
    {
        return $this->getJson(self::URL."/loans/{$id}")->assertOk()->json('data');
    }

    private function settings(array $over = [])
    {
        return $this->putJson(self::URL.'/loan-settings', $over + ['default_interest' => 2, 'late_fee_type' => 'day', 'late_fee_amount' => 500, 'max_installments' => 6]);
    }

    // ───────────────────────── สิทธิ์ ─────────────────────────

    public function test_only_president_and_vice_president_can_use_loans_and_settings(): void
    {
        $g = Group::factory()->create();
        $this->getJson(self::URL.'/loans')->assertUnauthorized();
        foreach ([Role::Member, Role::Secretary, Role::Gang] as $role) {
            $this->council($role);
            $this->getJson(self::URL.'/loans')->assertForbidden();
            $this->loan($g)->assertForbidden();
            $this->getJson(self::URL.'/loan-settings')->assertForbidden();
            $this->settings()->assertForbidden();
        }
        foreach ([Role::VicePresident, Role::President] as $role) {
            $this->council($role);
            $this->getJson(self::URL.'/loans')->assertOk();
            $this->getJson(self::URL.'/loan-settings')->assertOk();
        }
    }

    // ───────────────────────── ตั้งค่ากลาง ─────────────────────────

    public function test_group_registry_lists_its_own_loans_for_the_president_and_vice_president_only(): void
    {
        $this->council();
        $mine = Group::factory()->create();
        $other = Group::factory()->create();
        $this->loan($mine, ['reason' => 'ของฉัน'])->assertCreated();
        $this->loan($other, ['reason' => 'ของอื่น'])->assertCreated();

        $res = $this->getJson("/api/council/groups/{$mine->id}/loans")->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('ของฉัน', $res->json('data.0.reason'));
        $this->assertArrayNotHasKey('payments', $res->json('data.0'));

        $this->council(Role::Member);
        $this->getJson("/api/council/groups/{$mine->id}/loans")->assertForbidden();
    }

    public function test_central_settings_have_neutral_defaults_and_can_be_changed_with_a_history(): void
    {
        $this->council(Role::President);
        $d = $this->getJson(self::URL.'/loan-settings')->assertOk()->json('data');
        $this->assertSame(['default_interest' => 0, 'late_fee_type' => 'day', 'late_fee_amount' => 0, 'max_installments' => 6], $d['settings']);
        $this->assertSame([], $d['logs']);
        $this->assertSame(['day', 'week', 'once'], array_column($d['fee_types'], 'key'));

        $res = $this->settings(['default_interest' => 3, 'late_fee_type' => 'week', 'late_fee_amount' => 1000, 'max_installments' => 4])->assertOk();
        $res->assertJsonPath('data.settings.default_interest', 3)->assertJsonPath('data.settings.late_fee_type', 'week');
        $logs = collect($res->json('data.logs'))->keyBy('field');
        $this->assertCount(4, $logs);
        $this->assertSame('0%', $logs['default_interest']['old']);
        $this->assertSame('3%', $logs['default_interest']['new']);
        $this->assertSame('ต่อวัน', $logs['late_fee_type']['old']);
        $this->assertSame('ต่อสัปดาห์', $logs['late_fee_type']['new']);
        $this->assertSame('0 → 1,000', $logs['late_fee_amount']['old'].' → '.$logs['late_fee_amount']['new']);
        $this->assertSame('6 งวด', $logs['max_installments']['old']);
        $this->assertSame('ประธานสภา', $logs['default_interest']['role_label']);

        // ส่งค่าเดิมซ้ำ = ไม่เพิ่มประวัติ แก้ค่าเดียว = เพิ่มประวัติ 1 รายการ
        $this->settings(['default_interest' => 3, 'late_fee_type' => 'week', 'late_fee_amount' => 1000, 'max_installments' => 4])->assertOk()->assertJsonCount(4, 'data.logs');
        $this->settings(['default_interest' => 2.5, 'late_fee_type' => 'week', 'late_fee_amount' => 1000, 'max_installments' => 4])->assertOk()->assertJsonCount(5, 'data.logs')->assertJsonPath('data.logs.0.new', '2.5%');

        $this->settings(['default_interest' => -1])->assertUnprocessable()->assertJsonValidationErrors('default_interest');
        $this->settings(['default_interest' => 101])->assertUnprocessable()->assertJsonValidationErrors('default_interest');
        $this->settings(['late_fee_type' => 'weird'])->assertUnprocessable()->assertJsonValidationErrors('late_fee_type');
        $this->settings(['late_fee_amount' => -5])->assertUnprocessable()->assertJsonValidationErrors('late_fee_amount');
        $this->settings(['max_installments' => 0])->assertUnprocessable()->assertJsonValidationErrors('max_installments');
        $this->settings(['max_installments' => 105])->assertUnprocessable()->assertJsonValidationErrors('max_installments');
    }

    public function test_new_contracts_take_defaults_from_settings_and_keep_their_own_terms_afterwards(): void
    {
        $this->council();
        $g = Group::factory()->create();
        $this->settings(['default_interest' => 2, 'late_fee_type' => 'day', 'late_fee_amount' => 500, 'max_installments' => 6])->assertOk();

        // ไม่ส่งเงื่อนไข = ใช้ค่ากลาง
        $a = $this->loan($g, ['loaned_at' => '2026-11-10', 'installments' => 3, 'due_at' => null])->assertCreated();
        $a->assertJsonPath('data.interest_rate', 2)->assertJsonPath('data.late_fee_type', 'day')->assertJsonPath('data.late_fee_value', 500)->assertJsonPath('data.interest_total', 60000);
        // ปรับเฉพาะสัญญานี้ได้
        $b = $this->loan($g, ['interest_rate' => 0, 'late_fee_type' => 'once', 'late_fee_value' => 20000, 'due_at' => '2026-11-20'])->assertCreated();
        $b->assertJsonPath('data.interest_rate', 0)->assertJsonPath('data.late_fee_type', 'once')->assertJsonPath('data.late_fee_value', 20000);

        // แก้ค่ากลางทีหลัง สัญญาเก่าไม่เปลี่ยน
        $this->settings(['default_interest' => 9, 'late_fee_type' => 'week', 'late_fee_amount' => 9999, 'max_installments' => 2])->assertOk();
        $old = $this->show($a->json('data.id'));
        $this->assertSame(2.0, (float) $old['interest_rate']);
        $this->assertSame('day', $old['late_fee_type']);
        $this->assertSame(500.0, (float) $old['late_fee_value']);
        $this->assertSame(60000, $old['interest_total']);

        // สัญญาใหม่ใช้ค่ากลางล่าสุด และจำนวนงวดเกินค่ากลางไม่ได้
        $this->loan($g, ['installments' => 3, 'due_at' => null])->assertUnprocessable()->assertJsonValidationErrors('installments');
        $this->loan($g, ['installments' => 2, 'due_at' => null])->assertCreated()->assertJsonPath('data.interest_rate', 9)->assertJsonPath('data.installments', 2);
    }

    // ───────────────────────── สร้างสัญญา ─────────────────────────

    public function test_contract_is_created_for_a_registered_group_with_validation(): void
    {
        $this->council();
        $g = Group::factory()->create();

        $this->loan($g)->assertCreated()
            ->assertJsonPath('data.group.id', $g->id)->assertJsonPath('data.borrower_name', 'สมชาย กิตติ')->assertJsonPath('data.reason', 'ซื้ออุปกรณ์ของแก๊ง')
            ->assertJsonPath('data.principal', 1000000)->assertJsonPath('data.outstanding', 1000000)->assertJsonPath('data.status', 'open')->assertJsonPath('data.status_label', 'กำลังกู้');

        $gone = Group::factory()->create();
        $gone->delete();
        $this->loan($gone)->assertUnprocessable()->assertJsonValidationErrors('group_id');
        $this->loan($g, ['group_id' => 99999])->assertUnprocessable()->assertJsonValidationErrors('group_id');
        $this->loan($g, ['borrower_name' => ''])->assertUnprocessable()->assertJsonValidationErrors('borrower_name');
        $this->loan($g, ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->loan($g, ['principal' => 0])->assertUnprocessable()->assertJsonValidationErrors('principal');
        $this->loan($g, ['principal' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('principal');
        $this->loan($g, ['loaned_at' => '2026-11-16'])->assertUnprocessable()->assertJsonValidationErrors('loaned_at');
        $this->loan($g, ['due_at' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('due_at');
        $this->loan($g, ['due_at' => null])->assertUnprocessable()->assertJsonValidationErrors('due_at');   // งวดเดียวต้องมีวันกำหนดคืน
        $this->loan($g, ['interest_rate' => 101])->assertUnprocessable()->assertJsonValidationErrors('interest_rate');
        $this->loan($g, ['interest_rate' => -1])->assertUnprocessable()->assertJsonValidationErrors('interest_rate');
        $this->loan($g, ['installments' => 0])->assertUnprocessable()->assertJsonValidationErrors('installments');
        $this->loan($g, ['late_fee_type' => 'percent'])->assertUnprocessable()->assertJsonValidationErrors('late_fee_type');
        $this->loan($g, ['late_fee_value' => -5])->assertUnprocessable()->assertJsonValidationErrors('late_fee_value');
    }

    public function test_contract_keeps_the_writers_rank_and_the_borrowers_position_in_the_group(): void
    {
        $boss = $this->council(Role::President);
        $g = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $g->id, 'character_name' => 'Boss One', 'position' => 'leader']);
        GroupMember::factory()->create(['group_id' => $g->id, 'character_name' => 'Deputy One', 'position' => 'co_leader']);
        GroupMember::factory()->create(['group_id' => $g->id, 'character_name' => 'Ex Deputy', 'position' => 'co_leader', 'left_at' => now()]);

        $this->loan($g, ['borrower_name' => ' deputy one '])->assertCreated()
            ->assertJsonPath('data.by_rank', Role::President->thaiName())->assertJsonPath('data.borrower_position', 'รองหัวหน้า');
        $this->loan($g, ['borrower_name' => 'Boss One'])->assertJsonPath('data.borrower_position', 'หัวหน้า');
        $this->loan($g, ['borrower_name' => 'Ex Deputy'])->assertJsonPath('data.borrower_position', '');   // ออกจากกลุ่มแล้ว
        $this->loan($g, ['borrower_name' => 'Somebody Else'])->assertJsonPath('data.borrower_position', '');

        // ยศที่เก็บไว้ในสัญญาไม่เปลี่ยนตามเมื่อผู้เขียนถูกเปลี่ยนยศภายหลัง
        $id = CouncilLoan::query()->where('borrower_name', 'deputy one')->value('id');
        $boss->update(['role' => Role::Member->value]);
        $this->council(Role::VicePresident);
        $this->assertSame(Role::President->thaiName(), $this->show($id)['by_rank']);

        // สัญญาเก่าที่ไม่มียศเก็บไว้: ใช้ยศปัจจุบันของผู้เขียนทั้งในรายการและหน้าสัญญา (ต้องไม่ error)
        CouncilLoan::query()->update(['creator_rank' => null]);
        $this->getJson(self::URL.'/loans')->assertOk()->assertJsonPath('data.0.by_rank', Role::Member->thaiName());
        $this->assertSame(Role::Member->thaiName(), $this->show($id)['by_rank']);
    }

    public function test_contract_image_link_is_public_by_token_and_regenerating_revokes_the_old_one(): void
    {
        Storage::fake('local');
        $this->council(Role::President);
        $id = $this->loan(Group::factory()->create())->assertCreated()->json('data.id');

        $one = $this->post(self::URL."/loans/{$id}/image", ['image' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])->assertOk();
        $token = $one->json('token');
        $this->assertSame(40, strlen($token));
        $this->assertStringEndsWith("/api/public/loan-contracts/{$token}.png", $one->json('url'));

        $this->get("/api/public/loan-contracts/{$token}.png")->assertOk()->assertHeader('Content-Type', 'image/png');   // ไม่ต้องล็อกอิน
        $this->get('/api/public/loan-contracts/'.str_repeat('x', 40).'.png')->assertNotFound();

        // สร้างลิงก์ใหม่: ลิงก์เก่าใช้ไม่ได้
        $two = $this->post(self::URL."/loans/{$id}/image", ['image' => UploadedFile::fake()->image('b.png')], ['Accept' => 'application/json'])->assertOk()->json('token');
        $this->assertNotSame($token, $two);
        $this->get("/api/public/loan-contracts/{$token}.png")->assertNotFound();
        $this->get("/api/public/loan-contracts/{$two}.png")->assertOk();

        // ไฟล์ต้องเป็น PNG และมีรูป
        $this->post(self::URL."/loans/{$id}/image", ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->post(self::URL."/loans/{$id}/image", [], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');

        // สภาทั่วไปสร้างลิงก์รูปไม่ได้ (ข้อมูลการเงิน)
        $this->council(Role::Member);
        $this->post(self::URL."/loans/{$id}/image", ['image' => UploadedFile::fake()->image('c.png')], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_interest_is_simple_per_week_and_split_across_weekly_installments(): void
    {
        $this->council();
        // กู้ 1,000,000 ดอกเบี้ย 2%/สัปดาห์ ผ่อน 4 งวด: ดอกเบี้ยรวม = 1,000,000 x 2% x 4 = 80,000 (ไม่ทบต้น)
        $res = $this->loan(Group::factory()->create(), ['loaned_at' => '2026-11-10', 'due_at' => null, 'interest_rate' => 2, 'installments' => 4])->assertCreated();
        $res->assertJsonPath('data.interest_total', 80000)->assertJsonPath('data.total_payable', 1080000)->assertJsonPath('data.outstanding', 1080000)
            ->assertJsonPath('data.final_due', '2026-12-08')->assertJsonPath('data.due_at', '2026-12-08');

        $s = $res->json('data.schedule');
        $this->assertSame(['2026-11-17', '2026-11-24', '2026-12-01', '2026-12-08'], array_column($s, 'due_at'));
        $this->assertSame(270000, $s[0]['amount']);                    // 250,000 ต้น + 20,000 ดอกเบี้ย
        $this->assertSame(1080000, array_sum(array_column($s, 'amount')));
        $this->assertSame(['upcoming', 'upcoming', 'upcoming', 'upcoming'], array_column($s, 'status'));

        // เศษหารไม่ลงตัวไปรวมงวดสุดท้าย
        $r = $this->loan(Group::factory()->create(), ['interest_rate' => 1.5, 'installments' => 3, 'due_at' => null])->assertCreated();
        $parts = $r->json('data.schedule');
        $this->assertSame(1000000, array_sum(array_column($parts, 'principal')));
        $this->assertSame($r->json('data.interest_total'), array_sum(array_column($parts, 'interest')));
        $this->assertSame(333333, $parts[0]['principal']);
        $this->assertSame(333334, $parts[2]['principal']);
    }

    // ───────────────────────── ค่าปรับเมื่อเลยกำหนด ─────────────────────────

    public function test_late_fee_per_day_counts_days_after_the_due_date_until_the_installment_is_paid(): void
    {
        $this->council();
        $id = $this->loan(Group::factory()->create(), ['loaned_at' => '2026-11-01', 'due_at' => '2026-11-08', 'late_fee_type' => 'day', 'late_fee_value' => 500])->json('data.id');

        // วันนี้ 15 พ.ย. เลยกำหนด 7 วัน: ค่าปรับ 7 x 500 = 3,500 (ตามแบบร่าง "เกินกำหนด N วัน × อัตรา")
        $d = $this->show($id);
        $this->assertSame('overdue', $d['status']);
        $this->assertSame(7, $d['days_overdue']);
        $this->assertSame(3500, $d['late_fee_total']);
        $this->assertSame(1003500, $d['total_payable']);
        $this->assertSame(1000000, $d['outstanding_pi']);
        $this->assertSame(3500, $d['outstanding_fee']);

        // คืนต้นเงินครบวันนี้: ค่าปรับหยุดนับที่วันที่ชำระ (7 วัน) ยอดค้างเหลือเฉพาะค่าปรับ สถานะ "คืนบางส่วน" จนกว่าจะจ่ายค่าปรับ
        $r = $this->pay($id, ['amount' => 1000000, 'paid_at' => '2026-11-15'])->assertCreated();
        $r->assertJsonPath('data.status', 'partial')->assertJsonPath('data.outstanding', 3500)->assertJsonPath('data.outstanding_pi', 0)->assertJsonPath('data.outstanding_fee', 3500);
        $this->pay($id, ['amount' => 3500, 'paid_at' => '2026-11-15'])->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.outstanding', 0);
    }

    public function test_late_fee_per_week_rounds_partial_weeks_up(): void
    {
        $this->council();
        // ครบกำหนด 7 พ.ย. → วันนี้ 15 พ.ย. เลย 8 วัน = 2 สัปดาห์ (ปัดขึ้น) x 1,000
        $id = $this->loan(Group::factory()->create(), ['loaned_at' => '2026-11-01', 'due_at' => '2026-11-07', 'late_fee_type' => 'week', 'late_fee_value' => 1000])->json('data.id');
        $this->assertSame(2000, $this->show($id)['late_fee_total']);

        // เลย 7 วันพอดี = 1 สัปดาห์
        $id2 = $this->loan(Group::factory()->create(), ['loaned_at' => '2026-11-01', 'due_at' => '2026-11-08', 'late_fee_type' => 'week', 'late_fee_value' => 1000])->json('data.id');
        $this->assertSame(1000, $this->show($id2)['late_fee_total']);
    }

    public function test_late_fee_once_is_charged_a_single_time_per_contract(): void
    {
        $this->council();
        // ผ่อน 3 งวดจากวันที่ 25 ต.ค.: ครบกำหนด 1, 8, 15 พ.ย. → งวด 1 เลย 14 วัน งวด 2 เลย 7 วัน
        $once = $this->loan(Group::factory()->create(), ['loaned_at' => '2026-10-25', 'due_at' => null, 'installments' => 3, 'late_fee_type' => 'once', 'late_fee_value' => 10000])->json('data.id');
        $d = $this->show($once);
        $this->assertSame(10000, $d['late_fee_total']);                // ครั้งเดียวต่อสัญญา ไม่ใช่ต่องวด
        $this->assertSame(10000, $d['schedule'][0]['late_fee']);
        $this->assertSame(0, $d['schedule'][1]['late_fee']);

        // แบบต่อวันในสถานการณ์เดียวกัน: (14 + 7) x 500 = 10,500
        $day = $this->loan(Group::factory()->create(), ['loaned_at' => '2026-10-25', 'due_at' => null, 'installments' => 3, 'late_fee_type' => 'day', 'late_fee_value' => 500])->json('data.id');
        $d = $this->show($day);
        $this->assertSame(10500, $d['late_fee_total']);
        $this->assertSame([14, 7, 0], array_column($d['schedule'], 'days_late'));
        $this->assertSame(['overdue', 'overdue', 'due'], array_column($d['schedule'], 'status'));
        $this->assertSame(14, $d['days_overdue']);
    }

    public function test_paying_an_installment_on_time_avoids_its_late_fee(): void
    {
        $this->council();
        $id = $this->loan(Group::factory()->create(), ['loaned_at' => '2026-11-01', 'due_at' => null, 'installments' => 3, 'late_fee_type' => 'day', 'late_fee_value' => 1000])->json('data.id');
        // งวดละ 333,333/333,333/333,334 ครบกำหนด 8, 15, 22 พ.ย. วันนี้ 15: งวด 1 เลย 7 วัน
        $this->assertSame(7000, $this->show($id)['late_fee_total']);

        $this->pay($id, ['amount' => 333333, 'paid_at' => '2026-11-07'])->assertCreated()->assertJsonPath('data.late_fee_total', 0)->assertJsonPath('data.status', 'partial');
        $this->pay($id, ['amount' => 333333, 'paid_at' => '2026-11-15'])->assertCreated()->assertJsonPath('data.late_fee_total', 0); // งวด 2 ครบกำหนดวันนี้ไม่ช้า
    }

    // ───────────────────────── การคืนเงิน ─────────────────────────

    public function test_repayments_update_status_and_cannot_exceed_the_outstanding_amount(): void
    {
        $this->council();
        $id = $this->loan(Group::factory()->create())->json('data.id');

        $this->pay($id)->assertCreated()->assertJsonPath('data.repaid', 400000)->assertJsonPath('data.outstanding', 600000)->assertJsonPath('data.status', 'partial')->assertJsonPath('data.status_label', 'คืนบางส่วน');
        $this->pay($id, ['amount' => 700000])->assertUnprocessable()->assertJsonValidationErrors('amount');   // เกินยอดคงค้าง
        $this->pay($id, ['amount' => 0])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->pay($id, ['paid_at' => '2026-11-09'])->assertUnprocessable()->assertJsonValidationErrors('paid_at'); // ก่อนวันที่กู้
        $this->pay($id, ['paid_at' => '2026-11-16'])->assertUnprocessable()->assertJsonValidationErrors('paid_at'); // อนาคต

        $res = $this->pay($id, ['amount' => 600000, 'paid_at' => '2026-11-14'])->assertCreated();
        $res->assertJsonPath('data.outstanding', 0)->assertJsonPath('data.status', 'paid')->assertJsonPath('data.status_label', 'คืนครบ');
        $this->assertCount(2, $res->json('data.payments'));
        $this->pay($id, ['amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('amount');         // คืนครบแล้ว
    }

    public function test_repayments_settle_installments_in_order(): void
    {
        $this->council();
        $id = $this->loan(Group::factory()->create(), ['loaned_at' => '2026-11-01', 'due_at' => null, 'interest_rate' => 2, 'installments' => 4, 'late_fee_type' => 'day', 'late_fee_value' => 0])->json('data.id'); // งวดละ 270,000

        $d = $this->show($id);
        $this->assertSame(['overdue', 'due', 'upcoming', 'upcoming'], array_column($d['schedule'], 'status'));
        $this->assertSame('overdue', $d['status']);
        $this->assertSame(1, $d['next_due']['no']);

        $this->pay($id, ['amount' => 270000, 'paid_at' => '2026-11-15'])->assertCreated();
        $d = $this->show($id);
        $this->assertSame(['paid_late', 'due', 'upcoming', 'upcoming'], array_column($d['schedule'], 'status'));
        $this->assertSame(2, $d['next_due']['no']);
        $this->assertSame('partial', $d['status']);

        $this->pay($id, ['amount' => 810000, 'paid_at' => '2026-11-15'])->assertCreated()->assertJsonPath('data.outstanding', 0)->assertJsonPath('data.status', 'paid');
    }

    // ───────────────────────── แก้/ลบไม่ได้ · ยกเลิกด้วยรายการต่อท้าย ─────────────────────────

    public function test_contracts_and_repayments_cannot_be_edited_or_deleted(): void
    {
        $this->council();
        $id = $this->loan(Group::factory()->create())->json('data.id');
        $pid = $this->pay($id)->json('data.payments.0.id');

        $this->putJson(self::URL."/loans/{$id}", ['principal' => 1])->assertStatus(405);
        $this->patchJson(self::URL."/loans/{$id}", ['principal' => 1])->assertStatus(405);
        $this->deleteJson(self::URL."/loans/{$id}")->assertStatus(405);
        $this->deleteJson(self::URL."/loans/{$id}/payments/{$pid}")->assertStatus(404);
        $this->putJson(self::URL."/loans/{$id}/payments/{$pid}", ['amount' => 1])->assertStatus(404);
        $this->assertSame(1000000, CouncilLoan::find($id)->principal);
        $this->assertSame(0, CouncilLoan::onlyTrashed()->count());
    }

    public function test_a_repayment_is_corrected_by_a_reversal_entry_and_the_original_stays(): void
    {
        $this->council();
        $id = $this->loan(Group::factory()->create())->json('data.id');
        $pid = $this->pay($id)->json('data.payments.0.id');

        $this->postJson(self::URL."/loans/{$id}/payments/{$pid}/reverse", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $res = $this->postJson(self::URL."/loans/{$id}/payments/{$pid}/reverse", ['reason' => 'กรอกจำนวนผิด'])->assertCreated();
        $res->assertJsonPath('data.repaid', 0)->assertJsonPath('data.outstanding', 1000000)->assertJsonPath('data.status', 'open');

        $pays = collect($res->json('data.payments'));
        $this->assertCount(2, $pays);                                    // รายการเดิมยังอยู่ + รายการยกเลิก
        $orig = $pays->firstWhere('id', $pid);
        $this->assertTrue($orig['reversed']);
        $this->assertFalse($orig['is_reversal']);
        $rev = $pays->firstWhere('is_reversal', true);
        $this->assertSame($pid, $rev['reversal_of']);
        $this->assertSame('กรอกจำนวนผิด', $rev['reason']);

        // ยกเลิกซ้ำ / ยกเลิกรายการยกเลิกไม่ได้ · จ่ายใหม่ได้
        $this->postJson(self::URL."/loans/{$id}/payments/{$pid}/reverse", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(self::URL."/loans/{$id}/payments/{$rev['id']}/reverse", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->pay($id, ['amount' => 250000])->assertCreated()->assertJsonPath('data.repaid', 250000);

        // รายการของสัญญาอื่นยกเลิกผ่านสัญญานี้ไม่ได้
        $other = $this->loan(Group::factory()->create())->json('data.id');
        $this->postJson(self::URL."/loans/{$other}/payments/{$pid}/reverse", ['reason' => 'x'])->assertNotFound();
    }

    public function test_a_contract_is_cancelled_by_an_entry_only_when_no_repayment_is_active(): void
    {
        $this->council();
        $id = $this->loan(Group::factory()->create())->json('data.id');
        $pid = $this->pay($id)->json('data.payments.0.id');

        $this->postJson(self::URL."/loans/{$id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(self::URL."/loans/{$id}/cancel", ['reason' => 'กรอกผิด'])->assertUnprocessable()->assertJsonValidationErrors('reason'); // ยังมีรายการรับคืน

        $this->postJson(self::URL."/loans/{$id}/payments/{$pid}/reverse", ['reason' => 'ยกเลิกเพื่อยกเลิกสัญญา'])->assertCreated();
        $res = $this->postJson(self::URL."/loans/{$id}/cancel", ['reason' => 'บันทึกผิดแก๊ง'])->assertOk();
        $res->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.status_label', 'ยกเลิกแล้ว')->assertJsonPath('data.outstanding', 0)->assertJsonPath('data.cancel_reason', 'บันทึกผิดแก๊ง');

        $this->postJson(self::URL."/loans/{$id}/cancel", ['reason' => 'ซ้ำ'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->pay($id)->assertUnprocessable()->assertJsonValidationErrors('amount');            // สัญญาที่ยกเลิกแล้วรับคืนไม่ได้
        $this->assertSame(1, CouncilLoan::count());                                              // สัญญายังอยู่ ไม่ได้ถูกลบ
    }

    // ───────────────────────── รายการ ตัวกรอง สรุป ─────────────────────────

    public function test_list_is_sorted_by_next_due_date_and_filters_and_summary_work(): void
    {
        $this->council();
        $a = Group::factory()->create(['name' => 'AAA Gang']);
        $b = Group::factory()->family()->create(['name' => 'BBB Family']);
        $c = Group::factory()->create(['name' => 'CCC Gang']);
        $late = $this->loan($a, ['loaned_at' => '2026-10-01', 'due_at' => '2026-10-20', 'late_fee_type' => 'day', 'late_fee_value' => 100])->json('data.id'); // เกินกำหนด
        $soon = $this->loan($b, ['principal' => 500000, 'due_at' => '2026-11-18'])->json('data.id');                                                      // กำลังกู้
        $paid = $this->loan($c, ['principal' => 300000, 'due_at' => '2026-11-12'])->json('data.id');
        $this->pay($paid, ['amount' => 300000, 'paid_at' => '2026-11-12']);                                                                               // คืนครบ
        $this->pay($soon, ['amount' => 100000, 'paid_at' => '2026-11-12']);                                                                               // คืนบางส่วน

        $res = $this->getJson(self::URL.'/loans')->assertOk();
        $this->assertSame([$late, $soon, $paid], array_column($res->json('data'), 'id')); // เกินกำหนดก่อน แล้วใกล้ครบกำหนด แล้วคืนครบท้ายสุด
        $this->assertSame(['overdue', 'partial', 'paid'], array_column($res->json('data'), 'status'));
        $this->assertSame(1800000, $res->json('meta.summary.lent'));
        $this->assertSame(3, $res->json('meta.summary.count'));
        $this->assertSame(1, $res->json('meta.summary.overdue_count'));

        $this->getJson(self::URL.'/loans?status=overdue')->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'/loans?status=partial')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $soon);
        $this->getJson(self::URL.'/loans?status=paid')->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'/loans?status=unpaid')->assertJsonCount(2, 'data');
        $this->getJson(self::URL.'/loans?q=AAA')->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'/loans?q='.urlencode('สมชาย'))->assertJsonCount(3, 'data');          // ค้นจากชื่อผู้กู้ได้
        $this->getJson(self::URL.'/loans?status=bogus')->assertUnprocessable();
        $this->assertSame(1800000, $this->getJson(self::URL.'/loans?status=paid')->json('meta.summary.lent')); // การ์ดสรุปไม่ขึ้นกับตัวกรอง

        // สัญญาที่ยกเลิกไม่นับในการ์ดสรุป และขึ้นท้ายรายการ
        $x = $this->loan(Group::factory()->create(), ['principal' => 200000])->json('data.id');
        $this->postJson(self::URL."/loans/{$x}/cancel", ['reason' => 'ผิด'])->assertOk();
        $res = $this->getJson(self::URL.'/loans')->assertOk();
        $this->assertSame(1800000, $res->json('meta.summary.lent'));
        $this->assertSame($x, $res->json('data.3.id'));
        $this->getJson(self::URL.'/loans?status=cancelled')->assertJsonCount(1, 'data');
    }

    // ───────────────────────── เชื่อมสรุปการเงิน / กองทุน ─────────────────────────

    public function test_loans_repayments_and_corrections_flow_into_the_finance_summary(): void
    {
        $this->council();
        $g = Group::factory()->create(['name' => 'AAA Gang']);
        $id = $this->loan($g)->json('data.id');                                    // รายจ่าย 1,000,000 (10 พ.ย.)
        $pid = $this->pay($id)->json('data.payments.0.id');                        // รายรับ 400,000 (12 พ.ย.)

        $d = $this->getJson(self::URL.'/summary?from=2026-11-01&to=2026-11-15')->assertOk()->json('data');
        $this->assertSame(400000, $d['income']);
        $this->assertSame(1000000, $d['expense']);
        $this->assertSame(-600000, $d['balance']);

        $rows = $this->getJson(self::URL.'/entries?category=loan&from=2026-11-01&to=2026-11-15')->json('data');
        $this->assertSame('รับคืนเงินกู้ · AAA Gang', $rows[0]['title']);
        $this->assertNull($rows[0]['entry_id']);
        $this->assertSame($id, $rows[0]['loan_id']);

        // ยกเลิกรายการรับคืน = รายจ่ายหักล้าง (รายการเดิมยังอยู่ในสรุป) → สุทธิรายรับ 0
        $this->postJson(self::URL."/loans/{$id}/payments/{$pid}/reverse", ['reason' => 'ผิด'])->assertCreated();
        $d = $this->getJson(self::URL.'/summary?from=2026-11-01&to=2026-11-15')->json('data');
        $this->assertSame(400000, $d['income']);
        $this->assertSame(1400000, $d['expense']);
        $this->assertSame(-1000000, $d['balance']);

        // ยกเลิกสัญญา = รายรับคืนยอดกู้ → กองทุนกลับมาเท่าเดิม
        $this->postJson(self::URL."/loans/{$id}/cancel", ['reason' => 'ผิด'])->assertOk();
        $this->assertSame(0, $this->getJson(self::URL.'/summary?from=2026-11-01&to=2026-11-15')->json('data.balance'));
        $titles = array_column($this->getJson(self::URL.'/entries?category=loan&from=2026-11-01&to=2026-11-15')->json('data'), 'title');
        $this->assertContains('ยกเลิกสัญญาเงินกู้ · AAA Gang', $titles);
        $this->assertContains('ยกเลิกรับคืนเงินกู้ · AAA Gang', $titles);
        $this->assertStringContainsString('เงินกู้ #'.$id, $this->get(self::URL.'/export?from=2026-11-01&to=2026-11-15')->streamedContent());
    }

    public function test_the_fund_balance_reflects_income_and_loans(): void
    {
        $this->council();
        $this->postJson(self::URL.'/records', ['type' => 'income', 'spent_at' => '2026-11-10', 'amount' => 3000000, 'category' => 'fund', 'title' => 'เงินเข้ากองทุน'])->assertCreated();

        $this->assertSame(3000000, $this->getJson(self::URL.'/loans')->json('meta.central.balance'));
        $id = $this->loan(Group::factory()->create())->assertCreated()->assertJsonPath('meta.central.balance', 2000000)->json('data.id'); // เบิก 1,000,000 ออกจากกองทุน
        $this->pay($id)->assertJsonPath('meta.central.balance', 2400000);                                                                 // รับคืนเข้ากองทุน
    }

    public function test_a_dissolved_group_keeps_its_contract_visible(): void
    {
        $this->council();
        $g = Group::factory()->create(['name' => 'GONE Gang']);
        $this->loan($g);
        $g->delete();

        $row = $this->getJson(self::URL.'/loans')->assertOk()->json('data.0');
        $this->assertSame('GONE Gang', $row['group']['name']);
        $this->assertTrue($row['group']['deleted']);
    }

    // ───────────────────────── แจ้งเตือน + ข้อความทวง ─────────────────────────

    public function test_overdue_and_due_soon_contracts_appear_in_alerts_with_a_reminder_text_for_the_top_roles_only(): void
    {
        $this->council(Role::President);
        $g1 = Group::factory()->create(['name' => 'Ravens']);
        $g2 = Group::factory()->family()->create(['name' => 'ตระกูลสิงห์']);
        $g3 = Group::factory()->create(['name' => 'FarAway']);
        $g4 = Group::factory()->create(['name' => 'Done']);
        $late = $this->loan($g1, ['borrower_name' => 'นที วรรณ', 'principal' => 300000, 'loaned_at' => '2026-10-01', 'due_at' => '2026-11-10', 'late_fee_type' => 'day', 'late_fee_value' => 500])->json('data.id'); // เกิน 5 วัน
        $this->loan($g2, ['borrower_name' => 'ธีรภัทร', 'principal' => 200000, 'due_at' => '2026-11-17']);   // เหลือ 2 วัน
        $this->loan($g3, ['due_at' => '2026-11-25']);                                                       // ยังไกล
        $paid = $this->loan($g4, ['principal' => 100000, 'due_at' => '2026-11-12'])->json('data.id');
        $this->pay($paid, ['amount' => 100000, 'paid_at' => '2026-11-12']);

        $all = collect($this->getJson('/api/council/alerts')->assertOk()->json('data'))->filter(fn ($a) => str_starts_with($a['type'], 'loan_'));
        $alerts = $all->whereIn('type', ['loan_overdue', 'loan_due_soon'])->values();
        $this->assertCount(2, $alerts);
        // ยังไม่ถึงกำหนดแต่ยังไม่มีการคืนเงิน ขึ้นเป็น "ทบทวน" · สัญญาที่คืนครบแล้วไม่ขึ้น
        $far = $all->firstWhere('type', 'loan_pending');
        $this->assertSame('low', $far['severity']);
        $this->assertStringContainsString('FarAway', $far['title']);
        $this->assertStringContainsString('ยังไม่มีการคืนเงิน', $far['title']);
        $this->assertCount(3, $all);
        $this->assertStringNotContainsString('Done', $all->pluck('title')->implode(' '));
        // คืนบางส่วนแล้ว: ข้อความเปลี่ยนเป็น "ยังคืนไม่ครบ" และแสดงยอดที่คืนแล้ว
        $farId = CouncilLoan::query()->where('group_id', $g3->id)->value('id');
        $this->pay($farId, ['amount' => 400000, 'paid_at' => '2026-11-12'])->assertCreated();
        $far = collect($this->getJson('/api/council/alerts')->json('data'))->firstWhere('type', 'loan_pending');
        $this->assertStringContainsString('ยังคืนไม่ครบ', $far['title']);
        $this->assertStringContainsString('คืนแล้ว 400,000', $far['detail']);
        $over = $alerts->firstWhere('type', 'loan_overdue');
        $this->assertSame('high', $over['severity']);
        $this->assertStringContainsString('เกินกำหนด 5 วัน', $over['title']);
        $this->assertStringContainsString('นที วรรณ', $over['copy_text']);
        $this->assertStringContainsString('Ravens', $over['copy_text']);
        $this->assertStringContainsString('WV-LN-'.str_pad((string) $late, 6, '0', STR_PAD_LEFT), $over['copy_text']);
        $this->assertStringContainsString('302,500', $over['copy_text']);         // 300,000 + ค่าปรับ 5 x 500
        $this->assertStringContainsString('ค่าปรับ 2,500', $over['copy_text']);
        $this->assertStringContainsString('10 พ.ย. 2569', $over['copy_text']);
        $soon = $alerts->firstWhere('type', 'loan_due_soon');
        $this->assertSame('medium', $soon['severity']);
        $this->assertStringContainsString('ใกล้ครบกำหนดอีก 2 วัน', $soon['title']);

        // สภาทั่วไปไม่เห็นแจ้งเตือนเงินกู้ (ข้อมูลการเงินเฉพาะประธาน/รอง)
        $this->council(Role::Member);
        $types = array_column($this->getJson('/api/council/alerts')->assertOk()->json('data'), 'type');
        $this->assertNotContains('loan_overdue', $types);
        $this->assertNotContains('loan_due_soon', $types);
        $this->assertNotContains('loan_pending', $types);

        // สัญญาที่ยกเลิกแล้วไม่แจ้งเตือน
        $this->council(Role::VicePresident);
        $this->postJson(self::URL."/loans/{$late}/cancel", ['reason' => 'ผิด'])->assertOk();
        $this->assertCount(1, collect($this->getJson('/api/council/alerts')->json('data'))->whereIn('type', ['loan_overdue', 'loan_due_soon']));
        $this->assertCount(2, collect($this->getJson('/api/council/alerts')->json('data'))->filter(fn ($a) => str_starts_with($a['type'], 'loan_') && ! str_contains($a['title'], 'เกินกำหนด')));
    }
}
