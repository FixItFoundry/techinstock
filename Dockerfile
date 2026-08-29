FROM php:8.3-cli

ENV PRODUCTS_FILE=/data/products.json \
    PHP_CLI_SERVER_WORKERS=1

WORKDIR /srv

# Chromium is required for the Micro Center scraper (Akamai-protected pages
# need a real browser to render the DOM).
RUN apt-get update \
    && apt-get install -y --no-install-recommends chromium \
    && rm -rf /var/lib/apt/lists/*

COPY . /srv

RUN mkdir -p /data

EXPOSE 8000

COPY <<'EOF' /entrypoint.sh
#!/bin/sh
set -e
if [ ! -f "$PRODUCTS_FILE" ]; then
  echo "Seeding default product catalog at $PRODUCTS_FILE"
  cp /srv/products.json "$PRODUCTS_FILE"
fi
exec php -S 0.0.0.0:8000 /srv/index.php
EOF
RUN chmod +x /entrypoint.sh

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php -r "exit(@file_get_contents('http://127.0.0.1:8000/healthz') !== false ? 0 : 1);"

CMD ["/entrypoint.sh"]
