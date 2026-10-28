<?php

namespace App\Console\Commands;

use App\Enums\RuleSection;
use App\Models\Rule;
use Database\Seeders\RuleSeeder;
use Illuminate\Console\Command;

class RulesReseed extends Command
{
    protected $signature = 'rules:reseed
        {sections* : คีย์หมวดที่จะนำเข้าใหม่ เช่น story}
        {--force : ยืนยันว่ายอมให้ทับกฎของหมวดนั้นทั้งหมด}';

    protected $description = 'นำเข้ากฎหมวดที่ระบุใหม่จากเนื้อหาในโค้ด (ทับของเดิม ใช้เมื่อโครงสร้างเนื้อหาเปลี่ยน)';

    public function handle(): int
    {
        $keys = $this->argument('sections');

        $invalid = array_filter($keys, fn ($k) => RuleSection::tryFrom($k) === null);
        if ($invalid) {
            $this->error('ไม่รู้จักหมวด: '.implode(', ', $invalid));

            return self::FAILURE;
        }

        $before = Rule::whereIn('section', $keys)->count();
        $this->line("หมวด [".implode(', ', $keys)."] มีกฎอยู่ {$before} รายการ — จะถูกลบแล้วนำเข้าใหม่ทั้งหมด (การแก้ไขในระบบจะหาย)");

        if (! $this->option('force')) {
            $this->warn('ยังไม่ได้ทำอะไร: เพิ่ม --force เพื่อยืนยัน');

            return self::FAILURE;
        }

        $counts = (new RuleSeeder)->reseed($keys);

        activity('default')
            ->event('updated')
            ->withProperties(['sections' => $counts, 'replaced' => $before])
            ->log('นำเข้ากฎใหม่: '.implode(', ', $keys));

        $this->info('นำเข้าแล้ว: '.collect($counts)->map(fn ($n, $k) => "{$k}={$n}")->implode(', '));

        return self::SUCCESS;
    }
}
