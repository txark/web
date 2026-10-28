<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * ประธาน/รองประธาน/แอดมิน ถูกสร้างตอนล็อกอิน Discord ครั้งแรก
     * ตาม *_DISCORD_IDS ใน .env จึงไม่ต้อง seed ผู้ใช้
     */
    public function run(): void
    {
        $this->call(RuleSeeder::class);
    }
}
