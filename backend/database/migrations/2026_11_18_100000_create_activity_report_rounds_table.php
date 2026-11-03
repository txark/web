<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** รอบรายงานความเคลื่อนไหว: สภาเปิดเป็นช่วงเวลา แก๊ง/ครอบครัวยื่นได้เฉพาะในรอบที่เปิดอยู่ (กลุ่มละ 1 ครั้งต่อรอบ) */
    public function up(): void
    {
        Schema::create('activity_report_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->timestamp('opens_at');
            $table->timestamp('closes_at');
            $table->timestamp('closed_early_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_report_rounds');
    }
};
