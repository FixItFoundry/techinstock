FROM python:3.12-slim

# Don't write .pyc, don't buffer stdout
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /srv

# Install deps first for better layer caching
COPY requirements.txt .
RUN pip install -r requirements.txt

# Copy application
COPY app ./app

# Persistent catalog lives outside the image so users can mount a volume.
# Default catalog is baked in at /srv/app/products.json; copy on first run
# if the mounted location is empty.
ENV PRODUCTS_FILE=/data/products.json
RUN mkdir -p /data

EXPOSE 8000

# Use a small entrypoint so the bundled default catalog seeds the volume on
# first boot, then exec into uvicorn.
COPY <<'EOF' /entrypoint.sh
#!/bin/sh
set -e
if [ ! -f "$PRODUCTS_FILE" ]; then
  echo "Seeding default product catalog at $PRODUCTS_FILE"
  cp /srv/app/products.json "$PRODUCTS_FILE"
fi
exec uvicorn app.main:app --host 0.0.0.0 --port 8000
EOF
RUN chmod +x /entrypoint.sh

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/healthz', timeout=3).status==200 else 1)"

CMD ["/entrypoint.sh"]
