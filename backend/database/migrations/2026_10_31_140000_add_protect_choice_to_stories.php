<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PROT-006: จบสตอรี่แล้วแต่ละฝั่งเลือกได้ว่าจะรับ Protect หรือไม่ (null = ยังไม่ระบุ / สตอรี่ยังไม่จบ)
        Schema::table('stories', function (Blueprint $table) {
            $table->boolean('protect_a')->nullable()->after('stake_final_b');
            $table->boolean('protect_b')->nullable()->after('protect_a');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['protect_a', 'protect_b']);
        });
    }
};
