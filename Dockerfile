# WAVEVY COUNCIL — เว็บเดียวจบ (Laravel API + หน้าเว็บ static) สำหรับ Render
# Build จากโฟลเดอร์รากของ repo (Root Directory เว้นว่าง · Dockerfile Path = ./Dockerfile)

# ── 1) สร้างหน้าเว็บ (CSS / Alpine / ฟอนต์) ──
FROM node:22-alpine AS frontend
WORKDIR /frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build \
    && printf "window.APP_CONFIG = { API_URL: '' };\n" > js/config.js \
    && rm -rf node_modules package.json package-lock.json scripts serve.json

# ── 2) ติดตั้งไลบรารี PHP ──
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ── 3) ตัวรันจริง (PHP 8.4: Symfony ที่ lock ไว้ต้องการ >= 8.4.1) ──
FROM php:8.4-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip \
    && docker-php-ext-install pdo_pgsql zip opcache bcmath \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
RUN sed -ri "s#/var/www/html#${APACHE_DOCUMENT_ROOT}#g" /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '<Directory /var/www/app/public>\n  AllowOverride All\n  Require all granted\n  DirectoryIndex index.html index.php\n</Directory>\n' > /etc/apache2/conf-available/app.conf \
    && a2enconf app

# หน้าเว็บ/CSS/JS ให้เบราว์เซอร์ตรวจกับเซิร์ฟเวอร์ทุกครั้ง (ไม่ใช้ของเก่าในแคช) เพื่อให้การอัปเดตหน้าตาขึ้นทันทีหลัง deploy
RUN printf '<FilesMatch "\.(html|css|js)$">
  Header set Cache-Control "no-cache"
</FilesMatch>
' > /etc/apache2/conf-available/nocache.conf     && a2enconf nocache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/app
COPY backend/ ./
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /frontend/ ./public/
RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\x0d$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh
EXPOSE 10000
ENTRYPOINT ["entrypoint.sh"]
