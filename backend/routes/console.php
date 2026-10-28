<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ลบบันทึกกิจกรรมที่เก่ากว่า ACTIVITY_LOG_RETENTION_DAYS (ค่าเริ่มต้น 90 วัน)
// ต้องมีตัวรันตารางงาน: `php artisan schedule:work` (พัฒนา) หรือ cron `* * * * * php artisan schedule:run` (ใช้งานจริง)
Schedule::command('activitylog:clean --force')->dailyAt('03:30');

// บังคับออกเวรให้สภาที่ปิดหน้าเว็บ/เบราว์เซอร์/คอมโดยไม่ออกเวร (เวรที่หมดสัญญาณเกินกำหนด)
// ถ้าตัวรันตารางงานไม่ทำงาน ระบบก็ปิดเวรที่ค้างให้อยู่ดีเมื่อมีการเรียกใช้ครั้งถัดไป เพียงแต่ไม่ทันที
Schedule::command('duty:close-abandoned')->everyMinute();
