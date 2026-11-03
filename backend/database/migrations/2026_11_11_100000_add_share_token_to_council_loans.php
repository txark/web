<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // โทเคนลิงก์รูปหนังสือสัญญากู้ (สร้างเมื่อกดคัดลอกลิงก์รูป · สร้างใหม่แล้วลิงก์เดิมใช้ไม่ได้)
        Schema::table('council_loans', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique()->after('borrower_position');
        });
    }

    public function down(): void
    {
        Schema::table('council_loans', function (Blueprint $table) {
            $table->dropColumn('share_token');
        });
    }
};
