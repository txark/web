<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // สัญญาสตอรี่แบบนัดเวลา: เริ่มเล่น/ล็อกสล็อตตามเวลา, เล่นถึงกี่ไฟต์ก่อนชนะ, เงื่อนไขการแข่งขัน
        Schema::table('stories', function (Blueprint $table) {
            $table->string('story_type', 8)->default('gang')->after('number'); // gang | lady | family
            $table->string('play_format', 60)->nullable()->after('status');
            $table->timestamp('starts_at')->nullable()->after('play_format'); // เวลานัดเริ่มเล่น
            $table->timestamp('lock_at')->nullable()->after('starts_at'); // เวลาล็อกสล็อต (ก่อนเริ่มเล่น)
            $table->boolean('lock_applied')->default(false)->after('lock_at');
            $table->unsignedTinyInteger('fights')->nullable()->after('lock_applied'); // ถึงกี่คะแนนก่อนชนะ
            $table->string('venue', 120)->nullable()->after('fights');
            $table->string('ticket_policy', 60)->nullable()->after('guns'); // การแจ้งใบ
            $table->boolean('allow_reserve_swap')->default(false)->after('ticket_policy'); // สลับสล็อตตัวสำรอง
            $table->boolean('allow_slot_change')->default(false)->after('allow_reserve_swap'); // เพิ่ม-ถอนสล็อต
            $table->dropColumn('reason');
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->unsignedTinyInteger('toxic_level')->nullable()->default(null)->change(); // ว่าง = ตามกฎประเทศ
            $table->unsignedSmallInteger('score_a')->default(0)->change();
            $table->unsignedSmallInteger('score_b')->default(0)->change();
            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropIndex(['status', 'starts_at']);
            $table->dropColumn(['story_type', 'play_format', 'starts_at', 'lock_at', 'lock_applied', 'fights', 'venue', 'ticket_policy', 'allow_reserve_swap', 'allow_slot_change']);
            $table->text('reason')->nullable();
        });
    }
};
