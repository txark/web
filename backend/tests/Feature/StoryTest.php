<?php

namespace Tests\Feature;

use App\Enums\MemberPosition;
use App\Enums\Role;
use App\Models\DutyShift;
use App\Models\Group;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** สัญญาสตอรี่: สร้าง / แก้ไข / ล็อกสล็อตตามเวลา / บันทึกคะแนน / จบพิเศษ / ยกเลิก และการตรวจกติกาอัตโนมัติ */
class StoryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/council/stories';

    protected function setUp(): void
    {
        parent::setUp();
        config(['council.duty.enforced' => true, 'council.duty.exempt_roles' => [], 'council.story.fights_options' => [1, 3, 5, 6, 7], 'council.story.fights_custom' => false]);
        $this->at('2026-10-31 20:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** ตั้งเวลาปัจจุบัน (เวลาไทย) และเข้าเวรใหม่ให้ตรงเวลา */
    private function at(string $bangkok, Role $role = Role::Member): User
    {
        Carbon::setTestNow(Carbon::parse($bangkok, 'Asia/Bangkok'));

        return $this->council($role);
    }

    private function council(Role $role = Role::Member): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        DutyShift::create(['user_id' => $u->id, 'started_at' => now()->subMinutes(20), 'last_seen_at' => now()]);
        Sanctum::actingAs($u);

        return $u;
    }

    private function group(string $type = 'gang', array $attrs = []): Group
    {
        $g = Group::factory()->state(array_merge(['type' => $type, 'registered_at' => now()->subDays(100)], $attrs))->create();
        $g->members()->create(['character_name' => 'Boss '.$g->id, 'position' => MemberPosition::Leader, 'joined_at' => now()->subDays(50)]);

        return $g;
    }

    private function body(Group $a, Group $b, array $over = []): array
    {
        return $over + [
            'story_type' => 'gang', 'play_format' => 'ไฟท์ตรง', 'starts_at' => '2026-10-31T21:00',
            'group_a_id' => $a->id, 'group_b_id' => $b->id, 'fights' => 5, 'venue' => 'หน้าคุก ป้ายเขียว',
            'stake_amount' => 3000000, 'guns' => 2, 'ticket_policy' => 'ใบเป็นใบ',
            'allow_reserve_swap' => true, 'allow_slot_change' => false,
        ];
    }

    private function create(Group $a, Group $b, array $over = [])
    {
        return $this->postJson(self::URL, $this->body($a, $b, $over));
    }

    public function test_only_council_can_use_stories(): void
    {
        $this->getJson(self::URL)->assertOk();
        foreach ([Role::Gang, Role::Family, Role::Admin] as $role) {
            $this->council($role);
            $this->getJson(self::URL)->assertForbidden();
        }
    }

    public function test_create_stores_the_contract_in_thai_time_and_phase_is_waiting(): void
    {
        $a = $this->group();
        $b = $this->group();

        $r = $this->create($a, $b)->assertCreated()
            ->assertJsonPath('data.phase', 'waiting')->assertJsonPath('data.phase_label', 'รอเข้าสู่การเล่น')
            ->assertJsonPath('data.fights', 5)->assertJsonPath('data.score_a', 0)->assertJsonPath('data.warnings', []);
        $this->assertMatchesRegularExpression('/^WV-ST-[A-Z0-9]{8}$/', $r->json('data.number'));

        $story = Story::first();
        $this->assertSame('2026-10-31 14:00:00', $story->starts_at->utc()->format('Y-m-d H:i:s')); // 21:00 เวลาไทย
        $this->assertSame('2026-10-31 13:45:00', $story->lock_at->utc()->format('Y-m-d H:i:s')); // ล็อก 15 นาทีก่อนเล่น
        $this->assertFalse($a->fresh()->slots_locked, 'ยังไม่ถึงเวลาล็อก');
        $this->assertDatabaseHas('activity_log', ['log_name' => 'stories', 'event' => 'started']);
    }

    public function test_slots_lock_at_lock_time_and_phase_turns_to_playing(): void
    {
        $a = $this->group();
        $b = $this->group();
        $id = $this->create($a, $b)->json('data.id');

        $this->at('2026-10-31 20:44:00');
        $this->getJson(self::URL)->assertOk()->assertJsonPath('counts.waiting', 1);
        $this->assertFalse($a->fresh()->slots_locked);

        $this->at('2026-10-31 20:46:00'); // เลยเวลาล็อกแล้ว
        $this->getJson(self::URL)->assertOk();
        $this->assertTrue($a->fresh()->slots_locked);
        $this->assertTrue($b->fresh()->slots_locked);
        $this->assertSame("สตอรี่ {$this->number($id)}", $a->fresh()->locked_reason);

        $this->at('2026-10-31 21:01:00');
        $this->getJson(self::URL."/{$id}")->assertJsonPath('data.phase', 'playing');
        $this->getJson(self::URL.'?status=playing')->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'?status=waiting')->assertJsonCount(0, 'data');
    }

    private function number(int $id): string
    {
        return Story::findOrFail($id)->number;
    }

    public function test_disallowing_both_slot_changes_locks_immediately_and_allowing_again_unlocks_before_lock_time(): void
    {
        $a = $this->group();
        $b = $this->group();
        $id = $this->create($a, $b, ['allow_reserve_swap' => false])->assertCreated()->json('data.id'); // ไม่อนุญาตทั้งคู่ เริ่มเล่น 21:00 ยังไม่ถึงเวลาล็อกปกติ
        $this->assertTrue($a->fresh()->slots_locked);
        $this->assertTrue($b->fresh()->slots_locked);
        $this->assertTrue(Story::find($id)->lock_applied);

        // แก้เป็นอนุญาตสลับตัวสำรอง และยังไม่ถึงเวลาล็อกปกติ → ปลดล็อก
        $this->putJson(self::URL."/{$id}", $this->body($a, $b, ['allow_reserve_swap' => true]))->assertOk();
        $this->assertFalse($a->fresh()->slots_locked);
        $this->assertFalse(Story::find($id)->lock_applied);

        // อนุญาตอย่างน้อยหนึ่งอย่าง → ล็อกตามเวลาปกติ (15 นาทีก่อนเริ่มเล่น)
        $c = $this->group();
        $d = $this->group();
        $this->create($c, $d, ['allow_slot_change' => true])->assertCreated();
        $this->assertFalse($c->fresh()->slots_locked);

        // แก้กลับเป็นไม่อนุญาตทั้งคู่ → ล็อกทันที
        $id2 = Story::where('group_a_id', $c->id)->value('id');
        $this->putJson(self::URL."/{$id2}", $this->body($c, $d, ['allow_reserve_swap' => false, 'allow_slot_change' => false]))->assertOk();
        $this->assertTrue($c->fresh()->slots_locked);
    }

    public function test_command_locks_slots_and_a_late_created_story_locks_immediately(): void
    {
        $a = $this->group();
        $b = $this->group();
        $this->create($a, $b, ['starts_at' => '2026-10-31T20:10'])->assertCreated(); // ล็อกไปแล้วตั้งแต่สร้าง (เวลาล็อก 19:55 ผ่านแล้ว)
        $this->assertTrue($a->fresh()->slots_locked);

        $c = $this->group();
        $d = $this->group();
        $this->create($c, $d)->assertCreated();
        $this->at('2026-10-31 20:50:00');
        $this->artisan('stories:sync-locks')->assertSuccessful();
        $this->assertTrue($c->fresh()->slots_locked);
    }

    public function test_validation_and_group_type_rules(): void
    {
        $a = $this->group();
        $b = $this->group();
        $this->create($a, $a)->assertUnprocessable()->assertJsonValidationErrors('group_a_id');
        $this->postJson(self::URL, ['group_a_id' => $a->id])->assertUnprocessable()
            ->assertJsonValidationErrors(['story_type', 'play_format', 'starts_at', 'group_b_id', 'fights', 'venue', 'stake_amount', 'guns', 'ticket_policy', 'allow_reserve_swap', 'allow_slot_change']);
        $this->create($a, $b, ['fights' => 0])->assertUnprocessable()->assertJsonValidationErrors('fights');
        $this->create($a, $b, ['fights' => 4])->assertUnprocessable()->assertJsonValidationErrors('fights');
        $this->create($a, $b, ['ticket_policy' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('ticket_policy');
        $this->create($a, $b, ['toxic_level' => 50])->assertUnprocessable()->assertJsonValidationErrors('toxic_level');

        // ประเภทต้องตรงกับกลุ่ม
        $fam = $this->group('family');
        $this->create($a, $fam)->assertUnprocessable()->assertJsonValidationErrors('group_b_id');
        $this->create($a, $b, ['story_type' => 'family'])->assertUnprocessable()->assertJsonValidationErrors(['group_a_id', 'group_b_id']);
        $this->create($fam, $this->group('family'), ['story_type' => 'family'])->assertCreated();
        $this->assertSame(1, Story::count());
    }

    public function test_fights_follow_config_and_custom_mode_and_existing_value_is_kept_on_edit(): void
    {
        $a = $this->group();
        $b = $this->group();
        config(['council.story.fights_options' => [5, 6, 7]]);
        $this->create($a, $b, ['fights' => 9])->assertUnprocessable()->assertJsonValidationErrors('fights');

        config(['council.story.fights_custom' => true, 'council.story.fights_max' => 12]);
        $this->create($a, $b, ['fights' => 13])->assertUnprocessable()->assertJsonValidationErrors('fights');
        $id = $this->create($a, $b, ['fights' => 9])->assertCreated()->assertJsonPath('data.fights', 9)->json('data.id');

        // ปิดโหมดกำหนดเอง: สตอรี่เดิมที่เป็น 9 ไฟต์ยังบันทึกซ้ำด้วยค่าเดิมได้ แต่เปลี่ยนเป็นค่านอกรายการไม่ได้
        config(['council.story.fights_custom' => false]);
        $this->putJson(self::URL."/{$id}", $this->body($a, $b, ['fights' => 9, 'venue' => 'โรงงานน้ำมัน']))->assertOk();
        $this->putJson(self::URL."/{$id}", $this->body($a, $b, ['fights' => 8]))->assertUnprocessable()->assertJsonValidationErrors('fights');
        $this->getJson(self::URL)->assertJsonPath('options.fights_custom', false)->assertJsonPath('options.fights_options', [5, 6, 7]);
    }

    public function test_stake_outside_standard_range_is_allowed_with_warning_unless_enforced(): void
    {
        $r = $this->create($this->group(), $this->group(), ['stake_amount' => 500000])->assertCreated();
        $this->assertStringContainsString('นอกมาตรฐาน', implode(' ', $r->json('data.warnings')));
        $this->create($this->group(), $this->group(), ['stake_amount' => 20000000])->assertCreated();
        $this->create($this->group(), $this->group(), ['stake_amount' => 0])->assertCreated();
        $this->create($this->group(), $this->group(), ['stake_amount' => 2000000000])->assertUnprocessable()->assertJsonValidationErrors('stake_amount');
        $this->create($this->group(), $this->group(), ['stake_amount' => -1])->assertUnprocessable()->assertJsonValidationErrors('stake_amount');

        config(['council.story.stake_enforce' => true, 'council.story.stake_min' => 1000000, 'council.story.stake_max' => 2000000]);
        $this->create($this->group(), $this->group(), ['stake_amount' => 500000])->assertUnprocessable()->assertJsonValidationErrors('stake_amount');
        $this->create($this->group(), $this->group(), ['stake_amount' => 1500000])->assertCreated();
        $this->create($this->group(), $this->group(), ['stake_amount' => 0])->assertCreated();
        $this->getJson(self::URL)->assertJsonPath('options.stake_enforce', true)->assertJsonPath('options.stake_min', 1000000);
    }

    public function test_family_stories_have_no_reserve_slot_option_and_lock_by_slot_change_only(): void
    {
        $a = $this->group('family');
        $b = $this->group('family');
        $body = $this->body($a, $b, ['story_type' => 'family', 'allow_slot_change' => false, 'allow_reserve_swap' => true]); // ส่งอนุญาตมาก็ถูกบังคับเป็นไม่อนุญาต
        $r = $this->postJson(self::URL, $body)->assertCreated()->assertJsonPath('data.allow_reserve_swap', false);
        $this->assertTrue($a->fresh()->slots_locked, 'ครอบครัวไม่มีตัวสำรอง: ไม่อนุญาตเพิ่ม-ถอนก็ล็อกทันที');

        // ไม่ส่ง allow_reserve_swap เลยได้สำหรับครอบครัว แต่แก๊งยังต้องส่ง
        $c = $this->group('family');
        $d = $this->group('family');
        $body = $this->body($c, $d, ['story_type' => 'family', 'allow_slot_change' => true]);
        unset($body['allow_reserve_swap']);
        $this->postJson(self::URL, $body)->assertCreated();
        $this->assertFalse($c->fresh()->slots_locked);

        $e = $this->group();
        $f = $this->group();
        $body = $this->body($e, $f);
        unset($body['allow_reserve_swap']);
        $this->postJson(self::URL, $body)->assertUnprocessable()->assertJsonValidationErrors('allow_reserve_swap');
    }

    public function test_random_format_and_venue_pick_from_configured_lists_and_others_are_rejected(): void
    {
        $r = $this->create($this->group(), $this->group(), ['venue' => 'random', 'play_format' => 'random'])->assertCreated();
        $this->assertContains($r->json('data.venue'), config('council.story.venues'));
        $this->assertContains($r->json('data.play_format'), ['ไฟท์ตรง', 'ยานพาหนะ']);
        $this->assertCount(17, config('council.story.venues'));
        $r->assertJsonPath('data.venue_random', true)->assertJsonPath('data.play_format_random', true);

        // เลือกเอง = ไม่ใช่การสุ่ม · แก้ไขโดยส่งค่าจริง + flag คงว่าสุ่มไว้ ไม่สุ่มใหม่
        $a = $this->group();
        $b = $this->group();
        $id = $this->create($a, $b, ['venue' => 'โรงงานน้ำมัน'])->assertCreated()->assertJsonPath('data.venue_random', false)->assertJsonPath('data.play_format_random', false)->json('data.id');
        $this->putJson(self::URL."/{$id}", $this->body($a, $b, ['venue' => 'random', 'play_format' => 'ยานพาหนะ']))->assertOk()->assertJsonPath('data.venue_random', true)->assertJsonPath('data.play_format', 'ยานพาหนะ');
        $picked = Story::find($id)->venue;
        $this->putJson(self::URL."/{$id}", $this->body($a, $b, ['venue' => $picked, 'venue_random' => true, 'play_format' => 'ยานพาหนะ']))->assertOk()
            ->assertJsonPath('data.venue', $picked)->assertJsonPath('data.venue_random', true)->assertJsonPath('data.play_format_random', false);

        $this->create($this->group(), $this->group(), ['venue' => 'ที่ไม่มี'])->assertUnprocessable()->assertJsonValidationErrors('venue');
        $this->create($this->group(), $this->group(), ['play_format' => 'ไฟต์ปะทะ'])->assertUnprocessable()->assertJsonValidationErrors('play_format');
    }

    public function test_a_group_cannot_be_in_two_unfinished_stories(): void
    {
        $a = $this->group();
        $b = $this->group();
        $c = $this->group();
        $this->create($a, $b)->assertCreated();

        $this->create($a, $c)->assertUnprocessable()->assertJsonValidationErrors('group_a_id');
        $this->create($c, $b)->assertUnprocessable()->assertJsonValidationErrors('group_b_id');
        $this->assertSame(1, Story::count());
    }

    public function test_daily_limit_counts_by_play_day_and_ignores_cancelled(): void
    {
        config(['council.story.daily_limit' => 2]);
        $first = $this->create($this->group(), $this->group())->assertCreated()->json('data.id');
        $this->create($this->group(), $this->group())->assertCreated();
        $this->create($this->group(), $this->group())->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->create($this->group(), $this->group(), ['starts_at' => '2026-11-01T21:00'])->assertCreated(); // คนละวัน

        $this->postJson(self::URL."/{$first}/cancel", ['reason' => 'ประกาศผิด'])->assertOk();
        $this->create($this->group(), $this->group())->assertCreated();
        $this->getJson(self::URL)->assertJsonPath('today.count', 2)->assertJsonPath('today.limit', 2);
    }

    public function test_time_stake_and_gun_warnings_do_not_block(): void
    {
        $w = $this->create($this->group(), $this->group('gang'), ['starts_at' => '2026-10-31T23:40', 'stake_amount' => 1000000, 'guns' => 1])->assertCreated()->json('data.warnings');
        $this->assertCount(3, $w);

        $w = $this->create($this->group(), $this->group(), ['starts_at' => '2026-10-31T10:00', 'guns' => 0])->assertCreated()->json('data.warnings');
        $this->assertCount(2, $w); // นอกเวลา + เวลาผ่านไปแล้ว
        $this->assertStringContainsString('นอกช่วง', $w[0]);
    }

    public function test_update_changes_conditions_but_not_the_pair(): void
    {
        $a = $this->group();
        $b = $this->group();
        $id = $this->create($a, $b)->json('data.id');

        $this->putJson(self::URL."/{$id}", $this->body($a, $b, ['fights' => 7, 'starts_at' => '2026-10-31T22:00', 'venue' => 'โรงงานน้ำมัน']))->assertOk()
            ->assertJsonPath('data.fights', 7)->assertJsonPath('data.venue', 'โรงงานน้ำมัน');
        $this->assertSame('2026-10-31 14:45:00', Story::first()->lock_at->utc()->format('Y-m-d H:i:s'));

        $this->putJson(self::URL."/{$id}", $this->body($a, $this->group()))->assertUnprocessable();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'stories', 'event' => 'updated']);
    }

    public function test_score_cannot_be_recorded_before_start_and_ends_when_a_side_reaches_the_target(): void
    {
        $a = $this->group();
        $b = $this->group();
        $id = $this->create($a, $b, ['fights' => 3])->json('data.id');

        $this->postJson(self::URL."/{$id}/score", ['score_a' => 1, 'score_b' => 0])->assertUnprocessable(); // ยังไม่ถึงเวลาเล่น

        $this->at('2026-10-31 21:05:00');
        $this->postJson(self::URL."/{$id}/score", ['score_a' => 1])->assertUnprocessable()->assertJsonValidationErrors('score_b');
        $this->postJson(self::URL."/{$id}/score", ['score_a' => 4, 'score_b' => 0])->assertUnprocessable()->assertJsonValidationErrors('score_a');
        $this->postJson(self::URL."/{$id}/score", ['score_a' => 3, 'score_b' => 3])->assertUnprocessable()->assertJsonValidationErrors('score_b');

        $this->postJson(self::URL."/{$id}/score", ['score_a' => 2, 'score_b' => 1])->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.phase', 'playing')->assertJsonPath('data.score_a', 2);
        $this->assertTrue($a->fresh()->slots_locked);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'stories', 'event' => 'scored']);

        $this->postJson(self::URL."/{$id}/score", ['score_a' => 2, 'score_b' => 3, 'protect_a' => true, 'protect_b' => false])->assertOk()
            ->assertJsonPath('data.protect_a', true)->assertJsonPath('data.protect_b', false)
            ->assertJsonPath('data.status', 'ended')->assertJsonPath('data.phase', 'ended')->assertJsonPath('data.winner_group_id', $b->id)
            ->assertJsonPath('data.stake_final_a', 3000000)->assertJsonPath('data.stake_final_b', 3000000);
        $this->assertFalse($a->fresh()->slots_locked);
        $this->assertNull($a->fresh()->locked_reason);
        $this->postJson(self::URL."/{$id}/score", ['score_a' => 0, 'score_b' => 0])->assertStatus(409);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'stories', 'event' => 'ended']);

        $this->create($a, $b, ['starts_at' => '2026-11-01T21:00'])->assertCreated(); // จบแล้วสร้างคู่ใหม่ได้
    }

    public function test_late_stake_must_name_the_side_and_is_off_by_default(): void
    {
        $a = $this->group();
        $b = $this->group();
        $this->create($a, $b, ['stake_late' => true])->assertUnprocessable()->assertJsonValidationErrors('stake_late');
        $this->create($a, $b, ['stake_late' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('stake_late');
        $this->create($a, $b)->assertCreated()->assertJsonPath('data.stake_late_a', false)->assertJsonPath('data.stake_late_b', false);

        $c = $this->group();
        $d = $this->group();
        $this->create($c, $d, ['stake_late' => 'a'])->assertCreated()->assertJsonPath('data.stake_late_a', true)->assertJsonPath('data.stake_late_b', false);
        $e = $this->group();
        $f = $this->group();
        $this->create($e, $f, ['stake_late' => 'both'])->assertCreated()->assertJsonPath('data.stake_late_a', true)->assertJsonPath('data.stake_late_b', true);
    }

    public function test_protect_choice_is_required_when_the_story_ends_and_forced_off_for_no_show(): void
    {
        $a = $this->group();
        $b = $this->group();
        $id = $this->create($a, $b, ['fights' => 3, 'starts_at' => '2026-10-31T20:10'])->json('data.id');
        $this->at('2026-10-31 20:20:00');

        // ยังไม่จบ: ไม่ต้องระบุ Protect และผลลัพธ์ยังเป็น null
        $this->postJson(self::URL."/{$id}/score", ['score_a' => 2, 'score_b' => 1])->assertOk()->assertJsonPath('data.protect_a', null);

        // ถึงเป้า: ต้องระบุทั้งสองฝั่ง
        $this->postJson(self::URL."/{$id}/score", ['score_a' => 3, 'score_b' => 1])->assertUnprocessable()->assertJsonValidationErrors(['protect_a', 'protect_b']);
        $this->postJson(self::URL."/{$id}/score", ['score_a' => 3, 'score_b' => 1, 'protect_a' => true])->assertUnprocessable()->assertJsonValidationErrors('protect_b');
        $this->postJson(self::URL."/{$id}/score", ['score_a' => 3, 'score_b' => 1, 'protect_a' => true, 'protect_b' => false])->assertOk()
            ->assertJsonPath('data.protect_a', true)->assertJsonPath('data.protect_b', false);

        // ยอมแพ้ต้องระบุ · ไม่มาเจรจาปิดเรื่องถูกงด Protect ทั้งสองฝั่ง (PROT-009) ไม่ต้องระบุ
        $c = $this->group();
        $d = $this->group();
        $id2 = $this->create($c, $d)->json('data.id');
        $this->postJson(self::URL."/{$id2}/end", ['end_kind' => 'surrender', 'surrender_group_id' => $c->id])->assertUnprocessable()->assertJsonValidationErrors(['protect_a', 'protect_b']);
        $e = $this->group();
        $f = $this->group();
        $id3 = $this->create($e, $f)->json('data.id');
        $this->postJson(self::URL."/{$id3}/end", ['end_kind' => 'no_show', 'protect_a' => true, 'protect_b' => true])->assertOk()
            ->assertJsonPath('data.protect_a', false)->assertJsonPath('data.protect_b', false);
    }

    public function test_accepting_protect_grants_it_for_seven_days_from_the_moment_the_score_is_saved(): void
    {
        config(['council.story.fights_options' => [1, 3, 5, 6, 7]]);
        $a = $this->group('gang', ['protect_until' => null]);
        $b = $this->group('gang', ['protect_until' => null]);
        $id = $this->create($a, $b, ['fights' => 1, 'starts_at' => '2026-10-31T20:10'])->json('data.id');
        $this->at('2026-10-31 20:20:00');

        $r = $this->postJson(self::URL."/{$id}/score", ['score_a' => 1, 'score_b' => 0, 'protect_a' => true, 'protect_b' => false])->assertOk();
        $this->assertSame('2026-11-07 13:20:00', Story::find($id)->protect_until_a->utc()->format('Y-m-d H:i:s')); // +7 วัน นับจาก 20:20 เวลาไทย
        $this->assertNull(Story::find($id)->protect_until_b);

        $a = $a->fresh();
        $this->assertSame('2026-11-07 13:20:00', $a->protect_until->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($a->protect_requested);
        $this->assertNull($b->fresh()->protect_until, 'ฝั่งที่ไม่รับไม่มี Protect');
        $this->assertDatabaseHas('group_records', ['group_id' => $a->id, 'kind' => 'protect']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'groups']);
        $r->assertJsonPath('data.protect_until_b', null);
    }

    public function test_existing_longer_protect_is_kept_and_protect_days_follow_config(): void
    {
        config(['council.story.fights_options' => [1, 3, 5, 6, 7], 'council.story.protect_days' => 3]);
        $a = $this->group('gang', ['protect_from' => now(), 'protect_until' => now()->addDays(10)]);
        $b = $this->group();
        $id = $this->create($a, $b, ['fights' => 1, 'starts_at' => '2026-10-31T20:10'])->json('data.id');
        $this->at('2026-10-31 20:20:00');
        $this->postJson(self::URL."/{$id}/score", ['score_a' => 1, 'score_b' => 0, 'protect_a' => true, 'protect_b' => true])->assertOk();

        $this->assertTrue($a->fresh()->protect_until->gt(now()->addDays(9)), 'Protect เดิมที่นานกว่าคงไว้');
        $this->assertTrue($b->fresh()->protect_until->between(now()->addDays(2)->addHours(23), now()->addDays(3)->addMinute()));
        $this->getJson(self::URL)->assertJsonPath('options.protect_days', 3);
    }

    public function test_surrender_and_late_stake_double_without_stacking(): void
    {
        $a = $this->group();
        $b = $this->group();
        $id = $this->create($a, $b)->json('data.id');
        $this->postJson(self::URL."/{$id}/end", ['end_kind' => 'surrender'])->assertUnprocessable()->assertJsonValidationErrors('surrender_group_id');
        $this->postJson(self::URL."/{$id}/end", ['end_kind' => 'normal'])->assertUnprocessable()->assertJsonValidationErrors('end_kind');
        $this->postJson(self::URL."/{$id}/end", ['end_kind' => 'surrender', 'surrender_group_id' => $a->id, 'protect_a' => false, 'protect_b' => true])->assertOk()
            ->assertJsonPath('data.winner_group_id', $b->id)->assertJsonPath('data.stake_final_a', 6000000)->assertJsonPath('data.stake_final_b', 3000000);

        $c = $this->group();
        $d = $this->group();
        $id2 = $this->create($c, $d, ['stake_late' => 'b'])->json('data.id');
        $this->postJson(self::URL."/{$id2}/end", ['end_kind' => 'surrender', 'surrender_group_id' => $d->id, 'protect_a' => true, 'protect_b' => true])->assertOk()
            ->assertJsonPath('data.stake_final_a', 3000000)->assertJsonPath('data.stake_final_b', 6000000); // ฝั่ง B ชำระหลังเริ่มและยอมแพ้ = ×2 ไม่ทบซ้อน
    }

    public function test_no_show_records_zero_scores_without_winner(): void
    {
        $id = $this->create($this->group(), $this->group())->json('data.id');
        $this->postJson(self::URL."/{$id}/end", ['end_kind' => 'no_show'])->assertOk()
            ->assertJsonPath('data.score_a', 0)->assertJsonPath('data.score_b', 0)->assertJsonPath('data.winner_group_id', null)->assertJsonPath('data.end_kind', 'no_show');
    }

    public function test_cancel_requires_reason_and_creator_or_vice_president(): void
    {
        $a = $this->group();
        $b = $this->group();
        $id = $this->create($a, $b, ['starts_at' => '2026-10-31T20:10'])->json('data.id');
        $this->assertTrue($a->fresh()->slots_locked);

        $this->postJson(self::URL."/{$id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->council(Role::Member); // คนอื่นที่ไม่ใช่ผู้สร้าง
        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'x'])->assertForbidden();

        $this->council(Role::VicePresident);
        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'ประกาศผิดคู่'])->assertOk()->assertJsonPath('data.phase', 'cancelled');
        $this->assertFalse($a->fresh()->slots_locked);
        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'x'])->assertStatus(409);
    }

    public function test_slot_lock_set_by_council_before_is_kept_after_story(): void
    {
        $a = $this->group('gang', ['slots_locked' => true, 'locked_reason' => 'สภาล็อกเอง']);
        $b = $this->group();
        $id = $this->create($a, $b, ['starts_at' => '2026-10-31T20:10', 'fights' => 1])->json('data.id');
        $this->at('2026-10-31 20:20:00');
        $this->postJson(self::URL."/{$id}/score", ['score_a' => 1, 'score_b' => 0, 'protect_a' => false, 'protect_b' => false])->assertOk()->assertJsonPath('data.status', 'ended');

        $this->assertTrue($a->fresh()->slots_locked);
        $this->assertSame('สภาล็อกเอง', $a->fresh()->locked_reason);
        $this->assertFalse($b->fresh()->slots_locked);
    }

    public function test_transactions_are_blocked_while_a_group_is_in_an_unfinished_story(): void
    {
        $a = $this->group();
        $b = $this->group();
        $id = $this->create($a, $b, ['fights' => 1])->json('data.id');

        $dissolve = fn () => $this->postJson('/api/council/transactions', ['kind' => 'dissolve', 'group_id' => $a->id, 'payload' => ['reason' => 'x']]);
        $dissolve()->assertUnprocessable()->assertJsonValidationErrors('group_id');

        $this->at('2026-10-31 21:05:00');
        $this->postJson(self::URL."/{$id}/score", ['score_a' => 1, 'score_b' => 0, 'protect_a' => false, 'protect_b' => false])->assertOk();
        $dissolve()->assertCreated();
    }

    public function test_member_changes_are_blocked_once_the_lock_time_passes(): void
    {
        $a = $this->group();
        $b = $this->group();
        $this->create($a, $b)->assertCreated();
        $this->at('2026-10-31 20:50:00'); // ถึงเวลาล็อก แต่ยังไม่มีใครเปิดรายการสตอรี่

        $this->postJson("/api/council/groups/{$a->id}/members", ['character_name' => 'New Guy', 'ic_phone' => '555', 'discord_id' => '123456789012345678'])->assertStatus(409);
    }

    public function test_group_logo_can_be_fetched_by_council_through_the_api(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('groups/logos/a.png', "\x89PNG\r\n\x1a\nfake");
        $a = $this->group('gang', ['logo_path' => 'groups/logos/a.png']);
        $b = $this->group();

        $this->get("/api/council/groups/{$a->id}/files/logo")->assertOk();
        $this->getJson("/api/council/groups/{$b->id}/files/logo")->assertNotFound();
    }

    public function test_list_can_be_filtered_by_play_date_range_in_thai_time(): void
    {
        $g = $this->group();
        $make = function (string $startsAt) use ($g) {
            $other = $this->group();
            $this->create($g, $other, ['starts_at' => $startsAt])->assertCreated();
            Story::latest('id')->first()->update(['status' => 'cancelled']); // ไม่ให้ชนกฎสตอรี่ซ้อน
        };
        $make('2026-10-26T20:00'); // จันทร์
        $make('2026-11-01T23:30'); // อาทิตย์ (ท้ายวัน)
        $make('2026-11-02T00:10'); // จันทร์ถัดไป (เวลาไทยหลังเที่ยงคืน)

        $this->getJson(self::URL."?group_id={$g->id}&from=2026-10-26&to=2026-11-01")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson(self::URL."?group_id={$g->id}&from=2026-11-02&to=2026-11-08")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL."?group_id={$g->id}&from=2026-11-09")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::URL.'?from=31-10-2026')->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_contract_share_link_is_public_read_only_and_can_be_revoked(): void
    {
        $a = $this->group('gang', ['name' => 'Alpha']);
        $b = $this->group('gang', ['name' => 'Beta']);
        $id = $this->create($a, $b, ['extra_conditions' => 'จู๊บ 1ที'])->json('data.id');

        $token = $this->postJson(self::URL."/{$id}/share")->assertOk()->json('token');
        $this->assertSame(40, strlen($token));
        $this->assertSame($token, $this->postJson(self::URL."/{$id}/share")->json('token'), 'กดซ้ำได้ลิงก์เดิม');
        $this->assertDatabaseHas('activity_log', ['log_name' => 'stories', 'event' => 'shared']);

        // เปิดอ่านได้โดยไม่ต้องล็อกอิน และไม่มีข้อมูลภายใน
        $this->getJson("/api/public/stories/{$token}")->assertOk()
            ->assertJsonPath('data.group_a.name', 'Alpha')->assertJsonPath('data.extra_conditions', 'จู๊บ 1ที')
            ->assertJsonMissingPath('data.warnings')->assertJsonMissingPath('data.started_by')->assertJsonMissingPath('data.share_token')->assertJsonMissingPath('data.id');

        $this->getJson('/api/public/stories/'.str_repeat('a', 40))->assertNotFound();
        $this->getJson('/api/public/stories/short')->assertNotFound();

        // ยกเลิกลิงก์
        $this->deleteJson(self::URL."/{$id}/share")->assertOk();
        $this->getJson("/api/public/stories/{$token}")->assertNotFound();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'stories', 'event' => 'unshared']);
    }

    public function test_list_filters_by_status_group_and_search_and_returns_counts(): void
    {
        $a = $this->group('gang', ['name' => 'Alpha Wolves']);
        $b = $this->group('gang', ['name' => 'Beta Crew']);
        $c = $this->group('gang', ['name' => 'Gamma']);
        $d = $this->group('gang', ['name' => 'Delta']);
        $this->create($a, $b)->assertCreated();
        $id = $this->create($c, $d, ['starts_at' => '2026-10-31T20:10', 'fights' => 1])->json('data.id');
        $this->at('2026-10-31 20:12:00');
        $this->postJson(self::URL."/{$id}/score", ['score_a' => 1, 'score_b' => 0, 'protect_a' => false, 'protect_b' => false])->assertOk();

        $this->getJson(self::URL)->assertJsonCount(2, 'data')->assertJsonPath('counts.waiting', 1)->assertJsonPath('counts.ended', 1)->assertJsonPath('counts.all', 2);
        $this->getJson(self::URL.'?status=waiting')->assertJsonCount(1, 'data')->assertJsonPath('data.0.group_a.name', 'Alpha Wolves');
        $this->getJson(self::URL.'?status=ended')->assertJsonCount(1, 'data');
        $this->getJson(self::URL."?group_id={$d->id}")->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'?q=beta')->assertJsonCount(1, 'data');
        $this->getJson(self::URL)->assertJsonPath('options.lock_minutes', 15);
    }
}
