<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ซื้อ-ขายระหว่างสภากับแก๊ง/ครอบครัว: สภาขายให้กลุ่ม = รายรับ · สภาซื้อจากกลุ่ม = รายจ่าย
        Schema::create('council_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups');
            $table->string('direction', 4);                    // sell = สภาขายให้กลุ่ม | buy = สภาซื้อจากกลุ่ม
            $table->string('item', 120);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price');          // WVDL ต่อหน่วย
            $table->unsignedBigInteger('amount');              // quantity x unit_price (คำนวณฝั่งเซิร์ฟเวอร์)
            $table->date('traded_at');                         // วันที่ซื้อ-ขาย (เวลาไทย)
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['group_id', 'traded_at']);
            $table->index('traded_at');
        });

        // โบนัสรายอาทิตย์ของสภา: คำนวณจากชั่วโมงเวรจันทร์–อาทิตย์ (เวลาไทย) บันทึกแล้วถือว่าจ่ายแล้ว (ไม่มีสถานะรอจ่าย)
        Schema::create('council_bonus_weeks', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');                        // วันจันทร์ของสัปดาห์
            $table->unsignedBigInteger('rate_per_hour');       // WVDL ต่อชั่วโมงเวร
            $table->decimal('min_hours', 6, 2)->default(0);    // ชั่วโมงเวรขั้นต่ำที่จะได้รับโบนัส
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('week_start');
        });

        Schema::create('council_bonus_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('week_id')->constrained('council_bonus_weeks');
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedInteger('seconds');                // ชั่วโมงเวรของสัปดาห์นั้น (วินาที)
            $table->unsignedBigInteger('amount');              // WVDL
            $table->timestamps();

            $table->index(['week_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_bonus_items');
        Schema::dropIfExists('council_bonus_weeks');
        Schema::dropIfExists('council_trades');
    }
};
