# Update-Sidecar der Baukalkulation (siehe updater.sh, docker-compose.yml Dienst "updater").
# Docker-CLI + Compose-Plugin; nur Linux-Docker-Hosts.
FROM docker:27-cli

RUN apk add --no-cache curl jq \
    && docker compose version

COPY updater.sh /usr/local/bin/updater.sh
RUN chmod 0755 /usr/local/bin/updater.sh

# Gesund, solange der Heartbeat (alle 15 s, root-eigene reguläre Datei) jünger als 2 Minuten ist.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD test -n "$(find /update/updater.json -maxdepth 0 -type f -user 0 -mmin -2 2>/dev/null)"

ENTRYPOINT ["/usr/local/bin/updater.sh"]
