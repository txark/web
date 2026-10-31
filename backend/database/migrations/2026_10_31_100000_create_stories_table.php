<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // สัญญาสตอรี่ระหว่างสองฝ่าย (แก๊ง/ครอบครัว): ประกาศเริ่ม → ประกาศจบ + คะแนน
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('group_a_id')->constrained('groups');
            $table->foreignId('group_b_id')->constrained('groups');
            $table->string('status', 16)->index(); // active | ended | cancelled
            $table->text('reason');
            $table->unsignedTinyInteger('toxic_level')->default(70); // 70 | 100
            $table->unsignedBigInteger('stake_amount')->default(0);
            $table->boolean('stake_late')->default(false); // ชำระหลังเริ่ม = 2 เท่า
            $table->unsignedSmallInteger('guns')->nullable(); // ต่อฝ่าย; 0/ว่าง = ไม่เล่นปืน
            $table->text('extra_conditions')->nullable();
            $table->json('warnings')->nullable();
            $table->boolean('locked_a')->default(false); // สตอรี่เป็นฝ่ายล็อกสล็อต (จะปลดให้ตอนจบ)
            $table->boolean('locked_b')->default(false);
            $table->timestamp('started_at');
            $table->foreignId('started_by')->constrained('users');
            // ผลตอนจบ
            $table->string('end_kind', 16)->nullable(); // normal | surrender | no_show
            $table->unsignedSmallInteger('score_a')->nullable();
            $table->unsignedSmallInteger('score_b')->nullable();
            $table->foreignId('winner_group_id')->nullable()->constrained('groups');
            $table->foreignId('surrender_group_id')->nullable()->constrained('groups');
            $table->unsignedBigInteger('stake_final')->nullable();
            $table->text('end_note')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
