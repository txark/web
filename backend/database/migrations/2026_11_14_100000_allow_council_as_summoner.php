<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ผู้แจ้งเรียกเป็น "สภา" ได้: ไม่มีกลุ่มผู้แจ้ง (summoner_group_id ว่าง) และตั้ง by_council = true
        Schema::table('council_summons', function (Blueprint $table) {
            $table->foreignId('summoner_group_id')->nullable()->change();
            $table->boolean('by_council')->default(false)->after('summoner_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('council_summons', function (Blueprint $table) {
            $table->dropColumn('by_council');
        });
    }
};
