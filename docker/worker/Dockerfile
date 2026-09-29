FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        ffmpeg \
        python3 \
        python3-pip \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath zip intl pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && pip3 install --no-cache-dir --break-system-packages faster-whisper yt-dlp \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Subtitle font for FfmpegVideoRenderer/libass (config('render.subtitles.font')).
# Oswald is a variable font shipping Bold/SemiBold as named instances, which
# fontconfig exposes as styles under the single "Oswald" family — matching
# the ASS style's Fontname=Oswald + Bold=-1 already set by AssSubtitleFormatter.
RUN mkdir -p /usr/share/fonts/truetype/oswald \
    && curl -sL "https://github.com/google/fonts/raw/main/ofl/oswald/Oswald%5Bwght%5D.ttf" \
        -o /usr/share/fonts/truetype/oswald/Oswald-Variable.ttf \
    && fc-cache -f

# Default 128M is too tight for downloading full-size stock video/image
# assets in CollectVideoAssetsService before they're streamed to disk — hit
# "Allowed memory size exhausted" fatal errors that killed the whole
# queue:work process (container exit), stalling every video behind it.
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory-limit.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache

RUN composer install --no-dev --optimize-autoloader --no-interaction

CMD ["php", "artisan", "queue:work", "--queue=render,whisper,default", "--sleep=3", "--tries=3"]
