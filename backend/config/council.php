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
    // ค่าบริการธุรกรรมของสภา (อ้างอิงหน้า "ค่าบริการสภา" ในกฎ) หน่วยตาม currency
    // ธุรกรรมที่ในรายการราคายังไม่มีอัตรา (ขึ้นทะเบียน / เปลี่ยน Leader / ยุบ) ตั้งเป็น 0 ไว้ก่อน แก้ที่นี่ที่เดียวเมื่อสภากำหนดราคา
    'fees' => [
        'currency' => 'WVDL',
        'register' => 0,             // ขึ้นทะเบียน
        'rename' => 1000000,         // เปลี่ยนชื่อแก๊ง
        'rename_logo' => 2000000,    // แก้ไข/เปลี่ยน Logo (เปลี่ยนชื่อต้องแนบโลโก้ใหม่เสมอ จึงคิดคู่กัน)
        'recolor' => 1000000,        // เปลี่ยนสีแก๊ง
        'change_leader' => 0,        // เปลี่ยน Leader
        'release_slot' => 300000,    // ออกสล็อต (แจ้งตัวเองออก) ต่อสมาชิกที่ออก 1 คน
        'release_free' => 500000,    // ออกลอย ต่อสมาชิกที่ออก 1 คน
        'dissolve' => 0,             // ยุบ
        'outfit_add' => 500000,         // เพิ่มชุดแก๊ง/ครอบครัว ต่อชิ้น (สูท เกราะ โปโล ฮูด เสื้อยืด ซองปืน)
        'outfit_add_special' => 1000000, // เพิ่มชุด MOD / Accessories ต่อชิ้น
        'outfit_edit' => 500000,        // แก้ไขชุด ต่อชนิดที่ถูกแทน (สลับข้ามชนิดนับ 1 ครั้งต่อคู่)
        'outfit_free_hours' => 24,      // ขึ้นทะเบียนใหม่ ลงชุดเพิ่มฟรีภายในกี่ชั่วโมง (ส่งไฟล์ชุดแล้ว 1 ครั้ง สิทธิ์หมด)
        'outfit_counts' => 0,           // แก้จำนวนชุดที่ลงทะเบียน (สภาแก้ข้อมูล ไม่มีอัตราในกฎ)
        'outfit_file' => 0,             // (ชนิดเดิม ไม่ใช้แล้ว)
    ],

    // รายรับ-รายจ่ายของสภา: รายรับ = ค่าบริการของธุรกรรมที่อนุมัติแล้ว · รายจ่ายกรอกเอง ตามหมวดนี้
    'finance' => [
        'expense_categories' => [
            'prize' => 'รางวัล / ของรางวัล',
            'event' => 'ค่าจัดงาน / กิจกรรม',
            'compensation' => 'ค่าตอบแทน',
            'refund' => 'คืนเงิน / ชดเชย',
            'other' => 'อื่นๆ',
        ],
    ],

    // สัญญาสตอรี่ (กฎ DEAL): เวลาเป็นนาทีนับจากเที่ยงคืน (เวลาไทย)
    'story' => [
        'daily_limit' => 15,        // DEAL-003 รับเล่นได้วันละกี่คู่
        'window_start' => 19 * 60,  // เริ่มคุยตั้งแต่ 19.00
        'window_end' => 23 * 60 + 30,       // ถึง 23.30
        'window_grace_end' => 23 * 60 + 45, // ยินยอมตรงกันเริ่มได้ไม่เกิน 23.45 (DEAL-004)
        // เดิมพันมาตรฐาน (DEAL-010: ตกลงกันให้สูง/ต่ำกว่านี้ได้) ปรับที่ .env ได้โดยไม่ต้องแก้โค้ด
        'stake_min' => (int) env('COUNCIL_STORY_STAKE_MIN', 3000000),
        'stake_max' => (int) env('COUNCIL_STORY_STAKE_MAX', 5000000),
        // ค่าเริ่มต้น: วางเดิมพันนอกช่วงมาตรฐานได้ (ระบบเตือน) ตั้ง COUNCIL_STORY_STAKE_ENFORCE=true เพื่อบังคับให้อยู่ในช่วง min–max
        'stake_enforce' => (bool) env('COUNCIL_STORY_STAKE_ENFORCE', false),
        'stake_cap' => 1000000000,  // เพดานสูงสุดที่รับได้เสมอ (กันกรอกผิด)
        'stake_presets' => [3000000, 4000000, 5000000], // ปุ่มลัดในฟอร์ม
        'guns_min_gang' => 2,       // DEAL-012 ปืนขั้นต่ำ
        'guns_min_family' => 1,
        'protect_days' => 7,        // ฝั่งที่รับ Protect หลังจบสตอรี่ได้กี่วัน (นับทันทีที่บันทึกคะแนนที่ทำให้สตอรี่จบ)
        'lock_minutes' => 15,       // ล็อกสล็อตก่อนเริ่มเล่นกี่นาที
        // จำนวนไฟต์ (คะแนนที่ต้องถึงเพื่อชนะ) ที่เลือกได้ ปรับได้ที่ .env โดยไม่ต้องแก้โค้ด: COUNCIL_STORY_FIGHTS="5,6,7"
        'fights_options' => array_values(array_filter(array_map('intval', explode(',', (string) env('COUNCIL_STORY_FIGHTS', '5,6,7'))))),
        // ให้กรอกจำนวนไฟต์เองได้ (ภายใน min–max) นอกเหนือจากรายการด้านบน (ปิดด้วย COUNCIL_STORY_FIGHTS_CUSTOM=false)
        'fights_custom' => (bool) env('COUNCIL_STORY_FIGHTS_CUSTOM', true),
        'fights_min' => 1,
        'fights_max' => 30,
        // รูปแบบการเล่น ("สุ่มโดยระบบ" สุ่มจากรายการนี้)
        'formats' => ['ไฟท์ตรง', 'ยานพาหนะ'],
        // สถานที่ไฟต์ที่เลือกได้ ("สุ่มโดยระบบ" สุ่มจากรายการนี้) เพิ่มสถานที่ที่นี่
        'venues' => [
            'สนามบินทะเลทราย', 'หน้าคุก ป้ายเขียว', 'สนามบินล่างเสาแดง', 'สนามบินล่าง', 'ลานจอดเครื่องบิน',
            'สนามบินค่ายทหาร', 'อุโมงค่ายทหาร', 'โกดังท่าเรือล่าง', 'โรงงานน้ำมัน', 'แลปบน',
            'ฟาร์มไก่เมืองบน', 'โฮมโปร', 'สนามบินเล็กทะเลทราย', 'ห้าแยกร้านเพชร', 'สนามกีฬา',
            'ถนนแลนด์เขียว', 'ปั้มจรวด',
        ],
        'ticket_options' => ['ใบเป็นใบ', 'ไม่แจ้งใบ'], // การแจ้งใบ
    ],

    'groups' => [
        // ตัวจริง/ตัวสำรองสูงสุด (แก๊งหญิงล้วน = lady, ครอบครัวไม่มีตัวสำรอง)
        'slots' => [
            'gang' => ['main' => 20, 'reserve' => 5],
            'lady' => ['main' => 25, 'reserve' => 5],
            'family' => ['main' => 10, 'reserve' => 0],
        ],
        // จำนวนสมาชิกขั้นต่ำเพื่อขึ้นทะเบียน
        'min_members' => ['gang' => 10, 'family' => 6],
        // ชุดที่ลงได้สูงสุดต่อชนิด (แก๊งและแก๊งหญิงล้วนใช้ชุดเดียวกัน) ชนิดที่ไม่อยู่ในรายการ = ลงไม่ได้
        'outfit_limits' => [
            'gang' => ['suit' => 2, 'armor' => 1, 'polo' => 1, 'hoodie' => 1, 'tshirt' => 1, 'gun' => 1, 'mod1' => 1, 'mod2' => 1, 'accessories' => 1],
            'family' => ['polo' => 1, 'hoodie' => 1, 'tshirt' => 1, 'accessories' => 1],
        ],
        'protect_days' => 14,
        // ชุดชั่วคราวตอนขึ้นทะเบียน: เลขชุดชาย/หญิง (คงที่) และจำนวนเบอร์สีที่เลือกได้ (1..colors)
        'temp_outfit' => ['male' => '1254', 'female' => '1300', 'colors' => 23],
        'max_co_leaders' => 2,         // แก๊ง/แก๊งหญิงล้วน: รองได้ไม่เกิน 2 คน
        'max_co_leaders_family' => 2,  // ครอบครัว: รองได้ 2 คน (REG-006.4)
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
        'exempt_roles' => [],   // บทบาทที่ไม่ต้องเข้าเวรเพิ่มเติม (Admin อยู่นอกลำดับสภาอยู่แล้ว จึงไม่ต้องเข้าเวร)
        'max_hours' => 12,      // เวรที่เปิดค้างเกินนี้ถูกปิดให้เองและต้องเข้าเวรใหม่
        'short_shift_hours' => 3,        // ออกเวรก่อนครบเท่านี้ ต้องระบุหมายเหตุ
        'heartbeat_grace_minutes' => 10, // หน้าเว็บเงียบเกินนี้ (นาที) (ปิดเว็บ/เบราว์เซอร์/คอม) = บังคับออกเวรโดยระบบ
    ],
];
