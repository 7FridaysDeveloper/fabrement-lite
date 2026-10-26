# The web server of the plugin's test sites: PHP + Apache serving WordPress the way a host does —
# HTTPS, WordPress's own rewrite rules (with the Authorization header passed on, as its .htaccess
# does), opcache, and WP-CLI for what a site runs outside a page (cron, an import, `wp eval`).
# Each site gets its own wp-config.php and a volume its code is synced into (tests/Site/docker-compose.yml).
FROM php:8.2-apache
RUN echo "ServerName example.org" > /etc/apache2/conf-enabled/servername.conf

RUN apt-get update \
    && apt-get install -y --no-install-recommends openssl less default-mysql-client rsync \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install mysqli opcache \
    && a2enmod rewrite headers ssl \
    && curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp \
    && openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
        -subj "/CN=example.org" -addext "subjectAltName=DNS:example.org" \
        -keyout /etc/ssl/private/site.key -out /etc/ssl/certs/site.crt

# Files change between requests (an agent saves a page bundle): opcache checks them every time.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.max_accelerated_files=50000'; \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=32M'; \
        echo 'post_max_size=32M'; \
    } > /usr/local/etc/php/conf.d/site.ini

COPY apache.conf /etc/apache2/sites-available/000-default.conf
