<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duty_shifts', function (Blueprint $table) {
            $table->text('note')->nullable()->after('ended_reason');         // หมายเหตุตอนออกเวร / "บังคับออกเวรโดยระบบ"
            $table->timestamp('last_seen_at')->nullable()->after('started_at'); // สัญญาณล่าสุดจากหน้าเว็บ (heartbeat)
        });
    }

    public function down(): void
    {
        Schema::table('duty_shifts', fn (Blueprint $t) => $t->dropColumn(['note', 'last_seen_at']));
    }
};
