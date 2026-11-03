<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // เงื่อนไขสัญญาเงินกู้: ดอกเบี้ยแบบง่าย % ต่อสัปดาห์ (คิดจากต้นเงิน ไม่ทบต้น) · ผ่อนรายสัปดาห์ N งวด · ค่าปรับล่าช้าต่องวด
        // รายการเดิม (installments = 1, ดอกเบี้ย 0, ไม่มีค่าปรับ) ทำงานเหมือนเดิมทุกอย่าง
        Schema::table('council_loans', function (Blueprint $table) {
            $table->decimal('interest_rate', 6, 2)->default(0)->after('principal');       // % ต่อสัปดาห์
            $table->unsignedSmallInteger('installments')->default(1)->after('interest_rate'); // จำนวนงวด (สัปดาห์ละ 1 งวด)
            $table->string('late_fee_type', 8)->default('fixed')->after('installments');  // fixed = จำนวนเงินคงที่ต่องวด | percent = % ของยอดงวด
            $table->decimal('late_fee_value', 14, 2)->default(0)->after('late_fee_type');
        });
    }

    public function down(): void
    {
        Schema::table('council_loans', function (Blueprint $table) {
            $table->dropColumn(['interest_rate', 'installments', 'late_fee_type', 'late_fee_value']);
        });
    }
};
