<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ระบบบันทึกข้อพิพาท: บันทึกเหตุการณ์ของแก๊ง/ครอบครัว พร้อมหลักฐานรูป/วิดีโอหลายไฟล์ · ข้อมูลภายในสภา
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();                    // WC-DSP-YYYYMMDD-XXXXXX
            $table->string('title', 200);
            $table->text('details');
            $table->timestamp('occurred_at');                           // วันเวลาที่เกิดเหตุ
            $table->string('status', 20)->default('open');             // open = ยังไม่จบ | closed = จบแล้ว
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('occurred_at');
        });

        // ผู้มีข้อพิพาท (แก๊ง/ครอบครัว) และสภาผู้อยู่ในเหตุการณ์
        Schema::create('dispute_groups', function (Blueprint $table) {
            $table->foreignId('dispute_id')->constrained('disputes')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups');
            $table->primary(['dispute_id', 'group_id']);
        });

        Schema::create('dispute_council', function (Blueprint $table) {
            $table->foreignId('dispute_id')->constrained('disputes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->primary(['dispute_id', 'user_id']);
        });

        Schema::create('dispute_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained('disputes')->cascadeOnDelete();
            $table->string('kind', 10);                                 // image | video
            $table->string('path');
            $table->string('name');
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_files');
        Schema::dropIfExists('dispute_council');
        Schema::dropIfExists('dispute_groups');
        Schema::dropIfExists('disputes');
    }
};
