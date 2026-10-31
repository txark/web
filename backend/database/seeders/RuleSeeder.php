<?php

namespace Database\Seeders;

use App\Enums\RuleSection;
use App\Models\Rule;
use App\Models\RuleSectionMeta;
use Illuminate\Database\Seeder;

/**
 * กฎของสภา คัดจากเว็บ WAVEVY COUNCIL (Google Sites)
 *  - หมวดจากหน้า "กฏสภา": อยู่ในไฟล์นี้
 *  - หมวดจากหน้าอื่น (ประกาศเรียก, สตอรี่, Protect, ค่าบริการ, ขึ้นทะเบียน, Home): ExtraRuleContent
 *
 * ตัดเศษข้อความที่ขาดจากการคัดลอก และเขียน WVDL ให้เป็นรูปเดียวกัน
 * ไม่ทับข้อมูลเดิม: ทำทีละหมวด ถ้าหมวดนั้นมีกฎอยู่แล้วจะข้าม (ประธาน/แอดมินแก้ในระบบได้ภายหลัง)
 */
class RuleSeeder extends Seeder
{
    public function run(): void
    {
        // วันที่ไปตรวจกับเว็บต้นฉบับ: ตั้งเป็นวันที่นำเข้า (หมวดที่มีวันที่อยู่แล้วไม่ทับ)
        foreach (RuleSection::cases() as $section) {
            RuleSectionMeta::firstOrCreate(['section' => $section->value], ['source_checked_at' => today()->toDateString()]);
        }

        $existing = Rule::query()->distinct()->pluck('section')
            ->map(fn ($s) => $s instanceof RuleSection ? $s->value : $s)
            ->all();

        Rule::withoutEvents(function () use ($existing) {
            foreach ($this->allSections() as $section => $items) {
                if (! in_array($section, $existing, true)) {
                    $this->insertSection($section, $items);
                }
            }
        });
    }

    /**
     * นำเข้าหมวดที่ระบุใหม่ทั้งหมด "ทับของเดิม" (ใช้เมื่อโครงสร้างเนื้อหาในโค้ดเปลี่ยน เช่น จัดหมวดย่อยใหม่)
     * การแก้ไขกฎของหมวดนั้นในระบบจะหายไป จึงเรียกผ่านคำสั่ง rules:reseed ที่ต้องยืนยันด้วย --force เท่านั้น
     *
     * @param  list<string>  $keys  คีย์หมวด เช่น ['story']
     * @return array<string, int> จำนวนรายการที่นำเข้าต่อหมวด
     */
    public function reseed(array $keys): array
    {
        $all = $this->allSections();
        $counts = [];

        Rule::withoutEvents(function () use ($keys, $all, &$counts) {
            foreach ($keys as $key) {
                Rule::where('section', $key)->delete();
                $this->insertSection($key, $all[$key]);
                $counts[$key] = count($all[$key]);
            }
        });

        return $counts;
    }

    /** @param  list<array<string, string>>  $items */
    private function insertSection(string $section, array $items): void
    {
        foreach ($items as $i => $item) {
            Rule::create([
                'section' => $section,
                'sort_order' => ($i + 1) * 10,
                'kind' => $item['kind'] ?? 'rule',
                'title' => $item['title'] ?? null,
                'body' => $item['body'] ?? null,
                'note_label' => isset($item['note']) ? ($item['note_label'] ?? 'หมายเหตุ') : null,
                'note' => $item['note'] ?? null,
                'penalty' => $item['penalty'] ?? null,
            ]);
        }
    }

    /**
     * @return array<string, list<array<string, string>>>
     */
    private function allSections(): array
    {
        return CouncilRulesV12::all();
    }
}
