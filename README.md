# Council System

```
backend/    Laravel 13 API (Sanctum, Socialite+Discord, activitylog)
frontend/   HTML + Tailwind CLI + Alpine.js (static)
```

## รันตอนพัฒนา

```bash
# backend  (http://localhost:8000)
cd backend
php artisan migrate
php artisan serve

# frontend (http://localhost:3000)
cd frontend
npm install
npm run build        # copy alpine + ฟอนต์ (fonts/, css/fonts.css) + build css
npm run css:watch    # ระหว่างแก้ไข
npm run serve
```

## ตั้งค่า Discord login
1. https://discord.com/developers/applications → New Application → OAuth2
2. Redirect URI: `http://localhost:8000/auth/discord/callback`
3. ใส่ `DISCORD_CLIENT_ID` / `DISCORD_CLIENT_SECRET` ใน `backend/.env`

## ฐานข้อมูล
ใช้ PostgreSQL ฐานข้อมูล `CC_Web` (ตั้งค่า DB_* ใน `backend/.env`)

## ข้อมูลเริ่มต้น / ตัวอย่าง
```bash
php artisan db:seed                              # ใส่กฎสภา (ข้ามถ้ามีกฎอยู่แล้ว)
php artisan db:seed --class=DemoGroupSeeder      # แก๊ง/ครอบครัวตัวอย่างไว้ดูหน้าทำเนียบ (ไม่ต้องรันบนระบบจริง)
```

## หน้าสาธารณะ (ไม่ต้องล็อกอิน)
`/rules.html`, `/groups.html` ใช้ API `GET /api/public/rules`, `/api/public/groups`, `/api/public/groups/{id}`

## ฟอนต์ (ไม่พึ่ง Google Fonts)
เนื้อหา = Noto Sans Thai Looped, หัวข้อ = Prompt — ติดตั้งผ่าน npm (`@fontsource/*`) แล้ว `npm run build`
จะคัดลอกเฉพาะชุดอักษรไทย/ละติน (woff2) ไปที่ `frontend/fonts/` และสร้าง `css/fonts.css` ให้ (รวม ~170KB)
ไฟล์เหล่านี้สร้างอัตโนมัติ จึงอยู่ใน .gitignore — ต้องรัน `npm run build` ก่อน deploy

## บันทึกกิจกรรม (Audit log)
เก็บในตาราง `activity_log` ทุกรายการมี ผู้กระทำ + IP + อุปกรณ์ แบ่ง 3 ประเภท:

| ประเภท (`log_name`) | เก็บอะไร |
|---|---|
| `auth` | เข้าสู่ระบบ / ออกจากระบบ / เข้าสู่ระบบไม่สำเร็จ (รวมคนที่ไม่ได้อยู่ใน Discord ของสภา) |
| `access` | การเปิดดูส่วนที่อ่อนไหว (`->middleware('log.access:ชื่อส่วน')`, ไม่ซ้ำภายใน 5 นาที) และการถูกปฏิเสธสิทธิ์ (403) |
| `default` | การสร้าง/แก้ไข/ลบข้อมูล (ผู้ใช้, แก๊ง/ครอบครัว, สมาชิก, กฎ) พร้อมค่าเดิม → ค่าใหม่ |

- ดูได้ที่ `/logs.html` หรือ `GET /api/logs` — **รองประธานขึ้นไปเท่านั้น** และการเปิดดู log ก็ถูกบันทึกด้วย
- ไม่บันทึกการเปิดดูหน้าสาธารณะ (กฎ/ทำเนียบ)
- ลบอัตโนมัติเมื่อเก่ากว่า `ACTIVITY_LOG_RETENTION_DAYS` (ค่าเริ่มต้น 90 วัน) ผ่าน schedule `activitylog:clean`
  ต้องมีตัวรัน: `php artisan schedule:work` (พัฒนา) หรือ cron `* * * * * php artisan schedule:run` (ใช้งานจริง)
- log มี IP และ Discord ID ถือเป็นข้อมูลส่วนบุคคล: อย่าเปิดให้ผู้ที่ไม่ใช่รองประธานขึ้นไปเห็น

## วันที่ตรวจต้นฉบับของกฎ
`php artisan rules:source-checked [หมวด...] [--date=YYYY-MM-DD]` อัปเดตวันที่ที่แสดงใต้ชื่อบทในหน้ากฎ

## การเข้าสู่ระบบ (ระบบปิด)
ล็อกอินด้วย Discord ได้ **เฉพาะ Discord ID ที่สภาเพิ่มไว้แล้ว** — ไม่มีการสมัครเอง
- แอดมิน / ประธาน (Head-CC) / รองประธาน (Sub-CC) เพิ่มคนที่หน้า `/users.html`: ใส่ Discord ID + ชื่อตัวละคร + บทบาท
  แล้วคนนั้นล็อกอินได้ทันที (ครั้งแรกระบบดึงชื่อ/รูปจาก Discord ให้เอง)
- กำหนดบทบาทได้เฉพาะที่ **ต่ำกว่าตัวเอง**: Sub-CC → Council/Gang/Family, Head-CC → ถึง Sub-CC, Admin → ถึง Head-CC
  และแก้ไขได้เฉพาะบัญชีที่สิทธิ์ต่ำกว่าตัวเอง (แก้ตัวเองไม่ได้) ระงับสิทธิ์ = เตะออกทันที / ลบได้เฉพาะคนที่ยังไม่เคยเข้าระบบ
- ผู้ที่ไม่ได้ถูกเพิ่ม: หน้า Login แสดง Discord ID ของเขาให้คัดลอกไปส่งให้สภา และมี log `login_failed` ให้สภาเห็น
- บัญชีตั้งต้น (กันระบบล็อกตัวเอง): Discord ID ใน `ADMIN_DISCORD_IDS`, `PRESIDENT_DISCORD_IDS`, `VICE_PRESIDENT_DISCORD_IDS` ใน `.env` ล็อกอินได้เลยโดยไม่ต้องถูกเพิ่ม
  (Admin เพิ่มได้ทาง `.env` เท่านั้น)
- ตั้ง `COUNCIL_OPEN_SIGNUP=true` เพื่อกลับไปแบบเปิด (ใครก็ล็อกอินได้เป็น Gang) — ไม่แนะนำ
