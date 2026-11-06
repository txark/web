<?php

use App\Models\Rule;
use App\Services\OfficialRulesImporter;
use Illuminate\Database\Migrations\Migration;

/**
 * อัปเดตกฎหมวดสภาให้ตรงเว็บทางการ (ฉบับ 1.10 · มีผลตั้งแต่ 2 พ.ย. 2569) จากไฟล์ database/data/council-rules.json
 * ทำเฉพาะฐานข้อมูลที่มีกฎอยู่แล้ว (ฐานข้อมูลใหม่ได้ข้อมูลเดียวกันจาก RuleSeeder) · อัตราค่าบริการไม่ถูกแตะ
 */
return new class extends Migration
{
    public function up(): void
    {
        $snapshot = OfficialRulesImporter::snapshot();

        if (! $snapshot || Rule::query()->doesntExist()) {
            return;
        }

        (new OfficialRulesImporter)->apply($snapshot['sections']);
    }

    public function down(): void
    {
        // ย้อนกลับไม่ได้ (เนื้อหาเดิมถูกแทนที่) — ใช้ php artisan rules:reseed เพื่อกลับไปเป็นฉบับ 1.2
    }
};
