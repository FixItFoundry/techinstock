FROM php:8.3-apache

ENV PRODUCTS_FILE=/data/products.json

# Chromium is required for the Micro Center scraper (Akamai-protected pages
# need a real browser to render the DOM).
RUN apt-get update \
    && apt-get install -y --no-install-recommends chromium \
    && rm -rf /var/lib/apt/lists/*

# Serve the app from the default Apache docroot so /static/* is handled
# natively (concurrent, cached) and only routes fall through to index.php.
COPY . /var/www/html

# Front-controller routing: any request that does not map to a real file
# (pages, /api/*, etc.) is handled by index.php. Static assets on disk are
# served directly by Apache — no PHP worker involved, so a slow Apple fetch
# can never block CSS/JS.
RUN cat > /etc/apache2/sites-available/000-default.conf <<'EOF'
<VirtualHost *:80>
    DocumentRoot /var/www/html
    <Directory /var/www/html>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
        FallbackResource /index.php
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

# Don't let browsers cache 404s or stale assets across deploys.
RUN echo '<IfModule mod_headers.c>\n    <FilesMatch "\.(css|js)$">\n        Header set Cache-Control "max-age=60"\n    </FilesMatch>\n</IfModule>' >> /etc/apache2/conf-available/zz-static-cache.conf \
    && a2enconf zz-static-cache

RUN mkdir -p /data

# Never leak PHP warnings into API responses (a failed cache write used to
# corrupt the JSON body and break the frontend). Log them instead.
RUN echo 'display_errors=0\nlog_errors=1\nerror_log=/var/log/apache2/php-error.log' > /usr/local/etc/php/conf.d/zz-errors.ini

EXPOSE 80

COPY <<'EOF' /entrypoint.sh
#!/bin/sh
set -e
# The catalog lives in a bind-mounted /data owned by the host user. Under
# rootless container runtimes the worker uid can't always be chowned onto the
# mount, so make it world-writable to guarantee the www-data worker can write
# caches + the editable catalog.
chown -R www-data:www-data /data 2>/dev/null || true
chmod -R 777 /data 2>/dev/null || true
if [ ! -f "$PRODUCTS_FILE" ]; then
  echo "Seeding default product catalog at $PRODUCTS_FILE"
  cp /var/www/html/products.json "$PRODUCTS_FILE"
  chmod 666 "$PRODUCTS_FILE"
fi
exec apache2-foreground
EOF
RUN chmod +x /entrypoint.sh

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php -r "exit(@file_get_contents('http://127.0.0.1/healthz') !== false ? 0 : 1);"

CMD ["/entrypoint.sh"]
