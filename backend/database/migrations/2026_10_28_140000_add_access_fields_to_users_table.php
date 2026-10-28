<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('role');      // ระงับสิทธิ์เข้าระบบ
            $table->timestamp('last_login_at')->nullable()->after('disabled_at'); // ว่าง = ยังไม่เคยเข้าระบบ
            $table->unsignedBigInteger('added_by')->nullable()->after('last_login_at'); // ใครเพิ่มคนนี้เข้าระบบ
            $table->index('added_by');
        });

        // ผู้ใช้ที่มีอยู่ก่อนระบบปิดทุกคนเกิดจากการล็อกอินจริง จึงถือว่าเคยเข้าระบบแล้ว (ไม่เช่นนั้นจะถูกมองเป็น "ยังไม่เคยเข้า")
        DB::table('users')->whereNull('last_login_at')->update(['last_login_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['added_by']);
            $table->dropColumn(['disabled_at', 'last_login_at', 'added_by']);
        });
    }
};
