<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TransactionKind;
use App\Enums\TransactionStatus;
use App\Models\DutyShift;
use App\Models\Group;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** รายรับ-รายจ่าย / ค่าบริการของสภา: รายรับมาจากค่าบริการของธุรกรรมที่อนุมัติแล้ว รายจ่ายกรอกเอง (รองประธานขึ้นไป) */
class FinanceTest extends TestCase
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

    private function tx(TransactionKind $kind, int $fee, string $at, TransactionStatus $status = TransactionStatus::Approved): Transaction
    {
        $user = User::factory()->role(Role::Member)->create();
        $t = Transaction::create([
            'number' => 'WC-'.fake()->unique()->numerify('########-######'), 'group_id' => Group::factory()->create()->id, 'kind' => $kind,
            'payload' => ['fee' => ['total' => $fee, 'items' => []]], 'status' => $status,
            'requested_by' => $user->id, 'requested_role' => 'member', 'reviewed_at' => $status === TransactionStatus::Approved ? Carbon::parse($at, 'Asia/Bangkok') : null,
        ]);
        $t->forceFill(['created_at' => Carbon::parse($at, 'Asia/Bangkok')])->save();

        return $t;
    }

    private function expense(array $over = [])
    {
        return $this->postJson(self::URL.'/expenses', $over + ['spent_at' => '2026-11-10', 'amount' => 250000, 'category' => 'prize', 'title' => 'รางวัลสตอรี่']);
    }

    public function test_only_vice_president_up_can_use_it(): void
    {
        $this->getJson(self::URL.'/summary')->assertUnauthorized();
        $this->council(Role::Member);
        $this->getJson(self::URL.'/summary')->assertForbidden();
        $this->postJson(self::URL.'/expenses', [])->assertForbidden();

        $this->council(Role::VicePresident);
        $this->getJson(self::URL.'/summary')->assertOk();
    }

    public function test_income_is_the_fee_of_approved_transactions_only_by_approval_date(): void
    {
        $this->council();
        $this->tx(TransactionKind::Rename, 3000000, '2026-11-10 12:00');
        $this->tx(TransactionKind::Recolor, 1000000, '2026-11-12 09:00');
        $this->tx(TransactionKind::Recolor, 1000000, '2026-11-12 10:00', TransactionStatus::Pending);   // ยังไม่อนุมัติ
        $this->tx(TransactionKind::Recolor, 1000000, '2026-11-12 10:00', TransactionStatus::Rejected);  // ไม่อนุมัติ
        $this->tx(TransactionKind::Register, 0, '2026-11-12 11:00');                                    // ค่าบริการ 0
        $this->tx(TransactionKind::Rename, 3000000, '2026-10-20 12:00');                                // นอกช่วง (เดือนก่อน)

        $d = $this->getJson(self::URL.'/summary')->assertOk()->json('data'); // ค่าเริ่มต้น = ตั้งแต่ต้นเดือนถึงวันนี้
        $this->assertSame(4000000, $d['income']);
        $this->assertSame(2, $d['income_count']);
        $this->assertSame(0, $d['expense']);
        $this->assertSame(4000000, $d['balance']);
        $this->assertSame(['rename', 'recolor'], array_column($d['by_kind'], 'key'));
        $this->assertSame('2026-11-01', $d['from']);
        $this->assertSame('2026-11-15', $d['to']);
    }

    public function test_expenses_are_recorded_validated_and_counted(): void
    {
        $this->council();
        $this->expense()->assertCreated()->assertJsonPath('data.amount', 250000)->assertJsonPath('data.category_label', 'รางวัล / ของรางวัล');
        $this->expense(['spent_at' => '2026-11-12', 'amount' => 50000, 'category' => 'event', 'title' => 'จัดงาน'])->assertCreated();

        $this->expense(['amount' => 0])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->expense(['amount' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->expense(['category' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->expense(['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->expense(['spent_at' => '2026-11-16'])->assertUnprocessable()->assertJsonValidationErrors('spent_at'); // อนาคต
        $this->expense(['spent_at' => '10/11/2026'])->assertUnprocessable()->assertJsonValidationErrors('spent_at');

        $d = $this->getJson(self::URL.'/summary')->json('data');
        $this->assertSame(300000, $d['expense']);
        $this->assertSame(-300000, $d['balance']);
        $this->assertSame(['prize', 'event'], array_column($d['by_category'], 'key'));
    }

    public function test_expense_can_be_updated_and_deleted(): void
    {
        $this->council();
        $id = $this->expense()->json('data.expense_id');

        $this->putJson(self::URL."/expenses/{$id}", ['spent_at' => '2026-11-11', 'amount' => 400000, 'category' => 'other', 'title' => 'แก้แล้ว', 'note' => 'หมายเหตุ'])
            ->assertOk()->assertJsonPath('data.amount', 400000)->assertJsonPath('data.note', 'หมายเหตุ');
        $this->assertSame(400000, $this->getJson(self::URL.'/summary')->json('data.expense'));

        $this->deleteJson(self::URL."/expenses/{$id}")->assertOk();
        $this->assertSame(0, $this->getJson(self::URL.'/summary')->json('data.expense'));
        $this->deleteJson(self::URL."/expenses/{$id}")->assertNotFound();
        $this->assertSoftDeleted('council_expenses', ['id' => $id]);
    }

    public function test_entries_merge_income_and_expense_with_filters_and_paging(): void
    {
        $this->council();
        $this->tx(TransactionKind::Rename, 3000000, '2026-11-10 12:00');
        $this->tx(TransactionKind::Recolor, 1000000, '2026-11-13 12:00');
        $this->expense();
        $this->expense(['spent_at' => '2026-11-14', 'title' => 'ค่าจัดงานพิเศษ', 'category' => 'event', 'amount' => 70000]);

        $all = $this->getJson(self::URL.'/entries')->assertOk()->json();
        $this->assertSame(4, $all['meta']['total']);
        $this->assertSame(4000000, $all['meta']['income']);
        $this->assertSame(320000, $all['meta']['expense']);
        $dates = array_column($all['data'], 'date');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates); // ใหม่สุดก่อน

        $this->getJson(self::URL.'/entries?type=income')->assertJsonCount(2, 'data');
        $this->getJson(self::URL.'/entries?type=expense&category=event')->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'/entries?category=rename')->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'/entries?q='.urlencode('จัดงาน'))->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'/entries?from=2026-11-13&to=2026-11-14')->assertJsonCount(2, 'data');
        $this->getJson(self::URL.'/entries?per_page=3&page=2')->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
        $this->getJson(self::URL.'/entries?from=2026-11-14&to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_day_boundaries_follow_thai_time(): void
    {
        $this->council();
        $this->tx(TransactionKind::Rename, 1000000, '2026-11-02 00:30'); // ตี 0:30 ไทย = 17:30 UTC ของวันก่อน ต้องนับเป็นวันที่ 2
        $this->assertSame(0, $this->getJson(self::URL.'/summary?from=2026-11-01&to=2026-11-01')->json('data.income'));
        $this->assertSame(1000000, $this->getJson(self::URL.'/summary?from=2026-11-02&to=2026-11-02')->json('data.income'));
    }

    public function test_export_is_a_csv_with_the_filtered_rows(): void
    {
        $this->council();
        $this->tx(TransactionKind::Rename, 3000000, '2026-11-10 12:00');
        $this->expense();

        $res = $this->get(self::URL.'/export?from=2026-11-01&to=2026-11-15')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));
        $this->assertStringContainsString('finance-2026-11-01_2026-11-15.csv', $res->headers->get('content-disposition'));
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('รายรับ (WVDL)', $csv);
        $this->assertStringContainsString('3000000', $csv);
        $this->assertStringContainsString('รางวัลสตอรี่', $csv);
        $this->assertSame(3, count(array_filter(explode("\n", trim($csv)))));  // หัวตาราง + 2 รายการ
    }
}
