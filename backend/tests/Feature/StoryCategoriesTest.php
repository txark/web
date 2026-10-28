<?php

namespace Tests\Feature;

use App\Models\Rule;
use Database\Seeders\RuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function story(): array
    {
        $this->seed(RuleSeeder::class);

        return collect($this->getJson('/api/public/rules')->json('data'))->firstWhere('key', 'story')['rules'];
    }

    /** แบ่งรายการเป็นหมวดย่อยตามหัวข้อ: ['ชื่อหมวด' => [รายการ...]] */
    private function categories(array $items): array
    {
        $out = [];
        $current = null;
        foreach ($items as $item) {
            if ($item['kind'] === 'heading') {
                $current = $item['title'];
                $out[$current] = [];
            } else {
                $this->assertNotNull($current, 'ต้องมีหัวข้อหมวดย่อยก่อนรายการแรกเสมอ');
                $out[$current][] = $item;
            }
        }

        return $out;
    }

    public function test_story_rules_are_split_into_twelve_named_categories_in_order(): void
    {
        $titles = array_keys($this->categories($this->story()));

        $this->assertSame([
            'การรับเรื่องและเงื่อนไขการเปิดสตอรี่',
            'การยั่วยุ งัดร้าน และสตอรี่ซ้อน',
            'หลังตกลงสัญญา ก่อนเริ่มไฟต์',
            'ระหว่างสตอรี่ในห้องสตอรี่',
            'หลังจบสตอรี่',
            'สินเดิมพันและการยอมแพ้',
            'การเล่นปืน',
            'ข้อกำหนดเพิ่มเติมของสัญญาสตอรี่',
            'หลักฐาน (Overlay)',
            'การใช้ปลอกแขน',
            'TOXIC',
            'การกู้เงินกับสภา',
        ], $titles);
    }

    public function test_every_original_rule_appears_exactly_once_after_regrouping(): void
    {
        $items = $this->story();
        $rules = array_values(array_filter($items, fn ($i) => $i['kind'] === 'rule'));
        $bodies = array_column($rules, 'body');

        $this->assertCount(67, $rules, 'จำนวนข้อกฎต้องเท่าต้นฉบับ (ไม่หาย ไม่เพิ่ม)');
        $this->assertSame($bodies, array_values(array_unique($bodies)), 'ห้ามมีข้อซ้ำ');
        $this->assertCount(81, $items); // 67 ข้อกฎ + 12 หัวข้อหมวดย่อย + 2 กล่อง TOXIC
    }

    public function test_category_sizes(): void
    {
        $sizes = collect($this->categories($this->story()))->map(fn ($rows) => count($rows))->values()->all();

        // TOXIC = 2 กล่อง (Toxic 70% / 100%) ไม่ใช่ข้อกฎ
        $this->assertSame([12, 8, 6, 8, 4, 3, 2, 11, 4, 4, 2, 5], $sizes);
    }

    public function test_toxic_category_holds_the_two_level_boxes(): void
    {
        $toxic = $this->categories($this->story())['TOXIC'];

        $this->assertSame(['highlight', 'highlight'], array_column($toxic, 'kind'));
        $this->assertSame(['Toxic 70%', 'Toxic 100%'], array_column($toxic, 'title'));
    }

    public function test_misplaced_old_heading_is_gone_and_loan_rules_stay_together(): void
    {
        $cats = $this->categories($this->story());

        $this->assertArrayNotHasKey('กฎงัดร้าน', $cats);
        $this->assertStringContainsString('สินสงคราม', $cats['การกู้เงินกับสภา'][0]['body']);
        $this->assertSame('ใบแดง + ยึดของ', end($cats['การกู้เงินกับสภา'])['penalty']);
        // ข้อ "งัดร้าน" ถูกย้ายมาอยู่กับหมวดการยั่วยุ
        $this->assertTrue(collect($cats['การยั่วยุ งัดร้าน และสตอรี่ซ้อน'])->contains(fn ($r) => str_contains($r['body'], 'งัดร้าน')));
    }

    // ───────── rules:reseed ─────────

    public function test_reseed_requires_force_and_changes_nothing_without_it(): void
    {
        $this->seed(RuleSeeder::class);
        Rule::where('section', 'story')->first()->update(['body' => 'แก้ไขโดยประธาน']);

        $this->artisan('rules:reseed', ['sections' => ['story']])->assertFailed();

        $this->assertTrue(Rule::where('body', 'แก้ไขโดยประธาน')->exists());
    }

    public function test_reseed_replaces_only_the_named_section(): void
    {
        $this->seed(RuleSeeder::class);
        Rule::where('section', 'story')->first()->update(['body' => 'แก้ไขโดยประธาน']);
        Rule::where('section', 'general')->first()->update(['body' => 'แก้ไข general']);
        $storyBefore = Rule::where('section', 'story')->count();

        $this->artisan('rules:reseed', ['sections' => ['story'], '--force' => true])->assertSuccessful();

        $this->assertFalse(Rule::where('body', 'แก้ไขโดยประธาน')->exists());      // story ถูกนำเข้าใหม่
        $this->assertSame($storyBefore, Rule::where('section', 'story')->count()); // จำนวนเท่าเดิม ไม่ซ้ำซ้อน
        $this->assertTrue(Rule::where('body', 'แก้ไข general')->exists());         // หมวดอื่นไม่ถูกแตะ
    }

    public function test_reseed_rejects_unknown_sections_and_is_logged(): void
    {
        $this->artisan('rules:reseed', ['sections' => ['nope'], '--force' => true])->assertFailed();

        $this->seed(RuleSeeder::class);
        $this->artisan('rules:reseed', ['sections' => ['protect'], '--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('activity_log', ['description' => 'นำเข้ากฎใหม่: protect']);
    }
}
