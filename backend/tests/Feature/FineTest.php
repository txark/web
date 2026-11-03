<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Group;
use App\Models\GroupRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ประกาศปรับ: แสดงรายการ "บันทึกค่าปรับ" ของทะเบียนรายวัน (บันทึกด้วย groups/{group}/records) */
class FineTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/council/fines';

    private function actAs(Role $role): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        Sanctum::actingAs($u);

        return $u;
    }

    private function recordFine(Group $g, array $over = [])
    {
        return $this->postJson("/api/council/groups/{$g->id}/records", $over + ['kind' => 'fine', 'category' => 'cc', 'amount' => 500000, 'charge' => 'ไม่ส่งชุดตามกำหนด', 'note' => 'ทดสอบ']);
    }

    public function test_only_council_can_see_fine_announcements(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        foreach ([Role::Gang, Role::Family] as $role) {
            $this->actAs($role);
            $this->getJson(self::URL)->assertForbidden();
        }
    }

    public function test_recorded_fines_are_listed_for_the_announcement_and_other_records_are_not(): void
    {
        $this->actAs(Role::Member);
        $g = Group::factory()->create(['name' => 'Wolves']);
        $this->recordFine($g)->assertCreated();
        $this->postJson("/api/council/groups/{$g->id}/records", ['kind' => 'report', 'status' => 'normal'])->assertCreated();

        $res = $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data');
        $this->assertSame('Wolves', $res->json('data.0.group.name'));
        $this->assertSame(500000, $res->json('data.0.amount'));
        $this->assertSame('ไม่ส่งชุดตามกำหนด', $res->json('data.0.charge'));
        $this->assertSame('ทดสอบ', $res->json('data.0.note'));
        $this->assertNotEmpty($res->json('meta.categories'));
    }

    public function test_fine_and_penance_can_be_recorded_together_and_need_at_least_one(): void
    {
        $this->actAs(Role::Member);
        $g = Group::factory()->create();

        $this->recordFine($g, ['penalty_type' => 'both', 'amount' => 500000, 'penance_units' => 2000])->assertCreated()
            ->assertJsonPath('data.data.penalty_type', 'both')->assertJsonPath('data.data.amount', 500000)->assertJsonPath('data.data.penance_units', 2000);
        $this->recordFine($g, ['penalty_type' => 'both', 'amount' => 300000])->assertCreated()->assertJsonPath('data.data.penalty_type', 'fine')->assertJsonPath('data.data.penance_units', null);
        $this->recordFine($g, ['penalty_type' => 'both', 'amount' => null, 'penance_units' => 50])->assertCreated()->assertJsonPath('data.data.penalty_type', 'penance')->assertJsonPath('data.data.amount', null);
        $this->recordFine($g, ['penalty_type' => 'both', 'amount' => null])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $res = $this->getJson(self::URL)->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(['penance', 'fine', 'both'], $res->json('data.*.penalty_type'));
        $this->assertArrayHasKey('fine_rules', $res->json('meta'));
        $this->assertArrayHasKey('fine_sections', $res->json('meta'));
    }

    public function test_penance_announcements_list_only_records_with_penance(): void
    {
        $this->actAs(Role::Member);
        $g = Group::factory()->create(['name' => 'Wolves']);
        $this->recordFine($g, ['charge' => 'ปรับอย่างเดียว'])->assertCreated();
        $this->recordFine($g, ['penalty_type' => 'penance', 'amount' => null, 'penance_units' => 2000, 'charge' => 'บำเพ็ญอย่างเดียว'])->assertCreated();
        $this->recordFine($g, ['penalty_type' => 'both', 'amount' => 500000, 'penance_units' => 100, 'charge' => 'ทั้งสองอย่าง'])->assertCreated();

        $res = $this->getJson('/api/council/penances')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame(['ทั้งสองอย่าง', 'บำเพ็ญอย่างเดียว'], $res->json('data.*.charge'));
        $this->assertSame(100, $res->json('data.0.penance_units'));
        $this->getJson('/api/council/penances?date=2026-01-01')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/council/penances?date=bad')->assertUnprocessable();
    }

    public function test_penance_can_be_marked_served_and_pending_scope_tracks_it(): void
    {
        $this->actAs(Role::Member);
        $g = Group::factory()->create(['name' => 'Wolves']);
        $this->recordFine($g, ['charge' => 'ปรับอย่างเดียว'])->assertCreated();
        $this->recordFine($g, ['penalty_type' => 'penance', 'amount' => null, 'penance_units' => 2000, 'charge' => 'บำเพ็ญ A'])->assertCreated();
        $this->recordFine($g, ['penalty_type' => 'penance', 'amount' => null, 'penance_units' => 100, 'charge' => 'บำเพ็ญ B'])->assertCreated();
        $a = GroupRecord::where('data->charge', 'บำเพ็ญ A')->first();
        $fineOnly = GroupRecord::where('data->charge', 'ปรับอย่างเดียว')->first();

        $this->getJson('/api/council/penances')->assertOk()->assertJsonPath('data.0.served', false);

        $this->postJson("/api/council/penances/{$a->id}/serve")->assertOk()->assertJsonPath('data.served', true)->assertJsonPath('data.served_by', fn ($v) => $v !== null);
        $this->assertNotNull($a->fresh()->data['served_at']);

        // ที่ยังค้างรับโทษ (ทุกวัน) เหลือเฉพาะ B
        $this->assertSame(['บำเพ็ญ B'], $this->getJson('/api/council/penances?scope=pending')->assertOk()->json('data.*.charge'));

        $this->deleteJson("/api/council/penances/{$a->id}/serve")->assertOk()->assertJsonPath('data.served', false);
        $this->assertCount(2, $this->getJson('/api/council/penances?scope=pending')->json('data'));

        // รายการที่ไม่มีโทษบำเพ็ญ (ปรับอย่างเดียว) ใช้ไม่ได้
        $this->postJson("/api/council/penances/{$fineOnly->id}/serve")->assertNotFound();
    }

    public function test_only_council_can_mark_penance_served(): void
    {
        $this->actAs(Role::Member);
        $g = Group::factory()->create();
        $this->recordFine($g, ['penalty_type' => 'penance', 'amount' => null, 'penance_units' => 10, 'charge' => 'x'])->assertCreated();
        $rec = GroupRecord::first();

        $this->actAs(Role::Gang);
        $this->postJson("/api/council/penances/{$rec->id}/serve")->assertForbidden();
    }

    public function test_list_can_be_filtered_by_thai_day(): void
    {
        $this->actAs(Role::Member);
        $g = Group::factory()->create();
        $this->recordFine($g, ['charge' => 'เมื่อวาน'])->assertCreated();
        $this->recordFine($g, ['charge' => 'วันถัดมา'])->assertCreated();
        $old = GroupRecord::where('data->charge', 'เมื่อวาน')->first();
        $old->forceFill(['created_at' => Carbon::parse('2026-11-10 23:30', 'Asia/Bangkok')->setTimezone(config('app.timezone'))])->save();
        $new = GroupRecord::where('data->charge', 'วันถัดมา')->first();
        $new->forceFill(['created_at' => Carbon::parse('2026-11-11 00:10', 'Asia/Bangkok')->setTimezone(config('app.timezone'))])->save();

        $this->getJson(self::URL.'?date=2026-11-10')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.charge', 'เมื่อวาน');
        $this->getJson(self::URL.'?date=2026-11-11')->assertOk()->assertJsonPath('data.0.charge', 'วันถัดมา');
        $this->getJson(self::URL.'?date=2026-11-12')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL.'?date=11/11/2026')->assertUnprocessable()->assertJsonValidationErrors('date');
    }
}
