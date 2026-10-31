<?php

namespace Tests\Feature;

use App\Enums\MemberPosition;
use App\Enums\Role;
use App\Models\DutyShift;
use App\Models\Group;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ธุรกรรมชุด: "ลงชุด/แก้ไขชุด" (ลงเพิ่ม + เปลี่ยน/แทน ในธุรกรรมเดียว ไฟล์ .ZIP ไฟล์เดียว) และ "แก้จำนวนชุด" */
class OutfitTransactionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/council/transactions';

    private const ZIP = "PK\x03\x04\0\0\0\0";

    protected function setUp(): void
    {
        parent::setUp();
        config(['council.duty.enforced' => true, 'council.duty.exempt_roles' => []]);
        Storage::fake('public');
        Storage::fake('local');

        $u = User::factory()->role(Role::Member)->loggedIn()->create();
        DutyShift::create(['user_id' => $u->id, 'started_at' => now()->subMinutes(20), 'last_seen_at' => now()]);
        Sanctum::actingAs($u);
    }

    private function group(string $type = 'gang', array $attrs = []): Group
    {
        $g = Group::factory()->state(array_merge(['type' => $type, 'registered_at' => now()->subDays(100)], $attrs))->create();
        $g->members()->create(['character_name' => 'Boss One', 'position' => MemberPosition::Leader, 'joined_at' => now()->subDays(50)]);

        return $g;
    }

    private function zip(string $name = 'outfit.zip'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::ZIP);
    }

    private function send(string $kind, Group $g, array $payload)
    {
        return $this->post(self::URL, ['kind' => $kind, 'group_id' => $g->id, 'payload' => $payload], ['Accept' => 'application/json']);
    }

    /** ธุรกรรมชุดรวม: แนบไฟล์ให้เสมอ */
    private function outfit(Group $g, array $over)
    {
        return $this->send('outfit', $g, $over + ['outfit_zip' => $this->zip()]);
    }

    private function reviewer(): void
    {
        $r = User::factory()->role(Role::Member)->loggedIn()->create();
        DutyShift::create(['user_id' => $r->id, 'started_at' => now()->subMinutes(20), 'last_seen_at' => now()]);
        Sanctum::actingAs($r);
    }

    // ───────── ส่วนลงเพิ่ม ─────────

    public function test_outfit_requires_a_zip_file(): void
    {
        $g = $this->group();

        $this->send('outfit', $g, ['add' => ['suit' => 1]])->assertUnprocessable()->assertJsonValidationErrors('outfit_zip');
        $this->send('outfit', $g, ['add' => ['suit' => 1], 'outfit_zip' => UploadedFile::fake()->createWithContent('x.zip', 'plain')])->assertUnprocessable()->assertJsonValidationErrors('outfit_zip');
        $this->assertNull($g->fresh()->outfit_counts);
    }

    public function test_outfit_needs_something_to_add_or_replace(): void
    {
        $g = $this->group();

        $this->outfit($g, [])->assertUnprocessable()->assertJsonValidationErrors('add');
        $this->outfit($g, ['add' => ['suit' => 0, 'polo' => '']])->assertUnprocessable()->assertJsonValidationErrors('add');
    }

    public function test_add_only_increases_counts_and_keeps_the_main_zip(): void
    {
        Storage::disk('local')->put('groups/outfits/main.zip', 'main');
        $g = $this->group('gang', ['outfit_counts' => ['suit' => 1, 'armor' => 1], 'outfit_zip_path' => 'groups/outfits/main.zip']);

        $res = $this->outfit($g, ['add' => ['suit' => 1, 'hoodie' => 1, 'gun' => '']])->assertCreated()
            ->assertJsonPath('data.kind', 'outfit')->assertJsonPath('data.status', 'approved');

        $this->assertSame(['suit' => 2, 'armor' => 1, 'hoodie' => 1], $g->fresh()->outfit_counts);
        // มีแต่ส่วนลงเพิ่ม: ไฟล์เก็บไว้กับธุรกรรม ไม่แทนไฟล์ชุดหลัก
        $this->assertSame('groups/outfits/main.zip', $g->fresh()->outfit_zip_path);
        $this->assertArrayNotHasKey('outfit_zip_path', $res->json('data.payload'));
        $this->assertStringContainsString('ลงเพิ่ม Suit 1, Hoodie 1', $res->json('data.summary'));
    }

    public function test_add_limits_and_family_types(): void
    {
        $g = $this->group('gang', ['outfit_counts' => ['suit' => 1, 'polo' => 1]]);

        $this->outfit($g, ['add' => ['suit' => 2]])->assertUnprocessable()->assertJsonValidationErrors('add.suit');   // 1 + 2 > 2
        $this->outfit($g, ['add' => ['polo' => 1]])->assertUnprocessable()->assertJsonValidationErrors('add.polo');   // ครบลิมิตแล้ว
        $this->outfit($g, ['add' => ['suit' => -1]])->assertUnprocessable()->assertJsonValidationErrors('add.suit');
        $this->assertSame(['suit' => 1, 'polo' => 1], $g->fresh()->outfit_counts);

        $family = $this->group('family');
        $this->outfit($family, ['add' => ['suit' => 1]])->assertUnprocessable()->assertJsonValidationErrors('add.suit');
        $this->outfit($family, ['add' => ['polo' => 1]])->assertCreated();
    }

    // ───────── ส่วนเปลี่ยน/แทน ─────────

    public function test_replace_only_swaps_the_main_zip_and_sets_counts(): void
    {
        Storage::disk('local')->put('groups/outfits/old.zip', 'old');
        $g = $this->group('gang', ['outfit_zip_path' => 'groups/outfits/old.zip', 'outfit_counts' => ['suit' => 2, 'polo' => 1, 'hoodie' => 1]]);

        $res = $this->outfit($g, ['replace' => ['types' => ['polo', 'hoodie'], 'counts' => ['polo' => 0, 'hoodie' => 1]], 'note' => 'แก้สี'])->assertCreated();

        $path = $g->fresh()->outfit_zip_path;
        $this->assertNotSame('groups/outfits/old.zip', $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertExists('groups/outfits/old.zip'); // ไฟล์เดิมไม่ถูกทับ/ลบ
        $this->assertSame('groups/outfits/old.zip', $g->fresh()->outfit_zip_original_path);
        $this->assertSame(['suit' => 2, 'polo' => 0, 'hoodie' => 1], $g->fresh()->outfit_counts);
        $this->assertSame(['hoodie', 'polo'], $res->json('data.payload.replace.types'));
        $this->assertStringContainsString('เปลี่ยน/แทน Hoodie (1 ชิ้น), Polo (0 ชิ้น)', $res->json('data.summary'));
    }

    public function test_swap_one_type_for_another(): void
    {
        $g = $this->group('gang', ['outfit_counts' => ['suit' => 2, 'hoodie' => 1]]);

        $res = $this->outfit($g, ['replace' => ['types' => ['hoodie', 'tshirt'], 'counts' => ['hoodie' => 0, 'tshirt' => 1], 'swaps' => ['hoodie' => 'tshirt']]])->assertCreated();

        $this->assertSame(['suit' => 2, 'hoodie' => 0, 'tshirt' => 1], $g->fresh()->outfit_counts);
        $this->assertStringContainsString('Hoodie → T-Shirt (0 ชิ้น), T-Shirt (1 ชิ้น)', $res->json('data.summary'));
    }

    public function test_replace_validation(): void
    {
        $g = $this->group('gang', ['outfit_counts' => ['hoodie' => 1]]);
        $rep = fn (array $o) => $this->outfit($g, ['replace' => array_merge(['types' => ['hoodie', 'tshirt'], 'counts' => ['hoodie' => 0, 'tshirt' => 1]], $o)]);

        $rep(['counts' => ['hoodie' => 0]])->assertUnprocessable()->assertJsonValidationErrors('replace.counts.tshirt'); // ขาดจำนวนของชนิดที่ติ๊ก
        $rep(['counts' => ['hoodie' => 0, 'tshirt' => 1, 'polo' => 1]])->assertUnprocessable()->assertJsonValidationErrors('replace.counts.polo'); // จำนวนของชนิดที่ไม่ได้ติ๊ก
        $rep(['counts' => ['hoodie' => 0, 'tshirt' => 2]])->assertUnprocessable()->assertJsonValidationErrors('replace.counts.tshirt'); // เกินลิมิต
        $rep(['swaps' => ['hoodie' => 'hoodie']])->assertUnprocessable()->assertJsonValidationErrors('replace.swaps.hoodie');
        $rep(['swaps' => ['hoodie' => 'polo']])->assertUnprocessable()->assertJsonValidationErrors('replace.swaps.hoodie'); // ปลายทางไม่อยู่ใน types
        $rep(['swaps' => ['hoodie' => 'zzz']])->assertUnprocessable()->assertJsonValidationErrors('replace.swaps.hoodie');
        // ย้ายต่อเป็นทอด/วนกลับไม่ได้
        $rep(['types' => ['hoodie', 'tshirt', 'polo'], 'counts' => ['hoodie' => 0, 'tshirt' => 0, 'polo' => 1], 'swaps' => ['hoodie' => 'tshirt', 'tshirt' => 'polo']])
            ->assertUnprocessable()->assertJsonValidationErrors('replace.swaps.hoodie');
        $rep(['counts' => ['hoodie' => 0, 'tshirt' => 0], 'swaps' => ['hoodie' => 'tshirt', 'tshirt' => 'hoodie']])->assertUnprocessable()->assertJsonValidationErrors('replace.swaps.hoodie');

        $family = $this->group('family');
        $this->outfit($family, ['replace' => ['types' => ['suit'], 'counts' => ['suit' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('replace.types.0');
        $this->assertSame(['hoodie' => 1], $g->fresh()->outfit_counts);
    }

    // ───────── ลงเพิ่ม + เปลี่ยน/แทน พร้อมกัน ─────────

    public function test_add_and_replace_in_one_transaction_use_one_zip(): void
    {
        Storage::disk('local')->put('groups/outfits/old.zip', 'old');
        $g = $this->group('gang', ['outfit_zip_path' => 'groups/outfits/old.zip', 'outfit_counts' => ['suit' => 1, 'hoodie' => 1]]);

        $res = $this->outfit($g, [
            'add' => ['suit' => 1, 'armor' => 1],
            'replace' => ['types' => ['hoodie', 'tshirt'], 'counts' => ['hoodie' => 0, 'tshirt' => 1], 'swaps' => ['hoodie' => 'tshirt']],
        ])->assertCreated();

        $this->assertSame(['suit' => 2, 'hoodie' => 0, 'armor' => 1, 'tshirt' => 1], $g->fresh()->outfit_counts);
        Storage::disk('local')->assertExists('groups/outfits/old.zip'); // ไฟล์เดิมไม่ถูกทับ/ลบ
        $this->assertSame('groups/outfits/old.zip', $g->fresh()->outfit_zip_original_path);
        Storage::disk('local')->assertExists($g->fresh()->outfit_zip_path);
        $this->assertSame(1, Transaction::count());
        $this->assertStringContainsString('ลงเพิ่ม Suit 1, Armor 1 · เปลี่ยน/แทน Hoodie → T-Shirt', $res->json('data.summary'));
    }

    public function test_the_same_type_cannot_be_in_both_parts(): void
    {
        $g = $this->group('gang', ['outfit_counts' => ['suit' => 1, 'hoodie' => 1]]);

        $this->outfit($g, ['add' => ['hoodie' => 1], 'replace' => ['types' => ['hoodie'], 'counts' => ['hoodie' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('add.hoodie');
        $this->assertSame(['suit' => 1, 'hoodie' => 1], $g->fresh()->outfit_counts);
    }

    // ───────── ค่าบริการตามกฎ ─────────

    public function test_fees_follow_the_council_rules(): void
    {
        $g = $this->group('gang', ['outfit_counts' => ['hoodie' => 1, 'polo' => 1]]);

        // ลงเพิ่ม: ชุดทั่วไป 500,000/ชิ้น, MOD/Accessories 1,000,000/ชิ้น; แก้ไขชุด 500,000/ชนิด (สลับข้ามชนิดนับ 1 ต่อคู่)
        $res = $this->outfit($g, [
            'add' => ['suit' => 2, 'armor' => 1, 'mod1' => 1, 'accessories' => 1],
            'replace' => ['types' => ['hoodie', 'tshirt', 'polo'], 'counts' => ['hoodie' => 0, 'tshirt' => 1, 'polo' => 0], 'swaps' => ['hoodie' => 'tshirt']],
        ])->assertCreated();

        $fee = $res->json('data.payload.fee');
        // (2+1) × 500,000 + (1+1) × 1,000,000 + 2 ชนิด (hoodie→tshirt นับ 1, polo นับ 1) × 500,000
        $this->assertSame(1500000 + 2000000 + 1000000, $fee['total']);
        $this->assertCount(3, $fee['items']);
    }

    public function test_new_groups_get_free_add_for_24_hours_but_not_free_edits(): void
    {
        $g = $this->group('gang', ['registered_at' => now()->subHours(3), 'outfit_counts' => ['hoodie' => 1]]);

        $res = $this->outfit($g, ['add' => ['suit' => 2, 'mod1' => 1], 'replace' => ['types' => ['hoodie'], 'counts' => ['hoodie' => 0]]])->assertCreated();
        $this->assertSame(500000, $res->json('data.payload.fee.total')); // เฉพาะค่าแก้ไขชุด 1 ชนิด

        $old = $this->group('gang', ['registered_at' => now()->subHours(30)]);
        $res = $this->outfit($old, ['add' => ['suit' => 2]])->assertCreated();
        $this->assertSame(1000000, $res->json('data.payload.fee.total')); // พ้น 24 ชม. แล้วคิดปกติ
    }

    public function test_the_free_right_is_lost_after_the_first_file_is_sent(): void
    {
        $g = $this->group('gang', ['registered_at' => now()->subHours(2)]);
        $this->assertTrue($g->outfitFreeAvailable());

        // ครั้งแรก: ฟรี
        $first = $this->outfit($g, ['add' => ['suit' => 1]])->assertCreated();
        $this->assertSame(0, $first->json('data.payload.fee.total'));
        $this->assertFalse($g->fresh()->outfitFreeAvailable());

        // ครั้งที่สอง (ยังไม่ครบ 24 ชม.): คิดตามปกติ สิทธิ์หายแล้ว
        $second = $this->outfit($g, ['add' => ['suit' => 1]])->assertCreated();
        $this->assertSame(500000, $second->json('data.payload.fee.total'));
        $this->assertFalse($this->getJson('/api/council/groups/'.$g->id)->json('data.outfit_free_available'));
    }

    public function test_a_pending_submission_uses_the_free_right_but_a_rejected_one_does_not(): void
    {
        $g = $this->group('gang', ['registered_at' => now()->subHours(2)]);
        $pend = fn () => $this->post(self::URL, ['kind' => 'outfit', 'group_id' => $g->id, 'payload' => ['add' => ['polo' => 1], 'outfit_zip' => $this->zip()], 'approval_required' => true], ['Accept' => 'application/json']);

        $tx = $pend()->assertCreated()->json('data');
        $this->assertFalse($g->fresh()->outfitFreeAvailable()); // รออนุมัติก็นับแล้ว

        $this->reviewer();
        $this->postJson(self::URL.'/'.$tx['id'].'/reject', ['reason' => 'ไฟล์ไม่ผ่าน'])->assertOk();
        $this->assertTrue($g->fresh()->outfitFreeAvailable()); // ถูกปฏิเสธ: สิทธิ์กลับมา
    }

    public function test_the_registration_file_does_not_use_the_free_right(): void
    {
        $g = $this->group('gang', ['registered_at' => now()->subHours(1), 'outfit_zip_path' => 'groups/outfits/reg.zip']);

        $this->assertTrue($g->outfitFreeAvailable());
    }

    public function test_the_activity_log_says_what_the_transaction_did(): void
    {
         $g = $this->group('gang', ['outfit_counts' => ['suit' => 1, 'hoodie' => 1]]);
        $this->outfit($g, [
            'add' => ['suit' => 1],
            'replace' => ['types' => ['hoodie', 'tshirt'], 'counts' => ['hoodie' => 0, 'tshirt' => 1], 'swaps' => ['hoodie' => 'tshirt']],
            'note' => 'เปลี่ยนฮูดเป็นเสื้อยืด',
        ])->assertCreated();

        // บันทึกกิจกรรมดูได้เฉพาะรองประธานขึ้นไป
        $vp = User::factory()->role(Role::VicePresident)->loggedIn()->create();
        DutyShift::create(['user_id' => $vp->id, 'started_at' => now()->subMinutes(20), 'last_seen_at' => now()]);
        Sanctum::actingAs($vp);

        $row = collect($this->getJson('/api/logs?log=transactions')->assertOk()->json('data'))->firstWhere('log', 'transactions');
        $this->assertStringContainsString('Hoodie → T-Shirt', $row['summary']);
        $lines = collect($row['details'])->mapWithKeys(fn ($l, $i) => [$i => $l[0].': '.$l[1]])->implode(" | ");
        $this->assertStringContainsString('ลงชุดเพิ่ม: Suit 1', $lines);
        $this->assertStringContainsString('เปลี่ยน/แทนชุด: Hoodie → T-Shirt (0 ชิ้น), T-Shirt (1 ชิ้น)', $lines);
        $this->assertStringContainsString('ไฟล์ชุด: แทนที่ไฟล์ชุดหลักของกลุ่ม', $lines);
        $this->assertStringContainsString('หมายเหตุ: เปลี่ยนฮูดเป็นเสื้อยืด', $lines);
        $this->assertStringContainsString('ค่าบริการรวม: 1,000,000 WVDL', $lines); // ลงเพิ่ม 1 ชิ้น + แก้ไขชุด 1 คู่
    }

    // ───────── ขั้นอนุมัติ / ชนิดเดิม / แก้จำนวนชุด ─────────

    public function test_approval_flow_applies_the_transaction_only_when_approved(): void
    {
        $g = $this->group();
        $tx = $this->post(self::URL, ['kind' => 'outfit', 'group_id' => $g->id, 'payload' => ['add' => ['suit' => 2], 'outfit_zip' => $this->zip()], 'approval_required' => true], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertNull($g->fresh()->outfit_counts);

        $this->reviewer();
        $this->postJson(self::URL.'/'.$tx['id'].'/approve')->assertOk();

        $this->assertSame(['suit' => 2], $g->fresh()->outfit_counts);
    }

    public function test_rejecting_deletes_the_attached_zip(): void
    {
        $g = $this->group();
        $tx = $this->post(self::URL, ['kind' => 'outfit', 'group_id' => $g->id, 'payload' => ['add' => ['suit' => 1], 'outfit_zip' => $this->zip()], 'approval_required' => true], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
        $this->assertNotEmpty(Storage::disk('local')->allFiles('transactions/outfits'));

        $this->reviewer();
        $this->postJson(self::URL.'/'.$tx['id'].'/reject', ['reason' => 'ไฟล์ไม่ผ่าน'])->assertOk();

        $this->assertEmpty(Storage::disk('local')->allFiles('transactions/outfits'));
        $this->assertNull($g->fresh()->outfit_counts);
    }

    public function test_the_old_separate_kinds_can_no_longer_be_submitted(): void
    {
        $g = $this->group();

        $this->send('outfit_add', $g, ['counts' => ['suit' => 1], 'outfit_zip' => $this->zip()])->assertUnprocessable();
        $this->send('outfit_file', $g, ['types' => ['polo'], 'counts' => ['polo' => 1], 'outfit_zip' => $this->zip()])->assertUnprocessable();
        $this->assertNull($g->fresh()->outfit_counts);
    }

    public function test_outfit_counts_replaces_counts_within_limits(): void
    {
        $g = $this->group('gang', ['outfit_counts' => ['suit' => 2, 'polo' => 1]]);

        $this->send('outfit_counts', $g, ['counts' => ['suit' => 3]])->assertUnprocessable()->assertJsonValidationErrors('counts.suit');
        $this->send('outfit_counts', $g, ['counts' => ['suit' => 2, 'polo' => '']])->assertCreated();

        $this->assertSame(['suit' => 2], $g->fresh()->outfit_counts);
    }
}
