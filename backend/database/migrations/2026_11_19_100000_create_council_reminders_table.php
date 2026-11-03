<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** บันทึกเตือนความจำของสภา (ใช้ร่วมกันทั้งสภา แสดงในหน้าแจ้งเตือนงานค้าง) */
    public function up(): void
    {
        Schema::create('council_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->date('due_on')->nullable();                 // กำหนด (วันที่ไทย) ไม่บังคับ
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_reminders');
    }
};
