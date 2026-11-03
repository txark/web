<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // บันทึกประกาศเรียก: สภาเรียกแก๊ง/ครอบครัวเข้าพบ โดยมีผู้แจ้งเรียก (แก๊ง/ครอบครัวอีกฝ่าย) · เก็บต่อท้ายอย่างเดียว
        Schema::create('council_summons', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();                              // WC-CALL-YYYYMMDD-XXXXXX
            $table->foreignId('summoned_group_id')->constrained('groups');       // ผู้ถูกเรียก
            $table->foreignId('summoner_group_id')->constrained('groups');       // ผู้แจ้งเรียก
            $table->text('reason');
            $table->string('place', 120);
            $table->unsignedTinyInteger('dimension');                            // มิติ 1–10
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_summons');
    }
};
