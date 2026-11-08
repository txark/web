<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AppSetting;
use App\Models\Rule;
use App\Models\User;
use App\Services\OfficialRulesImporter;
use Database\Seeders\RuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** จัดการกฎ (รองประธานขึ้นไป): แก้/เพิ่ม/ซ่อนกฎ และต้องไม่ถูกทับตอนซิงก์จากเว็บทางการ */
class RuleAdminTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/council/rules';

    protected function setUp(): void
    {
        parent::setUp();
        config(['council.duty.enforced' => false]);
        $this->seed(RuleSeeder::class);
    }

    private function actAs(Role $role): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        Sanctum::actingAs($u);

        return $u;
    }

    /** จำลองซิงก์รอบถัดไป: กฎของเว็บทางการ (ไฟล์ snapshot) + ข้อที่แก้ทับ → apply */
    private function sync(): void
    {
        $importer = new OfficialRulesImporter;
        $importer->apply(OfficialRulesImporter::withOverrides(OfficialRulesImporter::snapshot()['sections']));
    }

    public function test_only_vice_president_and_up_can_manage_rules(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actAs(Role::Member);
        $this->getJson(self::URL)->assertForbidden();
        $this->actAs(Role::VicePresident);
        $this->getJson(self::URL)->assertOk()->assertJsonStructure(['data', 'meta' => ['auto_sync', 'version', 'edited', 'hidden']]);
    }

    public function test_an_edit_to_an_official_rule_survives_the_next_sync(): void
    {
        $this->actAs(Role::President);
        $rule = Rule::where('code', 'CC-001')->firstOrFail();

        $this->putJson(self::URL.'/'.$rule->id, ['title' => 'ชื่อใหม่', 'body' => 'เนื้อหาใหม่', 'note' => 'หมายเหตุใหม่', 'penalty' => 'ปรับ 1 WVDL'])
            ->assertOk()->assertJsonPath('data.edited', true);

        $this->sync();

        $after = Rule::where('code', 'CC-001')->firstOrFail();
        $this->assertSame('ชื่อใหม่', $after->title);
        $this->assertSame('เนื้อหาใหม่', $after->body);
        $this->assertSame('ปรับ 1 WVDL', $after->penalty);
    }

    public function test_a_new_local_rule_is_kept_after_sync_and_a_hidden_one_stays_hidden(): void
    {
        $this->actAs(Role::VicePresident);

        $new = $this->postJson(self::URL, ['section' => 'visit', 'title' => 'ข้อใหม่ของสภา', 'body' => 'รายละเอียด'])
            ->assertCreated()->assertJsonPath('data.is_local', true)->json('data');
        $this->assertMatchesRegularExpression('/^VISIT-L1$/', $new['code']);

        $official = Rule::where('code', 'VISIT-001')->firstOrFail();
        $this->deleteJson(self::URL.'/'.$official->id)->assertOk()->assertJsonPath('data.hidden', true);

        $this->sync();

        $this->assertTrue(Rule::where('code', 'VISIT-L1')->where('is_local', true)->exists());
        $this->assertFalse(Rule::where('code', 'VISIT-001')->firstOrFail()->is_published);

        // ข้อที่ซ่อนไม่โผล่ในหน้ากฎสาธารณะ แต่ข้อที่เพิ่มเองโผล่
        $codes = collect($this->getJson('/api/public/rules')->json('data'))->flatMap(fn ($s) => collect($s['rules'])->pluck('code'));
        $this->assertNotContains('VISIT-001', $codes);
        $this->assertContains('VISIT-L1', $codes);
    }

    public function test_restore_brings_back_the_official_text(): void
    {
        $this->actAs(Role::President);
        $rule = Rule::where('code', 'CC-002')->firstOrFail();
        $original = $rule->title;

        $this->putJson(self::URL.'/'.$rule->id, ['title' => 'แก้เล่น'])->assertOk();
        $this->postJson(self::URL.'/'.$rule->id.'/restore')->assertOk()->assertJsonPath('data.edited', false);

        $this->assertSame($original, $rule->fresh()->title);
        $this->sync();
        $this->assertSame($original, Rule::where('code', 'CC-002')->first()->title);
    }

    public function test_the_auto_sync_switch_is_stored_and_honoured_by_the_command(): void
    {
        $this->actAs(Role::President);
        $this->putJson(self::URL.'/settings', ['auto_sync' => false])->assertOk()->assertJsonPath('data.auto_sync', false);
        $this->assertFalse(AppSetting::rulesAutoSync());

        $this->artisan('rules:pull-official --apply --auto')->expectsOutputToContain('ปิดซิงก์กฎอัตโนมัติไว้')->assertSuccessful();

        $this->putJson(self::URL.'/settings', ['auto_sync' => true])->assertOk();
        $this->assertTrue(AppSetting::rulesAutoSync());
    }
}
