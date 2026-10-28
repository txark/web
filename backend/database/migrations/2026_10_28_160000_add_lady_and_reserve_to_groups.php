<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('is_lady')->default(false)->after('type'); // แก๊งหญิงล้วน (Lady): ตัวจริงได้ 25
        });

        Schema::table('group_members', function (Blueprint $table) {
            $table->boolean('is_reserve')->default(false)->after('position'); // ตัวสำรอง (เฉพาะแก๊ง)
        });
    }

    public function down(): void
    {
        Schema::table('group_members', fn (Blueprint $t) => $t->dropColumn('is_reserve'));
        Schema::table('groups', fn (Blueprint $t) => $t->dropColumn('is_lady'));
    }
};
