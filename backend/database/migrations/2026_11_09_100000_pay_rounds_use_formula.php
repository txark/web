<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // โบนัสเวรใช้สูตรที่พิมพ์เอง (เช่น (เวลาเข้าเวร ÷ 1440) × paycheck + โบนัสพิเศษ) + ตัวแปรกลางของรอบ เก็บสำเนาไว้กับรอบ
        Schema::table('council_pay_rounds', function (Blueprint $table) {
            $table->text('formula')->nullable()->after('ended_at');
            $table->json('variables')->nullable()->after('formula');
        });

        // เงินเดือนรายคนไม่ใช้แล้ว (ตัวแปรเป็นค่ากลางของรอบ)
        Schema::dropIfExists('council_pay_settings');
    }

    public function down(): void
    {
        Schema::create('council_pay_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->unsignedBigInteger('salary')->default(0);
            $table->timestamps();
        });
        Schema::table('council_pay_rounds', function (Blueprint $table) {
            $table->dropColumn(['formula', 'variables']);
        });
    }
};
