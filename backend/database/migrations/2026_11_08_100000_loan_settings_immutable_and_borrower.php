<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ตั้งค่ากลางการกู้เงิน: ค่าเริ่มต้นที่ใส่ให้อัตโนมัติเมื่อสร้างสัญญาใหม่ (สัญญาเก่าเก็บค่าตอนทำสัญญา ไม่เปลี่ยนตาม)
        Schema::create('council_loan_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('default_interest', 6, 2)->default(0);        // % ต่อสัปดาห์
            $table->string('late_fee_type', 8)->default('day');           // day = ต่อวัน | week = ต่อสัปดาห์ | once = ครั้งเดียว
            $table->unsignedBigInteger('late_fee_amount')->default(0);
            $table->unsignedSmallInteger('max_installments')->default(6); // 1 งวด = 1 สัปดาห์
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // ประวัติการแก้ค่ากลาง (ต่อท้ายอย่างเดียว แก้ไม่ได้)
        Schema::create('council_loan_setting_logs', function (Blueprint $table) {
            $table->id();
            $table->string('field', 32);
            $table->string('old_value', 64)->nullable();
            $table->string('new_value', 64);
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });

        // สัญญา: ชื่อผู้กู้ (คน) + เหตุผล · บันทึกแล้วแก้/ลบไม่ได้ ยกเลิกได้ด้วยรายการยกเลิกต่อท้าย (cancelled_*)
        Schema::table('council_loans', function (Blueprint $table) {
            $table->string('borrower_name', 120)->nullable()->after('group_id');
            $table->text('reason')->nullable()->after('borrower_name');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->text('cancel_reason')->nullable();
        });

        // การรับคืน: แก้/ลบไม่ได้ ถ้าผิดบันทึก "รายการยกเลิกรับคืน" ต่อท้าย (reversal_of ชี้ไปรายการที่ยกเลิก)
        Schema::table('council_loan_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('reversal_of')->nullable()->after('loan_id');
            $table->text('reason')->nullable();
            $table->index('reversal_of');
        });

        // วิธีคิดค่าปรับเดิม (fixed/percent ต่องวด) เปลี่ยนเป็นแบบตามแบบร่าง (ต่อวัน/ต่อสัปดาห์/ครั้งเดียว): ข้อมูลเดิมเป็นข้อมูลทดสอบ
        DB::table('council_loans')->where('late_fee_type', 'percent')->update(['late_fee_type' => 'once', 'late_fee_value' => 0]);
        DB::table('council_loans')->where('late_fee_type', 'fixed')->update(['late_fee_type' => 'once']);
    }

    public function down(): void
    {
        Schema::table('council_loan_payments', function (Blueprint $table) {
            $table->dropIndex(['reversal_of']);
            $table->dropColumn(['reversal_of', 'reason']);
        });
        Schema::table('council_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['borrower_name', 'reason', 'cancelled_at', 'cancel_reason']);
        });
        Schema::dropIfExists('council_loan_setting_logs');
        Schema::dropIfExists('council_loan_settings');
    }
};
