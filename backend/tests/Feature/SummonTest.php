<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CouncilSummon;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ประกาศเรียก: สภา (Member ขึ้นไป) บันทึกการเรียกแก๊ง/ครอบครัวเข้าพบ พร้อมผู้แจ้งเรียก สถานที่ มิติ และเลขเอกสาร */
class SummonTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/council/summons';

    private function actAs(Role $role): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        Sanctum::actingAs($u);

        return $u;
    }

    private function body(Group $called, Group $by, array $over = []): array
    {
        return $over + ['summoned_group_id' => $called->id, 'summoner_group_id' => $by->id, 'reason' => 'ไม่ส่งชุดตามกำหนด', 'place' => '', 'dimension' => 1];
    }

    public function test_only_council_can_use_summons(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $a = Group::factory()->create();
        $b = Group::factory()->create();

        foreach ([Role::Gang, Role::Family] as $role) {
            $this->actAs($role);
            $this->getJson(self::URL)->assertForbidden();
            $this->postJson(self::URL, $this->body($a, $b))->assertForbidden();
        }
        $this->assertSame(0, CouncilSummon::count());
    }

    public function test_summon_is_recorded_with_default_place_and_a_document_number(): void
    {
        $user = $this->actAs(Role::Member);
        $called = Group::factory()->create(['name' => 'Ravens']);
        $by = Group::factory()->family()->create(['name' => 'Moon Family']);

        $res = $this->postJson(self::URL, $this->body($called, $by, ['dimension' => 7]))->assertCreated()
            ->assertJsonPath('data.summoned.name', 'Ravens')->assertJsonPath('data.summoned.kind_label', 'แก๊ง')
            ->assertJsonPath('data.summoner.name', 'Moon Family')->assertJsonPath('data.summoner.kind_label', 'ครอบครัว')
            ->assertJsonPath('data.reason', 'ไม่ส่งชุดตามกำหนด')->assertJsonPath('data.place', 'หน้าที่ทำการสภา')->assertJsonPath('data.dimension', 7);
        $this->assertMatchesRegularExpression('/^WC-CALL-\d{8}-[A-Z0-9]{6}$/', $res->json('data.number'));
        $this->assertSame($user->character_name ?: $user->name, $res->json('data.by'));

        $this->postJson(self::URL, $this->body($called, $by, ['dimension' => 0]))->assertCreated()->assertJsonPath('data.dimension', 0);   // มิติหลัก
        $this->postJson(self::URL, $this->body($called, $by, ['place' => 'โรงแรมกลางเมือง']))->assertCreated()->assertJsonPath('data.place', 'โรงแรมกลางเมือง');
        $this->assertSame(3, CouncilSummon::query()->distinct()->count('number'));
    }

    public function test_the_council_itself_can_be_the_reporter(): void
    {
        $this->actAs(Role::Member);
        $a = Group::factory()->create(['name' => 'Ravens']);

        $res = $this->postJson(self::URL, ['summoned_group_id' => $a->id, 'by_council' => true, 'reason' => 'สภาเรียกเอง', 'dimension' => 0])->assertCreated()
            ->assertJsonPath('data.summoner.name', 'สภา')->assertJsonPath('data.summoner.id', null);
        $this->assertTrue(CouncilSummon::first()->by_council);
        $this->assertNull(CouncilSummon::first()->summoner_group_id);
        $this->assertSame('สภา', $this->getJson(self::URL)->json('data.0.summoner.name'));
        $this->getJson("/api/council/groups/{$a->id}/summons")->assertOk()->assertJsonPath('data.0.role', 'summoned');

        // ไม่ใช่สภา ก็ต้องเลือกกลุ่มผู้แจ้งเรียก
        $this->postJson(self::URL, ['summoned_group_id' => $a->id, 'reason' => 'x', 'dimension' => 0])->assertUnprocessable()->assertJsonValidationErrors('summoner_group_id');
    }

    public function test_validation(): void
    {
        $this->actAs(Role::Member);
        $a = Group::factory()->create();
        $b = Group::factory()->create();
        $gone = Group::factory()->create();
        $gone->delete();

        $this->postJson(self::URL, $this->body($a, $a))->assertUnprocessable()->assertJsonValidationErrors('summoner_group_id');   // คนละกลุ่ม
        $this->postJson(self::URL, $this->body($a, $b, ['summoned_group_id' => $gone->id]))->assertUnprocessable()->assertJsonValidationErrors('summoned_group_id');
        $this->postJson(self::URL, $this->body($a, $b, ['summoner_group_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors('summoner_group_id');
        $this->postJson(self::URL, $this->body($a, $b, ['reason' => '']))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(self::URL, $this->body($a, $b, ['dimension' => -1]))->assertUnprocessable()->assertJsonValidationErrors('dimension');
        $this->postJson(self::URL, $this->body($a, $b, ['dimension' => 11]))->assertUnprocessable()->assertJsonValidationErrors('dimension');
        $this->postJson(self::URL, $this->body($a, $b, ['dimension' => null]))->assertUnprocessable()->assertJsonValidationErrors('dimension');
        $this->postJson(self::URL, $this->body($a, $b, ['place' => str_repeat('ก', 121)]))->assertUnprocessable()->assertJsonValidationErrors('place');
        $this->assertSame(0, CouncilSummon::count());
    }

    public function test_group_sees_summons_where_it_was_called_or_was_the_reporter(): void
    {
        $this->actAs(Role::Member);
        $a = Group::factory()->create();
        $b = Group::factory()->create();
        $c = Group::factory()->create();
        $this->postJson(self::URL, $this->body($a, $b, ['reason' => 'a ถูกเรียก']))->assertCreated();
        $this->postJson(self::URL, $this->body($c, $a, ['reason' => 'a แจ้งเรียก']))->assertCreated();
        $this->postJson(self::URL, $this->body($b, $c, ['reason' => 'ไม่เกี่ยว']))->assertCreated();

        $res = $this->getJson("/api/council/groups/{$a->id}/summons")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['a แจ้งเรียก', 'a ถูกเรียก'], $res->json('data.*.reason'));
        $this->assertSame(['summoner', 'summoned'], $res->json('data.*.role'));
    }

    public function test_list_can_be_filtered_by_thai_day(): void
    {
        $this->actAs(Role::Member);
        $a = Group::factory()->create();
        $b = Group::factory()->create();
        $old = CouncilSummon::create(['number' => 'WC-CALL-OLD', 'summoned_group_id' => $a->id, 'summoner_group_id' => $b->id, 'reason' => 'เมื่อวาน', 'place' => 'x', 'dimension' => 1]);
        $late = CouncilSummon::create(['number' => 'WC-CALL-LATE', 'summoned_group_id' => $a->id, 'summoner_group_id' => $b->id, 'reason' => 'ดึก', 'place' => 'x', 'dimension' => 1]);
        // 23:30 เวลาไทยของวันที่ 10 (= 16:30 UTC) และ 00:10 เวลาไทยของวันที่ 11
        $old->forceFill(['created_at' => Carbon::parse('2026-11-10 23:30', 'Asia/Bangkok')->setTimezone(config('app.timezone'))])->save();
        $late->forceFill(['created_at' => Carbon::parse('2026-11-11 00:10', 'Asia/Bangkok')->setTimezone(config('app.timezone'))])->save();

        $this->getJson(self::URL.'?date=2026-11-10&per_page=200')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.reason', 'เมื่อวาน');
        $this->getJson(self::URL.'?date=2026-11-11')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.reason', 'ดึก');
        $this->getJson(self::URL.'?date=2026-11-12')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL.'?date=11/11/2026')->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_list_is_newest_first_and_paginated(): void
    {
        $this->actAs(Role::Member);
        $a = Group::factory()->create();
        $b = Group::factory()->create();
        foreach (range(1, 8) as $i) {
            $this->postJson(self::URL, $this->body($a, $b, ['reason' => "เหตุผล {$i}"]))->assertCreated();
        }

        $res = $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 8)->assertJsonPath('meta.per_page', 6)->assertJsonCount(6, 'data');
        $this->assertSame('เหตุผล 8', $res->json('data.0.reason'));
        $this->getJson(self::URL.'?per_page=50')->assertJsonCount(8, 'data');
        $this->getJson(self::URL.'?page=2')->assertJsonCount(2, 'data');
    }
}
