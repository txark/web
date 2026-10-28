<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** บท "ขึ้นทะเบียนแก๊ง/ครอบครัว" แยกเป็น gang และ family: ลบของเดิม แล้วให้ RuleSeeder นำเข้าสองบทใหม่ */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rules')->where('section', 'registration')->delete();
        DB::table('rule_sections')->where('section', 'registration')->delete();
    }

    public function down(): void {}
};
