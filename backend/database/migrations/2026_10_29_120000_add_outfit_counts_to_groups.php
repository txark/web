<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->json('outfit_counts')->nullable(); // จำนวนชุดที่ลงทะเบียนแต่ละชนิด (ไม่มีคีย์ = ยังไม่ลงทะเบียน)
        });
    }

    public function down(): void
    {
        Schema::table('groups', fn (Blueprint $t) => $t->dropColumn('outfit_counts'));
    }
};
