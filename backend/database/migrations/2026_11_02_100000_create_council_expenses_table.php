<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // รายจ่ายของสภา (กรอกเอง) — รายรับดึงจากค่าบริการของธุรกรรมที่อนุมัติแล้ว ไม่เก็บซ้ำที่นี่
        Schema::create('council_expenses', function (Blueprint $table) {
            $table->id();
            $table->date('spent_at');                         // วันที่จ่าย (เวลาไทย)
            $table->unsignedBigInteger('amount');             // WVDL
            $table->string('category', 32);                   // คีย์หมวดจาก council.finance.expense_categories
            $table->string('title', 120);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('spent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_expenses');
    }
};
