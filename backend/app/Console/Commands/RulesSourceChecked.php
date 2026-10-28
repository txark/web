<?php

namespace App\Console\Commands;

use App\Enums\RuleSection;
use App\Models\RuleSectionMeta;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RulesSourceChecked extends Command
{
    protected $signature = 'rules:source-checked
        {sections?* : คีย์หมวด เช่น general story (ไม่ระบุ = ทุกหมวด)}
        {--date= : วันที่ YYYY-MM-DD (ไม่ระบุ = วันนี้)}';

    protected $description = 'บันทึกว่าได้ตรวจเนื้อหาหมวดกฎกับเว็บต้นฉบับล่าสุดเมื่อวันที่ใด (แสดงในหน้ากฎ)';

    public function handle(): int
    {
        $keys = $this->argument('sections') ?: array_map(fn (RuleSection $s) => $s->value, RuleSection::cases());

        $invalid = array_filter($keys, fn ($k) => RuleSection::tryFrom($k) === null);
        if ($invalid) {
            $this->error('ไม่รู้จักหมวด: '.implode(', ', $invalid));

            return self::FAILURE;
        }

        try {
            $date = $this->option('date') ? Carbon::createFromFormat('Y-m-d', $this->option('date'))->startOfDay() : today();
        } catch (\Throwable) {
            $this->error('รูปแบบวันที่ต้องเป็น YYYY-MM-DD');

            return self::FAILURE;
        }

        foreach ($keys as $key) {
            RuleSectionMeta::updateOrCreate(['section' => $key], ['source_checked_at' => $date->toDateString()]);
        }

        $this->info(count($keys).' หมวด: ตรวจต้นฉบับเมื่อ '.$date->toDateString());

        return self::SUCCESS;
    }
}
