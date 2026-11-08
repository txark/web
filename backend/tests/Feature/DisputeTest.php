<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Dispute;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ระบบบันทึกข้อพิพาท: สภาทุกยศดูได้ · บันทึกต้องเข้าเวร · ลบเฉพาะประธาน · หลักฐานรูป/วิดีโอ */
class DisputeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/council/disputes';

    private function actAs(Role $role): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        Sanctum::actingAs($u);

        return $u;
    }

    private function payload(array $extra = []): array
    {
        $council = User::factory()->role(Role::Member)->create();

        return $extra + [
            'title' => 'ทะเลาะกันหน้าธนาคาร',
            'occurred_at' => '2026-11-09T00:23',
            'details' => 'รายละเอียดเหตุการณ์',
            'group_ids' => [Group::factory()->create()->id],
            'council_ids' => [$council->id],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['council.duty.enforced' => false]);
        Storage::fake('local');
    }

    public function test_gang_and_visitors_cannot_see_disputes(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actAs(Role::Gang);
        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, [])->assertForbidden();
    }

    public function test_every_council_rank_and_admin_can_view(): void
    {
        foreach ([Role::Trainee, Role::Member, Role::President, Role::Admin] as $role) {
            $this->actAs($role);
            $this->getJson(self::URL)->assertOk()->assertJsonStructure(['data', 'meta' => ['open'], 'statuses']);
        }
    }

    public function test_create_with_parties_council_and_evidence(): void
    {
        $this->actAs(Role::Trainee);

        $res = $this->post(self::URL, $this->payload([
            'images' => [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.jpg')],
            'videos' => [UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4')],
        ]), ['Accept' => 'application/json'])->assertCreated();

        $res->assertJsonPath('data.status', 'open')->assertJsonCount(3, 'data.files')->assertJsonCount(1, 'data.groups')->assertJsonCount(1, 'data.council');
        $this->assertMatchesRegularExpression('/^WC-DSP-\d{8}-[A-Z0-9]{6}$/', $res->json('data.number'));

        // เปิดไฟล์หลักฐานได้เมื่อมีสิทธิ์
        $url = $res->json('data.files.0.url');
        $this->get($url)->assertOk();
        $this->actAs(Role::Gang);
        $this->get($url)->assertForbidden();
    }

    public function test_parties_and_council_are_required_and_evidence_types_checked(): void
    {
        $this->actAs(Role::Member);

        $this->postJson(self::URL, ['title' => 'x', 'occurred_at' => '2026-11-09T00:23', 'details' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['group_ids', 'council_ids']);

        $this->post(self::URL, $this->payload(['images' => [UploadedFile::fake()->create('a.exe', 10)]]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['images.0']);
    }

    public function test_writing_needs_duty_but_viewing_does_not(): void
    {
        config(['council.duty.enforced' => true, 'council.duty.exempt_roles' => []]);
        $this->actAs(Role::Member);

        $this->getJson(self::URL)->assertOk();
        $this->postJson(self::URL, $this->payload())->assertForbidden()->assertJsonPath('code', 'off_duty');
    }

    public function test_update_status_and_only_president_can_delete(): void
    {
        $this->actAs(Role::Member);
        $id = $this->postJson(self::URL, $this->payload())->assertCreated()->json('data.id');

        $this->putJson(self::URL.'/'.$id, ['status' => 'closed'])->assertOk()->assertJsonPath('data.status_label', 'จบแล้ว');
        $this->getJson(self::URL.'?status=open')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL.'?q=ธนาคาร')->assertOk()->assertJsonPath('meta.total', 1);

        $this->deleteJson(self::URL.'/'.$id)->assertForbidden();
        $this->actAs(Role::VicePresident);
        $this->deleteJson(self::URL.'/'.$id)->assertForbidden();
        $this->actAs(Role::President);
        $this->deleteJson(self::URL.'/'.$id)->assertOk();
        $this->assertSoftDeleted('disputes', ['id' => $id]);
    }

    public function test_changes_are_written_to_the_activity_log(): void
    {
        $this->actAs(Role::Member);
        $this->postJson(self::URL, $this->payload())->assertCreated();

        $this->actAs(Role::Admin);
        $rows = collect($this->getJson('/api/logs?per_page=100')->json('data'));
        $row = $rows->first(fn ($r) => ($r['subject']['label'] ?? null) === 'ข้อพิพาท');
        $this->assertNotNull($row);
        $this->assertSame(1, Dispute::count());
    }

    public function test_form_options_list_groups_and_council_only(): void
    {
        $this->actAs(Role::Trainee);
        Group::factory()->create(['name' => 'AAA']);
        User::factory()->role(Role::Gang)->create();

        $res = $this->getJson(self::URL.'/options')->assertOk();
        $this->assertSame(1, count($res->json('groups')));
        foreach ($res->json('council') as $c) {
            $this->assertNotEmpty($c['role_label']);
        }
    }
}
