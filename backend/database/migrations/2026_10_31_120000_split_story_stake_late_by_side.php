<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ชำระหลังเริ่ม (DEAL-011) ต้องระบุฝั่งที่ชำระหลังเริ่มชัดเจน และยอดที่ต้องชำระแยกตามฝั่ง (ยอมแพ้ = ฝั่งที่ยอมแพ้ ×2)
        Schema::table('stories', function (Blueprint $table) {
            $table->boolean('stake_late_a')->default(false)->after('stake_amount');
            $table->boolean('stake_late_b')->default(false)->after('stake_late_a');
            $table->unsignedBigInteger('stake_final_a')->nullable()->after('surrender_group_id');
            $table->unsignedBigInteger('stake_final_b')->nullable()->after('stake_final_a');
        });

        // สตอรี่เดิม: ชำระหลังเริ่มเดิมไม่ระบุฝั่ง จึงถือว่าไม่มี (ยอดที่ต้องชำระของที่จบแล้วคงตามเดิมทั้งสองฝั่ง)
        DB::table('stories')->update(['stake_final_a' => DB::raw('stake_final'), 'stake_final_b' => DB::raw('stake_final')]);

        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['stake_late', 'stake_final']);
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->boolean('stake_late')->default(false);
            $table->unsignedBigInteger('stake_final')->nullable();
        });
        DB::table('stories')->update(['stake_final' => DB::raw('stake_final_a')]);
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['stake_late_a', 'stake_late_b', 'stake_final_a', 'stake_final_b']);
        });
    }
};
