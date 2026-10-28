<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // Discord ID ที่ล็อกอินได้ทันทีและได้ role สูงตั้งแต่ครั้งแรก (บัญชีตั้งต้นของระบบ)
    // แอดมินดูแลเว็บ: สิทธิ์สูงสุด (ตั้ง/ถอดประธานได้)
    'admin_discord_ids' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('ADMIN_DISCORD_IDS', '')))
    )),
    'president_discord_ids' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('PRESIDENT_DISCORD_IDS', '')))
    )),
    'vice_president_discord_ids' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('VICE_PRESIDENT_DISCORD_IDS', '')))
    )),

    // ระบบปิด: ล็อกอินได้เฉพาะ Discord ID ที่แอดมิน/ประธาน/รองประธานเพิ่มไว้แล้ว (หรืออยู่ใน *_DISCORD_IDS ข้างบน)
    // ตั้ง COUNCIL_OPEN_SIGNUP=true เพื่อให้ใครก็ล็อกอินได้และเริ่มที่ Gang (ไม่แนะนำ)
    'open_signup' => filter_var(env('COUNCIL_OPEN_SIGNUP', false), FILTER_VALIDATE_BOOL),

    // ถ้าตั้งค่า จะอนุญาตเฉพาะคนที่อยู่ใน Discord server นี้
    'discord_guild_id' => env('DISCORD_GUILD_ID'),

    // กติกาแก๊ง/ครอบครัว (ตามกฎสภา)
    'groups' => [
        // ตัวจริง/ตัวสำรองสูงสุด (แก๊งหญิงล้วน = lady, ครอบครัวไม่มีตัวสำรอง)
        'slots' => [
            'gang' => ['main' => 20, 'reserve' => 5],
            'lady' => ['main' => 25, 'reserve' => 5],
            'family' => ['main' => 10, 'reserve' => 0],
        ],
        // จำนวนสมาชิกขั้นต่ำเพื่อขึ้นทะเบียน
        'min_members' => ['gang' => 10, 'family' => 6],
        'protect_days' => 14,
        'max_co_leaders' => 2,
        'name_max_length' => 20,
        'rename_after_registered_days' => 20, // ลงทะเบียนครบกี่วันถึงเปลี่ยนชื่อได้
        'rename_cooldown_days' => 30,         // เปลี่ยนชื่อซ้ำได้หลังกี่วัน
        'rejoin_cooldown_days' => 7,          // ออกจากกลุ่มแล้วเข้ากลุ่มใหม่ได้หลังกี่วัน
    ],

    // แจ้งเตือนงานค้าง
    'alerts' => [
        'locked_days' => 7, // ล็อกสล็อตเกินกี่วันถึงเตือนให้ทบทวน
    ],

    // เขตเวลาของวันที่ในหน้าสรุปชั่วโมงเวร (ฐานข้อมูลเก็บเป็น UTC)
    'report_timezone' => env('COUNCIL_REPORT_TIMEZONE', 'Asia/Bangkok'),

    // เข้า/ออกเวร: สภาต้องเข้าเวรก่อนใช้ส่วนงานสภา (ยกเว้นหน้าแรกและหน้ากฎ)
    'duty' => [
        'enforced' => filter_var(env('DUTY_ENFORCED', true), FILTER_VALIDATE_BOOL),
        'exempt_roles' => [],   // บทบาทที่ไม่ต้องเข้าเวร เช่น ['admin']
        'max_hours' => 12,      // เวรที่เปิดค้างเกินนี้ถูกปิดให้เองและต้องเข้าเวรใหม่
        'short_shift_hours' => 3,        // ออกเวรก่อนครบเท่านี้ ต้องระบุหมายเหตุ
        'heartbeat_grace_minutes' => 10, // หน้าเว็บเงียบเกินนี้ (นาที) (ปิดเว็บ/เบราว์เซอร์/คอม) = บังคับออกเวรโดยระบบ
    ],
];
