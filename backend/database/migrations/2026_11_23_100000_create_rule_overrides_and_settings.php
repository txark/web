<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** แก้กฎผ่านหน้าเว็บ: กฎที่เพิ่มเอง (is_local) · ข้อที่แก้ทับเว็บทางการ (rule_overrides) · ค่าตั้งค่าทั่วไป (app_settings) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rules', function (Blueprint $t) {
            $t->boolean('is_local')->default(false)->after('is_published'); // เพิ่มเองในหน้าเว็บ ไม่ใช่จากเว็บทางการ (ซิงก์ไม่ลบ)
        });

        Schema::create('rule_overrides', function (Blueprint $t) {
            $t->id();
            $t->string('code', 24)->unique();           // ข้อกฎของเว็บทางการที่ถูกแก้ทับ
            $t->json('fields')->nullable();             // title, body, note_label, note, penalty ที่แก้
            $t->boolean('hidden')->default(false);      // ซ่อนข้อนี้ (ลบ)
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('rule_overrides');
        Schema::table('rules', fn (Blueprint $t) => $t->dropColumn('is_local'));
    }
};
