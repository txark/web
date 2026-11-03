<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // เงินกู้ที่สภาปล่อยให้แก๊ง/ครอบครัว: ยอดกู้เป็นรายจ่าย การรับคืนเป็นรายรับ (รวมเข้าสรุปการเงินอัตโนมัติ)
        Schema::create('council_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups');
            $table->unsignedBigInteger('principal');          // WVDL
            $table->date('loaned_at');                        // วันที่ให้กู้ (เวลาไทย)
            $table->date('due_at')->nullable();               // วันครบกำหนดชำระ
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['group_id', 'loaned_at']);
        });

        Schema::create('council_loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('council_loans');
            $table->unsignedBigInteger('amount');
            $table->date('paid_at');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_loan_payments');
        Schema::dropIfExists('council_loans');
    }
};
