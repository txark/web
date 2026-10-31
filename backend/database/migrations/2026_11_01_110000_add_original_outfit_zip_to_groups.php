<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ไฟล์ชุดตอนขึ้นทะเบียนเก็บไว้ถาวร ไม่ถูกทับเมื่อแก้ไขชุด/เปลี่ยนสี (outfit_zip_path = ไฟล์ล่าสุด)
        Schema::table('groups', function (Blueprint $table) {
            $table->string('outfit_zip_original_path')->nullable()->after('outfit_zip_path'); // disk local (ส่วนตัว)
        });

        // ทะเบียนเดิม: ไฟล์ที่มีอยู่ตอนนี้ถือเป็นไฟล์ตั้งต้น
        DB::table('groups')->whereNotNull('outfit_zip_path')->update(['outfit_zip_original_path' => DB::raw('outfit_zip_path')]);
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('outfit_zip_original_path');
        });
    }
};
