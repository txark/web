<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ยศผู้เขียนสัญญาและตำแหน่งของผู้กู้ในกลุ่ม ตอนทำสัญญา (เก็บไว้ในหนังสือสัญญา ไม่เปลี่ยนตามภายหลัง)
        Schema::table('council_loans', function (Blueprint $table) {
            $table->string('creator_rank', 40)->nullable()->after('created_by');
            $table->string('borrower_position', 40)->nullable()->after('borrower_name');
        });
    }

    public function down(): void
    {
        Schema::table('council_loans', function (Blueprint $table) {
            $table->dropColumn(['creator_rank', 'borrower_position']);
        });
    }
};
