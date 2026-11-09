<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** นำเข้าทะเบียนเดิม (ขึ้นทะเบียนแบบสภา): ผ่อนกฎกว่าขึ้นทะเบียนใหม่ และบันทึกที่มาของข้อมูล */
class GroupImportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/council/groups/import';

    protected function setUp(): void
    {
        parent::setUp();
        config(['council.duty.enforced' => false]);
    }

    private function actAs(Role $role): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        Sanctum::actingAs($u);

        return $u;
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'type' => 'gang',
            'name' => 'Old Gang Name จากที่เก่า',
            'registered_at' => '2026-08-15',
            'source' => 'ย้ายจากเซิร์ฟเวอร์เดิม',
            'leader' => ['ic_name' => 'Boss One', 'discord_id' => '123456789012345678'],
        ];
    }

    public function test_only_council_can_import(): void
    {
        $this->postJson(self::URL, $this->payload())->assertUnauthorized();
        $this->actAs(Role::Gang);
        $this->postJson(self::URL, $this->payload())->assertForbidden();
    }

    public function test_any_council_rank_can_import_with_a_back_dated_registration_and_loose_rules(): void
    {
        $this->actAs(Role::Trainee);

        // ไม่ต้องมีเบอร์สีชุดชั่วคราว/โลโก้ images.wavevycity.com/สมาชิกขั้นต่ำ/ชื่อภาษาไทยได้
        $res = $this->postJson(self::URL, $this->payload([
            'short_name' => 'OLD',
            'logo_url' => 'https://example.com/logo.png',
            'co_leaders' => [['ic_name' => 'Deputy A']],
        ]))->assertCreated();

        $g = Group::where('name', 'Old Gang Name จากที่เก่า')->firstOrFail();
        $this->assertSame('2026-08-15', $g->registered_at->setTimezone('Asia/Bangkok')->toDateString());
        $this->assertNull($g->temp_outfit_color);
        $this->assertFalse((bool) $g->protect_requested);
        $this->assertSame(2, $g->members()->count());
        $this->assertSame(0, $g->members()->where('position', 'member')->count());
        $this->assertSame('leader', $g->members()->orderBy('id')->first()->position->value);
        $this->assertStringContainsString('นำเข้าทะเบียนเดิม', $g->statusHistory()->first()->reason);
        $res->assertJsonPath('data.name', 'Old Gang Name จากที่เก่า');
    }

    public function test_validation_source_date_duplicate_name_and_slots(): void
    {
        $this->actAs(Role::Member);
        Group::factory()->create(['name' => 'Taken']);

        $this->postJson(self::URL, $this->payload(['source' => '']))->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->postJson(self::URL, $this->payload(['registered_at' => now()->addDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('registered_at');
        $this->postJson(self::URL, $this->payload(['name' => 'taken']))->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(self::URL, $this->payload(['logo_url' => 'http://insecure.example/x.png']))->assertUnprocessable()->assertJsonValidationErrors('logo_url');
        $this->postJson(self::URL, $this->payload(['color_hex' => '#FF0000']))->assertUnprocessable()->assertJsonValidationErrors('color_name');
        // ชื่อหัวหน้ากับรองซ้ำกัน
        $this->postJson(self::URL, $this->payload(['co_leaders' => [['ic_name' => 'boss one']]]))->assertUnprocessable()->assertJsonValidationErrors('co_leaders');
    }

    public function test_import_is_recorded_with_its_source_in_the_activity_log(): void
    {
        $this->actAs(Role::Member);
        $this->postJson(self::URL, $this->payload())->assertCreated();

        $this->actAs(Role::Admin);
        $rows = collect($this->getJson('/api/logs?per_page=100')->json('data'));
        $row = $rows->first(fn ($r) => str_contains($r['description'] ?? '', 'นำเข้าทะเบียนเดิม'));
        $this->assertNotNull($row);
        $this->assertContains(['ที่มาของข้อมูล', 'ย้ายจากเซิร์ฟเวอร์เดิม'], $row['details']);
    }
}
