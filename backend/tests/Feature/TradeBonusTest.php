<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CouncilTrade;
use App\Models\DutyShift;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ซื้อ-ขายกับแก๊ง/ครอบครัว ในหน้าการเงิน (ประธานและรองประธาน) — โบนัสเวรอยู่ที่ PayRoundTest */
class TradeBonusTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/council/finance';

    protected function setUp(): void
    {
        parent::setUp();
        config(['council.duty.enforced' => true, 'council.duty.exempt_roles' => [], 'council.duty.max_hours' => 12, 'council.duty.heartbeat_grace_minutes' => 10]);
        // พุธ 18 พ.ย. 2026 20:00 เวลาไทย → สัปดาห์ล่าสุดที่จบแล้วคือจันทร์ 9 พ.ย. – อาทิตย์ 15 พ.ย.
        Carbon::setTestNow(Carbon::parse('2026-11-18 20:00:00', 'Asia/Bangkok'));
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

    private function trade(Group $g, array $over = [])
    {
        return $this->postJson(self::URL.'/trades', $over + ['group_id' => $g->id, 'direction' => 'sell', 'item' => 'ชุดสูท', 'quantity' => 3, 'unit_price' => 500000, 'traded_at' => '2026-11-10', 'note' => 'ขายให้ทีม']);
    }

    // ───────────────────────── ซื้อ-ขาย ─────────────────────────

    public function test_only_president_and_vice_president_can_use_trades(): void
    {
        $g = Group::factory()->create();
        $this->getJson(self::URL.'/trades')->assertUnauthorized();
        foreach ([Role::Member, Role::Secretary, Role::Gang] as $role) {
            $this->council($role);
            foreach (['/trades', '/bonus'] as $path) {
                $this->getJson(self::URL.$path)->assertForbidden();
            }
            $this->trade($g)->assertForbidden();
        }
        foreach ([Role::VicePresident, Role::President] as $role) {
            $this->council($role);
            $this->getJson(self::URL.'/trades')->assertOk();
            $this->getJson(self::URL.'/bonus')->assertOk();
        }
    }

    public function test_trade_total_is_computed_by_the_server_and_validated(): void
    {
        $this->council();
        $g = Group::factory()->create();

        $this->trade($g, ['amount' => 1])->assertCreated()->assertJsonPath('data.amount', 1500000)->assertJsonPath('data.direction_label', 'สภาขาย');
        $this->trade($g, ['direction' => 'buy'])->assertCreated()->assertJsonPath('data.direction_label', 'สภาซื้อ');

        $gone = Group::factory()->create();
        $gone->delete();
        $this->trade($gone)->assertUnprocessable()->assertJsonValidationErrors('group_id');
        $this->trade($g, ['direction' => 'swap'])->assertUnprocessable()->assertJsonValidationErrors('direction');
        $this->trade($g, ['item' => ''])->assertUnprocessable()->assertJsonValidationErrors('item');
        $this->trade($g, ['quantity' => 0])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->trade($g, ['unit_price' => 0])->assertUnprocessable()->assertJsonValidationErrors('unit_price');
        $this->trade($g, ['unit_price' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('unit_price');
        $this->trade($g, ['traded_at' => '2026-11-19'])->assertUnprocessable()->assertJsonValidationErrors('traded_at');
        $this->trade($g, ['quantity' => 1000000, 'unit_price' => 999999999999])->assertUnprocessable()->assertJsonValidationErrors('unit_price'); // ยอดรวมเกินขีดจำกัด
    }

    public function test_trade_list_filters_summary_update_and_delete(): void
    {
        $this->council();
        $a = Group::factory()->create(['name' => 'AAA Gang']);
        $b = Group::factory()->family()->create(['name' => 'BBB Family']);
        $sell = $this->trade($a)->json('data.id');                                                          // ขาย 1,500,000
        $this->trade($b, ['direction' => 'buy', 'item' => 'วัตถุดิบ', 'quantity' => 2, 'unit_price' => 100000]); // ซื้อ 200,000

        $res = $this->getJson(self::URL.'/trades')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['sold' => 1500000, 'bought' => 200000, 'net' => 1300000], array_intersect_key($res->json('meta.summary'), array_flip(['sold', 'bought', 'net'])));
        $this->getJson(self::URL.'/trades?direction=buy')->assertJsonCount(1, 'data')->assertJsonPath('meta.summary.sold', 1500000); // การ์ดสรุปไม่ขึ้นกับตัวกรอง
        $this->getJson(self::URL.'/trades?q=AAA')->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'/trades?q='.urlencode('วัตถุดิบ'))->assertJsonCount(1, 'data');
        $this->getJson(self::URL."/trades?group_id={$b->id}")->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'/trades?direction=bogus')->assertUnprocessable();

        $this->putJson(self::URL."/trades/{$sell}", ['direction' => 'sell', 'item' => 'ชุดสูท', 'quantity' => 4, 'unit_price' => 500000, 'traded_at' => '2026-11-10'])
            ->assertOk()->assertJsonPath('data.amount', 2000000);
        $this->deleteJson(self::URL."/trades/{$sell}")->assertOk();
        $this->assertSoftDeleted('council_trades', ['id' => $sell]);
        $this->getJson(self::URL.'/trades')->assertJsonCount(1, 'data');
        $this->deleteJson(self::URL."/trades/{$sell}")->assertNotFound();
    }

    public function test_trades_flow_into_the_finance_summary(): void
    {
        $this->council();
        $g = Group::factory()->create(['name' => 'AAA Gang']);
        $this->trade($g);                                                                                       // รายรับ 1,500,000 (10 พ.ย.)
        $this->trade($g, ['direction' => 'buy', 'item' => 'วัตถุดิบ', 'quantity' => 2, 'unit_price' => 100000, 'traded_at' => '2026-11-12']); // รายจ่าย 200,000

        $d = $this->getJson(self::URL.'/summary?from=2026-11-01&to=2026-11-18')->assertOk()->json('data');
        $this->assertSame(1500000, $d['income']);
        $this->assertSame(200000, $d['expense']);
        $this->assertSame('trade', $d['by_kind'][0]['key']);
        $this->assertSame('trade', $d['by_category'][0]['key']);

        $rows = $this->getJson(self::URL.'/entries?category=trade&from=2026-11-01&to=2026-11-18')->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame('expense', $rows[0]['type']); // 12 พ.ย. ใหม่กว่า
        $this->assertSame('สภาซื้อ · วัตถุดิบ ×2 · AAA Gang', $rows[0]['title']);
        $this->assertSame('สภาขาย · ชุดสูท ×3 · AAA Gang', $rows[1]['title']);
        $this->assertNull($rows[0]['entry_id']);
    }

    public function test_trade_model_is_available_for_dissolved_groups(): void
    {
        $this->council();
        $g = Group::factory()->create(['name' => 'GONE Gang']);
        $this->trade($g);
        $g->delete();

        $row = $this->getJson(self::URL.'/trades')->assertOk()->json('data.0');
        $this->assertSame('GONE Gang', $row['group']['name']);
        $this->assertTrue($row['group']['deleted']);
        $this->assertSame(1, CouncilTrade::count());
    }
}
