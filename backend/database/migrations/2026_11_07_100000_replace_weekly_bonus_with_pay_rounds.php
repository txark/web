<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // โบนัสเวร: เปลี่ยนจากจ่ายรายสัปดาห์จันทร์–อาทิตย์ เป็น "ตัดรอบ" (นับจากเวลาตัดรอบครั้งก่อนถึงเวลากดตัดรอบ) — ตารางเดิมยังไม่เคยใช้งานจริง
        Schema::dropIfExists('council_bonus_items');
        Schema::dropIfExists('council_bonus_weeks');

        Schema::create('council_pay_rounds', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');                   // เริ่มนับรอบ (= เวลาตัดรอบครั้งก่อน)
            $table->timestamp('ended_at');                     // เวลาตัดรอบ
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();                              // ย้อนรอบล่าสุด = ลบแบบซ่อน

            $table->index('ended_at');
        });

        // โบนัสเวรของสมาชิกสภาแต่ละคนในรอบ: (นาทีเข้าเวร ÷ 1440) x เงินเดือน + โบนัสพิเศษ
        Schema::create('council_pay_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('council_pay_rounds');
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedInteger('minutes');                // นาทีเข้าเวรในรอบ (ปัดลงเป็นนาทีเต็ม)
            $table->unsignedBigInteger('salary');              // เงินเดือนที่ใช้คำนวณ (เต็มเมื่อเข้าเวรครบ 1,440 นาที)
            $table->unsignedBigInteger('base');                // intdiv(นาที x เงินเดือน, 1440)
            $table->unsignedBigInteger('special')->default(0); // โบนัสพิเศษ
            $table->unsignedBigInteger('amount');              // base + special
            $table->timestamps();

            $table->index(['round_id', 'user_id']);
        });

        // เงินเดือนล่าสุดของแต่ละคน ใช้เติมให้อัตโนมัติในรอบถัดไป
        Schema::create('council_pay_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->unsignedBigInteger('salary')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_pay_settings');
        Schema::dropIfExists('council_pay_items');
        Schema::dropIfExists('council_pay_rounds');
    }
};
