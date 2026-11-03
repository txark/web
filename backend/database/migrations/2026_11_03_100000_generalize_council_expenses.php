<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // รายการที่กรอกเองมีได้ทั้งรายรับและรายจ่าย (กองทุน / เงินกู้ / โบนัส ฯลฯ) จึงเปลี่ยนชื่อตารางและเพิ่มชนิดรายการ
        Schema::rename('council_expenses', 'council_entries');
        Schema::table('council_entries', function (Blueprint $table) {
            $table->string('type', 8)->default('expense')->after('id'); // income | expense (รายการเดิมเป็นรายจ่ายทั้งหมด)
            $table->index(['type', 'spent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('council_entries', function (Blueprint $table) {
            $table->dropIndex(['type', 'spent_at']);
            $table->dropColumn('type');
        });
        Schema::rename('council_entries', 'council_expenses');
    }
};
