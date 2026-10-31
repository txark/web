<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // จำว่ารูปแบบการเล่น/สถานที่ไฟต์ได้มาจาก "สุ่มโดยระบบ" (ค่าจริงที่สุ่มได้ยังเก็บใน play_format / venue)
        Schema::table('stories', function (Blueprint $table) {
            $table->boolean('play_format_random')->default(false)->after('play_format');
            $table->boolean('venue_random')->default(false)->after('venue');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['play_format_random', 'venue_random']);
        });
    }
};
