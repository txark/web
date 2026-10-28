<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->index();           // gang | family
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('logo_url', 500)->nullable();

            $table->string('status', 16)->default('normal')->index(); // normal | blacklist | sanction
            $table->text('status_reason')->nullable();      // ภายในสภา ไม่แสดงสาธารณะ
            $table->timestamp('status_changed_at')->nullable();

            $table->boolean('slots_locked')->default(false);
            $table->text('locked_reason')->nullable();

            $table->timestamp('registered_at')->useCurrent(); // เริ่มอยู่ภายใต้สภา (ใช้นับ 20 วันก่อนเปลี่ยนชื่อ)
            $table->timestamp('renamed_at')->nullable();      // ใช้นับคูลดาวน์เปลี่ยนชื่อ 30 วัน

            $table->timestamps();
            $table->softDeletes();
        });

        // ชื่อห้ามซ้ำ (ไม่สนตัวพิมพ์เล็ก/ใหญ่) เฉพาะที่ยังไม่ถูกลบ
        DB::statement('CREATE UNIQUE INDEX groups_name_unique ON groups (lower(name)) WHERE deleted_at IS NULL');

        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('character_name', 120);
            $table->string('position', 16)->default('member'); // leader | co_leader | member
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();          // ใช้คำนวณคูลดาวน์ 7 วัน
            $table->timestamps();

            $table->index(['group_id', 'left_at']);
        });

        Schema::create('group_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_status_history');
        Schema::dropIfExists('group_members');
        DB::statement('DROP INDEX IF EXISTS groups_name_unique');
        Schema::dropIfExists('groups');
    }
};
