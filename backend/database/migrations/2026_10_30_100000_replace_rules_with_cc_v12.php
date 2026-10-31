<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * กฎหมวดสภา CC ฉบับ 1.2: เปลี่ยนหมวดของหน้ากฎเป็น 12 หมวดย่อยใหม่ จึงลบกฎและข้อมูลหมวดเดิมออก
 * (การแก้กฎในระบบของหมวดเดิมจะหายไป) แล้วนำเข้ากฎใหม่ด้วย: php artisan db:seed --class=RuleSeeder
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rules')->delete();
        DB::table('rule_sections')->delete();
    }

    public function down(): void {}
};
