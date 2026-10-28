<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duty_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 16)->nullable(); // manual | auto (เกินชั่วโมงสูงสุด)
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });

        // 1 คนมีเวรที่เปิดอยู่ได้ครั้งละ 1 เวร (กันกดเข้าเวรซ้อนจากหลายแท็บ)
        DB::statement('CREATE UNIQUE INDEX duty_shifts_one_open_per_user ON duty_shifts (user_id) WHERE ended_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS duty_shifts_one_open_per_user');
        Schema::dropIfExists('duty_shifts');
    }
};
