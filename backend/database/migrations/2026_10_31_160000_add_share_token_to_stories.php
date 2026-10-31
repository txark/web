<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ลิงก์แชร์หนังสือสัญญา: ใครมีลิงก์ก็เปิดอ่านได้ (อ่านอย่างเดียว) จนกว่าสภาจะยกเลิกลิงก์
        Schema::table('stories', function (Blueprint $table) {
            $table->string('share_token', 48)->nullable()->unique()->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('share_token');
        });
    }
};
