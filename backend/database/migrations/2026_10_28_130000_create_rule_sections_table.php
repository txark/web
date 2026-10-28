<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ข้อมูลระดับหมวดของหน้ากฎ (เช่น วันที่ไปตรวจเนื้อหากับเว็บต้นฉบับล่าสุด)
        Schema::create('rule_sections', function (Blueprint $table) {
            $table->string('section', 32)->primary();   // ดู App\Enums\RuleSection
            $table->date('source_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_sections');
    }
};
