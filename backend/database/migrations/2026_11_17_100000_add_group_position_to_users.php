<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** ตำแหน่งของบัญชี gang/family ในกลุ่มที่สังกัด: leader (หัวหน้า) | co_leader (รองหัวหน้า) */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('group_position', 16)->nullable()->after('group_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('group_position');
        });
    }
};
