<?php

namespace App\Console\Commands;

use App\Services\OfficialRulesImporter;
use Illuminate\Console\Command;
use Throwable;

class RulesPullOfficial extends Command
{
    protected $signature = 'rules:pull-official
        {--apply : แทนที่กฎหมวดสภาในระบบด้วยที่ดึงมา (ไม่ใส่ = แสดงผลเปรียบเทียบเฉยๆ)}
        {--url= : ที่อยู่หน้ากฎ (ค่าเริ่มต้น https://official.wavevycity.com/rules)}
        {--save= : บันทึกข้อมูลที่ดึงมาเป็นไฟล์ JSON (ใช้เป็นข้อมูลตั้งต้นของระบบ)}';

    protected $description = 'ดึงกฎหมวดสภาล่าสุดจากเว็บทางการ เปรียบเทียบ และอัปเดตลงระบบ';

    public function handle(OfficialRulesImporter $importer): int
    {
        try {
            $data = $importer->fetch($this->option('url') ?: null);
        } catch (Throwable $e) {
            $this->error('ดึงกฎจากเว็บทางการไม่สำเร็จ: '.$e->getMessage().' — ไม่ได้แก้ไขอะไร');

            return self::FAILURE;
        }

        $total = array_sum(array_map('count', $data['sections']));
        if ($total === 0) {
            $this->error('ไม่พบกฎในหน้าที่ดึงมา (โครงหน้าเว็บอาจเปลี่ยน) — ไม่ได้แก้ไขอะไร');

            return self::FAILURE;
        }
        $importer->markChecked(); // เข้าดูเว็บทางการสำเร็จ = อัปเดตวันที่เข้าดูล่าสุด

        $this->info('ฉบับ '.($data['version'] ?? '?').' · มีผลตั้งแต่ '.($data['effective'] ?? '?').' · กฎหมวดสภา '.$total.' ข้อ ใน '.count($data['sections']).' หมวด');

        $rows = [];
        foreach ($importer->diff($data['sections']) as $key => $d) {
            $rows[] = [strtoupper($key), $d['current'], $d['official'], implode(' ', $d['added']) ?: '-', implode(' ', $d['removed']) ?: '-', count($d['changed'])];
        }
        $this->table(['หมวด', 'ในระบบ', 'เว็บทางการ', 'เพิ่มใหม่', 'หายไป', 'แก้เนื้อหา'], $rows);

        if ($data['fees']) {
            $this->line('ค่าบริการ CC-004 ตามเว็บทางการ (ไม่ได้แก้ทับ ตรวจเทียบเอง):');
            foreach ($data['fees'] as $f) {
                $this->line('  - '.$f);
            }
        }

        if ($path = $this->option('save')) {
            $importer->save($data, $path);
            $this->info("บันทึกไฟล์ {$path} แล้ว");
        }

        if (! $this->option('apply')) {
            $this->warn('ยังไม่ได้บันทึก — ใส่ --apply เพื่ออัปเดตจริง');

            return self::SUCCESS;
        }

        if (! $importer->isComplete($data['sections'])) {
            $this->error('ข้อมูลที่ดึงมาไม่ครบ (น้อยกว่าเกณฑ์ขั้นต่ำ) — ไม่ได้แก้ไขกฎในระบบ');

            return self::FAILURE;
        }

        if (! $importer->hasChanges($data['sections'])) {
            $this->info('กฎในระบบตรงกับเว็บทางการแล้ว ไม่มีอะไรต้องอัปเดต');

            return self::SUCCESS;
        }

        $this->info('อัปเดตแล้ว '.$importer->apply($data['sections']).' ข้อ');

        return self::SUCCESS;
    }
}
