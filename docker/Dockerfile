# محیط توسعه و تست؛ تصمیم استقرار روی سرور واقعی جدا گرفته می‌شود.

# ---------- برنامه روی PHP-FPM با همان پول‌های پروژه ----------
FROM php:8.5-fpm AS app
RUN docker-php-ext-install pdo_mysql
COPY docker/php.ini /usr/local/etc/php/conf.d/chatroom.ini
COPY deploy/php-fpm-chatroom.conf.example /tmp/pools.conf
RUN rm -f /usr/local/etc/php-fpm.d/*.conf \
    && printf '[global]\ndaemonize = no\nerror_log = /proc/self/fd/2\n' > /usr/local/etc/php-fpm.d/00-global.conf \
    && sed -e 's#listen = /run/php/chatroom.sock#listen = 9000#' \
           -e 's#listen = /run/php/chatroom-sse.sock#listen = 9001#' \
           -e '/^listen\.\(owner\|group\)/d' \
           -e 's/^group = www-data/group = www-data\nclear_env = no\ncatch_workers_output = yes/' \
           /tmp/pools.conf > /usr/local/etc/php-fpm.d/chatroom.conf
COPY . /var/www/chatroom
WORKDIR /var/www/chatroom
RUN mkdir -p uploads logs && chown -R www-data:www-data uploads logs && chmod 750 uploads logs

# ---------- Nginx با همان پیکربندی پروژه ----------
# بلوک ریدایرکت و خطوط TLS حذف می‌شوند؛ بقیه قوانین دقیقاً همان فایل deploy است
FROM nginx:stable AS web
COPY deploy/nginx-chatroom.conf.example /tmp/chatroom.conf
RUN sed -e '0,/^}/d' \
        -e 's/listen 443 ssl;/listen 80;/' \
        -e '/ssl_/d' \
        -e '/http2 on;/d' \
        -e 's#unix:/run/php/chatroom-sse.sock#fpm:9001#' \
        -e 's#unix:/run/php/chatroom.sock#fpm:9000#' \
        /tmp/chatroom.conf > /etc/nginx/conf.d/default.conf
COPY . /var/www/chatroom

# ---------- تست‌ها با کلاینت رسمی MySQL ----------
FROM app AS tests
COPY --from=mysql:9.7 /usr/bin/mysql /usr/bin/mysqldump /usr/bin/
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends libncurses6 > /dev/null \
    && rm -rf /var/lib/apt/lists/*
