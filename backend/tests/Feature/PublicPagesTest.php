<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\MemberPosition;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Rule;
use Database\Seeders\RuleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    // ---------- กฎ ----------

    public function test_rules_are_public_and_grouped_in_announced_order(): void
    {
        $this->seed(RuleSeeder::class);

        $response = $this->getJson('/api/public/rules')->assertOk();

        $keys = collect($response->json('data'))->pluck('key')->all();
        $this->assertSame([
            'general', 'supreme', 'ban', 'conversion', 'blacklist', 'sanction',
            'call', 'story', 'protect', 'gang', 'family', 'fees', 'overview', 'staff',
        ], $keys);

        $general = $response->json('data.0');
        $this->assertSame('กฎสภา', $general['label']);
        $this->assertCount(25, collect($general['rules'])->where('kind', 'rule'));
    }

    public function test_sections_are_grouped_contiguously_for_the_table_of_contents(): void
    {
        $this->seed(RuleSeeder::class);

        $groups = collect($this->getJson('/api/public/rules')->json('data'))->pluck('group');

        // กลุ่มเดียวกันต้องอยู่ติดกัน และเรียงตามลำดับกลุ่ม
        $this->assertSame(
            ['กฎสภา', 'สตอรี่และการประกาศเรียก', 'ทะเบียนและค่าบริการ', 'เกี่ยวกับสภา'],
            $groups->unique()->values()->all(),
        );
        $this->assertSame($groups->unique()->count(), $groups->chunkWhile(fn ($v, $k, $chunk) => $v === $chunk->last())->count());
    }

    public function test_rule_note_label_defaults_and_penalty_is_returned(): void
    {
        Rule::create([
            'section' => 'general', 'sort_order' => 10, 'body' => 'ข้อทดสอบ',
            'note' => 'โน้ต', 'penalty' => 'ปรับ 1',
        ]);

        $rule = $this->getJson('/api/public/rules')->json('data.0.rules.0');

        $this->assertSame('หมายเหตุ', $rule['note_label']);
        $this->assertSame('ปรับ 1', $rule['penalty']);
    }

    public function test_unpublished_rules_are_hidden_and_sorted_by_order(): void
    {
        Rule::create(['section' => 'general', 'sort_order' => 20, 'body' => 'B']);
        Rule::create(['section' => 'general', 'sort_order' => 10, 'body' => 'A']);
        Rule::create(['section' => 'general', 'sort_order' => 5, 'body' => 'hidden', 'is_published' => false]);

        $bodies = collect($this->getJson('/api/public/rules')->json('data.0.rules'))->pluck('body')->all();

        $this->assertSame(['A', 'B'], $bodies);
    }

    public function test_rule_seeder_is_idempotent(): void
    {
        $this->seed(RuleSeeder::class);
        $count = Rule::count();

        $this->seed(RuleSeeder::class);

        $this->assertSame($count, Rule::count());
    }

    public function test_rule_seeder_fills_only_missing_sections_and_keeps_edits(): void
    {
        $this->seed(RuleSeeder::class);

        // ประธานแก้กฎในหมวด general แล้ว และหมวด story ถูกลบไป
        Rule::where('section', 'general')->orderBy('sort_order')->first()->update(['body' => 'แก้ไขโดยประธาน']);
        Rule::where('section', 'story')->delete();

        $this->seed(RuleSeeder::class);

        $this->assertSame('แก้ไขโดยประธาน', Rule::where('section', 'general')->orderBy('sort_order')->first()->body);
        $this->assertGreaterThan(0, Rule::where('section', 'story')->count());
    }

    public function test_each_section_reports_when_the_source_was_last_checked(): void
    {
        $this->travelTo('2026-10-28 10:00:00');
        $this->seed(RuleSeeder::class);

        $sections = collect($this->getJson('/api/public/rules')->json('data'));

        $this->assertCount(14, $sections);
        $sections->each(fn ($s) => $this->assertSame('2026-10-28', $s['source_checked_at']));
    }

    public function test_section_without_checked_date_returns_null_instead_of_failing(): void
    {
        Rule::create(['section' => 'general', 'sort_order' => 10, 'body' => 'ข้อทดสอบ']);

        $this->getJson('/api/public/rules')
            ->assertOk()
            ->assertJsonPath('data.0.source_checked_at', null);
    }

    public function test_source_checked_command_updates_selected_sections_only(): void
    {
        $this->travelTo('2026-10-28 10:00:00');
        $this->seed(RuleSeeder::class);

        $this->artisan('rules:source-checked', ['sections' => ['story'], '--date' => '2026-11-05'])->assertSuccessful();

        $by = collect($this->getJson('/api/public/rules')->json('data'))->pluck('source_checked_at', 'key');
        $this->assertSame('2026-11-05', $by['story']);
        $this->assertSame('2026-10-28', $by['general']);
    }

    public function test_source_checked_command_validates_input(): void
    {
        $this->artisan('rules:source-checked', ['sections' => ['nope']])->assertFailed();
        $this->artisan('rules:source-checked', ['--date' => '28/10/2026'])->assertFailed();
    }

    public function test_seeder_does_not_overwrite_an_existing_checked_date(): void
    {
        $this->seed(RuleSeeder::class);
        Artisan::call('rules:source-checked', ['sections' => ['general'], '--date' => '2026-01-15']);

        $this->seed(RuleSeeder::class);

        $by = collect($this->getJson('/api/public/rules')->json('data'))->pluck('source_checked_at', 'key');
        $this->assertSame('2026-01-15', $by['general']);
    }

    public function test_every_section_has_a_source_page_and_fees_are_priced(): void
    {
        $this->seed(RuleSeeder::class);

        $data = collect($this->getJson('/api/public/rules')->json('data'));

        $data->each(fn ($s) => $this->assertStringStartsWith('https://sites.google.com/view/wavevycouncil/', $s['source_url']));

        $fees = $data->firstWhere('key', 'fees')['rules'];
        $this->assertCount(10, $fees);
        $this->assertSame('price', $fees[0]['kind']);
        $this->assertSame('ออกลอย', $fees[0]['title']);
        $this->assertSame('500,000 WVDL', $fees[0]['body']);
    }

    // ---------- ทำเนียบ ----------

    public function test_group_list_is_public_with_leader_and_active_member_count(): void
    {
        $group = Group::factory()->create(['name' => 'Alpha']);
        GroupMember::factory()->position(MemberPosition::Leader)->create(['group_id' => $group->id, 'character_name' => 'Boss One']);
        GroupMember::factory()->count(2)->create(['group_id' => $group->id]);
        GroupMember::factory()->left()->create(['group_id' => $group->id]);

        $row = $this->getJson('/api/public/groups')->assertOk()->json('data.0');

        $this->assertSame('Alpha', $row['name']);
        $this->assertSame('Boss One', $row['leader']);
        $this->assertSame(3, $row['members_count']); // ไม่นับคนที่ออกแล้ว
        $this->assertArrayNotHasKey('members', $row);
    }

    public function test_group_list_filters_by_type_status_and_name(): void
    {
        Group::factory()->create(['name' => 'Wolves']);
        Group::factory()->family()->create(['name' => 'Foxes']);
        Group::factory()->status(GroupStatus::Blacklist)->create(['name' => 'Bears']);

        $names = fn (string $qs) => collect($this->getJson("/api/public/groups?{$qs}")->json('data'))->pluck('name')->all();

        $this->assertSame(['Foxes'], $names('type=family'));
        $this->assertSame(['Bears'], $names('status=blacklist'));
        $this->assertSame(['Wolves'], $names('q=wolv'));
        $this->assertSame(['Bears', 'Foxes', 'Wolves'], $names(''));
    }

    public function test_group_list_rejects_invalid_filters_and_paginates(): void
    {
        $this->getJson('/api/public/groups?type=alien')->assertUnprocessable();
        $this->getJson('/api/public/groups?per_page=500')->assertUnprocessable();

        Group::factory()->count(3)->create();
        $this->getJson('/api/public/groups?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_group_detail_lists_active_members_ordered_and_hides_internal_reasons(): void
    {
        $group = Group::factory()->status(GroupStatus::Sanction, 'เหตุผลภายใน')->create([
            'slots_locked' => true,
            'locked_reason' => 'ล็อกภายใน',
        ]);
        GroupMember::factory()->create(['group_id' => $group->id, 'character_name' => 'Zed']);
        GroupMember::factory()->position(MemberPosition::Leader)->create(['group_id' => $group->id, 'character_name' => 'Boss']);
        GroupMember::factory()->position(MemberPosition::CoLeader)->create(['group_id' => $group->id, 'character_name' => 'Deputy']);
        GroupMember::factory()->left()->create(['group_id' => $group->id, 'character_name' => 'Gone']);

        $response = $this->getJson("/api/public/groups/{$group->id}")->assertOk();

        $this->assertSame(['Boss', 'Deputy', 'Zed'], collect($response->json('data.members'))->pluck('character_name')->all());
        $response->assertJsonPath('data.status', 'sanction')
            ->assertJsonPath('data.status_label', 'คว่ำบาตร')
            ->assertJsonPath('data.slots_locked', true);

        $this->assertStringNotContainsString('เหตุผลภายใน', $response->getContent());
        $this->assertStringNotContainsString('ล็อกภายใน', $response->getContent());
    }

    public function test_deleted_group_is_not_visible(): void
    {
        $group = Group::factory()->create();
        $group->delete();

        $this->getJson("/api/public/groups/{$group->id}")->assertNotFound();
        $this->getJson('/api/public/groups')->assertJsonCount(0, 'data');
    }

    public function test_group_names_must_be_unique_case_insensitively(): void
    {
        Group::factory()->create(['name' => 'Wavevy']);

        $this->expectException(QueryException::class);
        Group::factory()->create(['name' => 'wavevy']);
    }

    public function test_deleted_group_name_can_be_reused(): void
    {
        Group::factory()->create(['name' => 'Wavevy'])->delete();

        Group::factory()->create(['name' => 'Wavevy']);

        $this->assertSame(1, Group::where('name', 'Wavevy')->count());
    }
}
