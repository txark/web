#!/bin/sh
set -e
cd /var/www/app

# Render กำหนดพอร์ตผ่าน $PORT (ค่าเริ่มต้น 10000)
PORT="${PORT:-10000}"
sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# ดิสก์ถาวร (ถ้าผูกไว้ที่ storage/app) ทับโฟลเดอร์ว่าง จึงสร้างโฟลเดอร์ย่อยและสิทธิ์ใหม่ทุกครั้งที่เริ่ม
mkdir -p storage/app/public storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache
php artisan storage:link --force >/dev/null 2>&1 || true

# ฐานข้อมูล: สร้าง/อัปเดตตารางทุกครั้งที่ deploy (ไม่ลบข้อมูล) แล้วแคชค่าตั้งค่า
php artisan migrate --force
# กฎเริ่มต้น (DatabaseSeeder เติมเฉพาะกฎ และไม่ทับของเดิม จึงรันซ้ำทุกครั้งได้) — แผนฟรีเข้า Shell ไม่ได้ จึงเติมให้อัตโนมัติ
php artisan db:seed --force
php artisan config:cache
php artisan view:cache

# ตัวรันตารางงานในคอนเทนเนอร์เดียวกัน (ซิงก์กฎจากเว็บทางการทุกชั่วโมง ปิดเวรที่ค้าง ล็อกสล็อตสตอรี่)
# รันเป็น www-data เพื่อไฟล์ที่สร้างใน storage ไม่เป็นของ root
runuser -u www-data -- php artisan schedule:work >/dev/null &

# ซิงก์กฎจากเว็บทางการหนึ่งครั้งตอนเริ่ม (เบื้องหลัง ไม่หน่วงการเปิดเว็บ) — แผนฟรีที่หลับอยู่จะได้กฎล่าสุดทุกครั้งที่ตื่น
if [ "${RULES_AUTO_SYNC:-true}" != "false" ]; then
  (timeout 90 runuser -u www-data -- php artisan rules:pull-official --apply || true) &
fi

exec apache2-foreground
