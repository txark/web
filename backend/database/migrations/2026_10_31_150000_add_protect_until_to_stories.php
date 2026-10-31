<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // วันสิ้นสุด Protect ของฝั่งที่รับ (นับจากตอนบันทึกคะแนนที่ทำให้สตอรี่จบ) เก็บไว้กับสตอรี่ให้แสดงในรูปสรุปได้ตรงตามที่ตั้งจริง
        Schema::table('stories', function (Blueprint $table) {
            $table->timestamp('protect_until_a')->nullable()->after('protect_b');
            $table->timestamp('protect_until_b')->nullable()->after('protect_until_a');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['protect_until_a', 'protect_until_b']);
        });
    }
};
