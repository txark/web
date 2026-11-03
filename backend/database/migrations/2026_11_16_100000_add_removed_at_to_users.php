<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** ลบผู้ใช้แบบซ่อน: ประวัติ (ธุรกรรม เวร การเงิน ฯลฯ) ยังอ้างถึงบัญชีเดิมได้ครบ */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('disabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
