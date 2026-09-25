# ============================================================
# Stage 1: Frontend-Build (Node.js / Vite)
# ============================================================
# Erzeugt public/dist/core.js + public/dist/<modul>.js aus src/frontend/ und
# modules/*/frontend/.  Das Ergebnis wird in Stage 2 übernommen.
# public/dist/ ist in .gitignore/.dockerignore – immer frisch aus dem Source.
# ============================================================
FROM node:lts-alpine AS frontend-builder
WORKDIR /build
COPY package.json package-lock.json ./
COPY vite.config.js VERSION ./
COPY src/frontend/ ./src/frontend/
COPY modules/ ./modules/
# Versions-Abgleich (Cache-Busting ?v=NN + App-Version): index.html <-> sw.js <-> script.js
# <-> mobile.html, VERSION <-> manifest.json <-> package.json. Nur für die Prüfung benötigt.
COPY scripts/check-versions.mjs ./scripts/check-versions.mjs
COPY public/index.html public/sw.js public/script.js public/mobile.html public/manifest.json ./public/
RUN npm ci --no-audit --prefer-offline \
    && node scripts/check-versions.mjs \
    && npm run build

# ============================================================
# Stage 2: PHP-Applikation (Apache)
# ============================================================
FROM php:8.3-apache

# ============================================================
# Build-Flags für modulare Installation
# ============================================================
# ENABLE_OCR=true  → Tesseract + poppler installieren (~+200 MB Image,
#                    ~+200 MB RAM-Peak, hohe CPU-Last beim Parsen)
# ENABLE_OCR=false → nur Dateiname-basierte Dokumentenerkennung.
#                    Für kleine NAS / schwache Hardware empfohlen.
#
# WhatsApp-Bridge läuft als eigener Container (siehe docker-compose.yml,
# Profil "whatsapp") und ist standardmäßig deaktiviert.
#
# Die HiCAD-Rohbibliothek ("Materialbibliothek HiCAD/") wird per
# .dockerignore nicht ins Image kopiert. Stattdessen kommt die schlanke
# CSV-Übersicht (Materialbibliothek_Uebersicht.csv) zum Einsatz.
# ============================================================
ARG ENABLE_OCR=true
ENV APP_ENABLE_OCR=${ENABLE_OCR}

# System-Dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    libsqlite3-dev libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
    libfreetype6-dev libonig-dev libxml2-dev unzip git cron \
    && if [ "$ENABLE_OCR" = "true" ]; then \
       apt-get install -y --no-install-recommends \
       tesseract-ocr tesseract-ocr-deu tesseract-ocr-eng poppler-utils; \
    fi \
    && apt-get install -y --no-install-recommends tzdata \
    && ln -snf /usr/share/zoneinfo/Europe/Berlin /etc/localtime \
    && echo 'Europe/Berlin' > /etc/timezone \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_sqlite pdo_pgsql mbstring fileinfo gd zip xml exif \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache-Module: mod_rewrite + mod_headers.
# mod_headers wird von .htaccess für die Sicherheits-Header (CSP, X-Frame-Options,
# Permissions-Policy …) benötigt – ohne dieses Modul wird der <IfModule mod_headers.c>
# Block stillschweigend übersprungen.
RUN a2enmod rewrite headers

# .htaccess aktivieren: Das Basis-Image php:8.2-apache setzt im DocumentRoot
# standardmäßig "AllowOverride None", wodurch ALLE .htaccess-Dateien ignoriert
# werden. Dadurch wären die Security-Header (CSP) UND – sicherheitskritisch –
# die Zugriffssperren für data/*.sqlite / *.json / *.log inaktiv (direkter
# Download der Datenbank möglich). AllowOverride All stellt diese Schutzregeln
# wieder her.
RUN sed -ri '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# PHP-Konfiguration
RUN echo "upload_max_filesize = 100M\npost_max_size = 110M\nmax_execution_time = 120\nmax_input_time = 300\nmemory_limit = 256M\nopcache.enable=1\nopcache.memory_consumption=128\nopcache.max_accelerated_files=4000\nopcache.validate_timestamps=0\ndate.timezone = Europe/Berlin\nsession.gc_maxlifetime = 2592000\nsession.cookie_lifetime = 2592000" \
    > /usr/local/etc/php/conf.d/custom.ini \
    && docker-php-ext-install opcache

# Arbeitsverzeichnis
WORKDIR /var/www/html

# Composer-Dependencies zuerst (Layer-Caching)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Dann restliche Dateien kopieren
COPY --chown=www-data:www-data . .

# Frontend-Assets aus Stage 1 (public/dist/ ist gitignored, wird immer frisch gebaut)
COPY --from=frontend-builder --chown=www-data:www-data /build/public/dist ./public/dist/

# Data-Verzeichnis vorbereiten
RUN mkdir -p data && chown -R www-data:www-data data && chmod -R 775 data

# Apache DocumentRoot = public/. Code (src/, vendor/, modules/) und data/ liegen
# außerhalb und sind damit nicht per HTTP erreichbar – unabhängig von .htaccess.
# Die Projektwurzel bleibt /var/www/html (Cron-Pfade + Volumes unverändert).
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

# ── Cron-Daemon: automatische Wartungsskripte ────────────────
# Ohne diese Crontab werden cron_backup_email.php, cron_material_erinnerung.php
# und cron_stunden_erinnerung.php NIE automatisch ausgeführt (der Container
# startet sonst nur Apache). Die Skripte prüfen selbst Uhrzeit + Lock-File und
# handeln nur, wenn fällig – ein 15-Minuten-Takt ist daher unkritisch.
# Lauf als www-data, damit Schreibzugriff auf data/ (Locks, Logs) passt.
# cron gibt die Container-Umgebung nicht weiter: der Entrypoint legt die BK_*-Variablen
# (z. B. Datenbank-Zugang) in /etc/baukalkulation.env ab, jede Zeile lädt sie.
RUN printf '%s\n' \
    'SHELL=/bin/sh' \
    'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' \
    '*/15 * * * * www-data . /etc/baukalkulation.env; php /var/www/html/cron_backup_email.php >> /var/www/html/data/cron.log 2>&1' \
    '*/15 * * * * www-data . /etc/baukalkulation.env; php /var/www/html/cron_material_erinnerung.php >> /var/www/html/data/cron.log 2>&1' \
    '*/15 * * * * www-data . /etc/baukalkulation.env; php /var/www/html/cron_stunden_erinnerung.php >> /var/www/html/data/cron.log 2>&1' \
    '*/15 * * * * www-data . /etc/baukalkulation.env; php /var/www/html/cron_stundenauswertung_email.php >> /var/www/html/data/cron.log 2>&1' \
    > /etc/cron.d/baukalkulation-cron \
    && chmod 0644 /etc/cron.d/baukalkulation-cron

# Entrypoint: führt migrate.php bei jedem Container-Start aus (nach Volume-Mount),
# damit neue Tabellen/Spalten automatisch in die gemountete DB übernommen werden.
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
