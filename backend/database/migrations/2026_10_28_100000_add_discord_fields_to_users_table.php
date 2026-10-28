<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ล็อกอินด้วย Discord เท่านั้น จึงไม่มี email/password
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();

            $table->string('discord_id')->nullable()->unique()->after('id');
            $table->string('avatar')->nullable()->after('name');
            $table->string('character_name')->nullable()->after('avatar');
            $table->string('role', 32)->default('gang')->index()->after('character_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['discord_id', 'avatar', 'character_name', 'role']);
        });
    }
};
