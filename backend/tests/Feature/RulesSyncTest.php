<?php

namespace Tests\Feature;

use App\Models\Rule;
use App\Services\OfficialRulesImporter;
use Database\Seeders\RuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** ซิงก์กฎหมวดสภาจากเว็บทางการ */
class RulesSyncTest extends TestCase
{
    use RefreshDatabase;

    private function page(array $cards): string
    {
        $html = '';
        foreach ($cards as $code => [$title, $body]) {
            $html .= '<article id="'.$code.'"><div class="min-w-0 p-5"><h4>'.$title.'</h4><div class="prose-wv"><p>'.$body.'</p></div></div></article>';
        }

        return '<html><body>ฉบับ 9.9 · มีผลตั้งแต่ 1 มกราคม 2570 · 3 ข้อ'.$html.'</body></html>';
    }

    public function test_it_parses_cards_from_the_official_page(): void
    {
        $data = app(OfficialRulesImporter::class)->parse($this->page(['CC-001' => ['หัวข้อ', 'เนื้อหา'], 'VISIT-001' => ['อีกข้อ', 'ข้อความ']]));

        $this->assertSame(['cc', 'visit'], array_keys($data['sections']));
        $this->assertSame('หัวข้อ', $data['sections']['cc'][0]['title']);
        $this->assertSame('เนื้อหา', $data['sections']['cc'][0]['body']);
    }

    public function test_incomplete_data_never_overwrites_existing_rules(): void
    {
        $this->seed(RuleSeeder::class);
        $before = Rule::count();
        // หน้าเว็บที่ดึงได้แค่ 2 ข้อ (เช่นโครงหน้าเปลี่ยน) ต้องไม่ทับกฎเดิม
        Http::fake(['*' => Http::response($this->page(['CC-001' => ['x', 'y'], 'VISIT-001' => ['a', 'b']]))]);

        $this->artisan('rules:pull-official', ['--apply' => true])->assertFailed();

        $this->assertSame($before, Rule::count());
        $this->assertNotSame('x', Rule::where('code', 'CC-001')->value('title'));
    }

    public function test_failed_fetch_changes_nothing(): void
    {
        $this->seed(RuleSeeder::class);
        $before = Rule::count();
        Http::fake(['*' => Http::response('error', 500)]);

        $this->artisan('rules:pull-official', ['--apply' => true])->assertFailed();

        $this->assertSame($before, Rule::count());
    }

    public function test_complete_changed_data_is_applied_and_unchanged_data_is_skipped(): void
    {
        $this->seed(RuleSeeder::class);
        config(['council.rules_sync.min_sections' => 2, 'council.rules_sync.min_rules' => 2]);
        Http::fake(['*' => Http::response($this->page(['CC-001' => ['ใหม่', 'ข้อความใหม่'], 'VISIT-001' => ['อีกข้อ', 'ข้อความ']]))]);

        $this->artisan('rules:pull-official', ['--apply' => true])->assertSuccessful();
        $this->assertSame('ใหม่', Rule::where('code', 'CC-001')->value('title'));
        $this->assertSame(1, Rule::where('section', 'cc')->count());

        // ครั้งที่สอง ข้อมูลตรงกันแล้ว: ไม่แก้ไขซ้ำ
        $id = Rule::where('code', 'CC-001')->value('id');
        $this->artisan('rules:pull-official', ['--apply' => true])->assertSuccessful();
        $this->assertSame($id, Rule::where('code', 'CC-001')->value('id'));
    }

    public function test_pending_rules_are_merged_until_the_official_site_has_them(): void
    {
        $official = ['visit' => [['code' => 'VISIT-001', 'title' => 'ข้อแรก', 'body' => 'เนื้อหา', 'penalty' => null, 'note' => null, 'note_label' => 'หมายเหตุ']]];

        $merged = \App\Services\OfficialRulesImporter::withPending($official);
        $this->assertCount(2, $merged['visit']);
        $this->assertSame('VISIT-002', $merged['visit'][1]['code']);
        $this->assertStringContainsString('ครบ 30 วัน', $merged['visit'][1]['title']);

        // เว็บทางการมีข้อความนี้แล้ว: ไม่ผสมซ้ำ
        $official['visit'][] = ['code' => 'VISIT-002', 'title' => 'แก๊งและครอบครัวที่ขึ้นทะเบียนใหม่ ไม่อนุญาตให้ยุบจนกว่าจะครบ 30 วัน', 'body' => '', 'penalty' => null, 'note' => null, 'note_label' => 'หมายเหตุ'];
        $this->assertCount(2, \App\Services\OfficialRulesImporter::withPending($official)['visit']);
    }
}
