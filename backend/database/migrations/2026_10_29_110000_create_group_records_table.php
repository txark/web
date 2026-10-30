<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // บันทึกที่ผูกกับทะเบียน: รายงานตัว / ค่าปรับ / การปรับ Protect (เก็บแบบต่อท้าย แก้หรือลบไม่ได้)
        Schema::create('group_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups');
            $table->string('kind', 16); // report | fine | protect
            $table->json('data')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['group_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_records');
    }
};
