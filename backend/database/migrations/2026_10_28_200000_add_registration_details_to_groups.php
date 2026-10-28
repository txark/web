<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('short_name', 4)->nullable();
            $table->string('color_hex', 7)->nullable();
            $table->string('color_name', 60)->nullable();
            $table->unsignedSmallInteger('declared_members')->nullable();
            $table->string('logo_path')->nullable();           // disk public
            $table->string('temp_outfit_path')->nullable();    // disk local (ส่วนตัว)
            $table->string('temp_outfit_male', 30)->nullable();
            $table->string('temp_outfit_female', 30)->nullable();
            $table->string('outfit_zip_path')->nullable();     // disk local (ส่วนตัว)
            $table->boolean('protect_requested')->default(false);
            $table->timestamp('protect_until')->nullable();
        });

        Schema::table('group_members', function (Blueprint $table) {
            $table->string('ic_phone', 30)->nullable();
            $table->string('discord_id', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('group_members', fn (Blueprint $t) => $t->dropColumn(['ic_phone', 'discord_id']));
        Schema::table('groups', fn (Blueprint $t) => $t->dropColumn([
            'short_name', 'color_hex', 'color_name', 'declared_members', 'logo_path',
            'temp_outfit_path', 'temp_outfit_male', 'temp_outfit_female', 'outfit_zip_path',
            'protect_requested', 'protect_until',
        ]));
    }
};
