# Krayin CRM with the TXN edits.
#
# Official Krayin image (version pinned in ../KRAYIN_VERSION, passed in by
# scripts/deploy.sh) + the TXN files in overlay/ copied on top.
# Build from the repo root:
#   docker build --build-arg KRAYIN_BASE="$(cat KRAYIN_VERSION)" -f docker/Dockerfile .

ARG KRAYIN_BASE=webkul/krayin:2.2.0@sha256:5a5e6e86bdede8ecd72db7805c4a471c9111513efae95de3e8c80a7bb0e517b9
FROM ${KRAYIN_BASE}

# The stock image can run MySQL inside the container; here MySQL is its own
# container (krayin-mysql), so the internal one never starts.
ENV MYSQL_AUTOSTART=false \
    TZ=UTC

COPY --chown=www-data:www-data overlay/ /var/www/laravel-crm/
COPY --chown=www-data:www-data storage-seed/ /opt/txn/storage-seed/
COPY docker/entrypoint.sh /usr/local/bin/txn-entrypoint.sh

# - .env is mounted at runtime (never baked in; the stock one has a shared APP_KEY)
# - leftovers that must not ship: backup copies and a saved page in public/
# - no cached config/routes from the stock build
RUN chmod 0755 /usr/local/bin/txn-entrypoint.sh \
 && cd /var/www/laravel-crm \
 && rm -f .env public/zz-ask.html bootstrap/cache/config.php bootstrap/cache/routes-v7.php \
 && find app config packages routes resources public -type f -name '*.bak*' -delete

# nginx in the stock image answers /health with "OK".
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD curl -fsS http://127.0.0.1/health >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/txn-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
