<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();               // WC-YYYYMMDD-XXXXXX
            $table->foreignId('group_id')->nullable()->constrained('groups'); // ว่างได้เฉพาะ "ขึ้นทะเบียน" ที่ยังไม่มีผล
            $table->string('kind', 32);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->constrained('users');
            $table->string('requested_role', 32);                  // ยศ ณ ตอนส่ง (ยศเปลี่ยนทีหลังก็ไม่กระทบประวัติ)
            $table->boolean('approval_required')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->text('review_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'kind']);
            $table->index(['group_id', 'created_at']);
        });

        // Protect: วันเริ่มของการคุ้มครอง (วันสิ้นสุดใช้คอลัมน์ protect_until เดิม)
        Schema::table('groups', function (Blueprint $table) {
            $table->timestamp('protect_from')->nullable()->after('protect_requested');
        });
    }

    public function down(): void
    {
        Schema::table('groups', fn (Blueprint $t) => $t->dropColumn('protect_from'));
        Schema::dropIfExists('transactions');
    }
};
