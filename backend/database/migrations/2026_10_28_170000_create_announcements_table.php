<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->default('general');      // general | summon | event
            $table->string('title', 150);
            $table->text('body');
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete(); // ประกาศเรียกกลุ่มไหน (ถ้ามี)
            $table->timestamp('due_at')->nullable();             // กำหนดเวลา: นัดพบ/เริ่มกิจกรรม
            $table->boolean('is_published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
