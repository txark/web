<?php

use Database\Seeders\CouncilRulesV12;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * รหัสข้อกฎตามไฟล์ต้นฉบับ (เช่น CC-001, VISIT-005) แสดงบนการ์ดข้อกฎและใช้เป็นชื่อเรื่องของข้อ
 * เติมให้กฎที่มีอยู่แล้วโดยไม่ทับเนื้อหา: จับคู่ตามลำดับก็ต่อเมื่อจำนวนข้อกฎของหมวดตรงกับต้นฉบับ
 * (ถ้าไม่ตรง แปลว่าถูกแก้ไขแล้ว จึงข้ามหมวดนั้น)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->string('code', 24)->nullable()->after('section');
        });

        foreach (CouncilRulesV12::all() as $section => $items) {
            $cards = array_values(array_filter($items, fn ($i) => ($i['kind'] ?? 'rule') === 'rule' && ! empty($i['code'])));
            $rows = DB::table('rules')->where('section', $section)->where('kind', 'rule')->orderBy('sort_order')->orderBy('id')->get();

            if ($cards === [] || $rows->count() !== count($cards)) {
                continue;
            }
            foreach ($rows as $i => $row) {
                DB::table('rules')->where('id', $row->id)->update([
                    'code' => $cards[$i]['code'],
                    'title' => $row->title ?: ($cards[$i]['title'] ?? null),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
