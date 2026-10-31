<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ชุดชั่วคราวตอนขึ้นทะเบียน: เลือกเบอร์สี 1–23 (ชุดชาย/หญิงเป็นเลขชุดคงที่ตาม config) ไม่แนบรูปแล้ว
        // ใน 1 วัน (ตามวันที่ขึ้นทะเบียน เวลาไทย) แก๊งและครอบครัวใช้เบอร์สีซ้ำกันไม่ได้
        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedTinyInteger('temp_outfit_color')->nullable()->after('temp_outfit_female');
            $table->index(['temp_outfit_color', 'registered_at']);
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex(['temp_outfit_color', 'registered_at']);
            $table->dropColumn('temp_outfit_color');
        });
    }
};
