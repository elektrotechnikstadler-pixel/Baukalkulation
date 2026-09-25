#!/usr/bin/env bash
# ============================================================
# Baukalkulation – Interaktives Installations-/Update-Skript
# ============================================================
# Fragt die gewünschten Module ab, schreibt .env und baut die
# Container. Für Linux / NAS (Synology, QNAP, Ubuntu, Debian).
#
# Nutzung:
#   chmod +x install.sh
#   ./install.sh
# ============================================================

set -euo pipefail

# Interaktivitätsprüfung – stdin muss ein Terminal sein
if [ ! -t 0 ]; then
  echo "" >&2
  echo "❌ Fehler: Dieses Skript muss interaktiv in einem Terminal ausgeführt werden." >&2
  echo "   Bitte direkt aufrufen: ./install.sh" >&2
  echo "   Nicht via: curl ... | bash  oder  bash < install.sh" >&2
  echo "" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

ENV_FILE="$SCRIPT_DIR/.env"

echo ""
echo "============================================================"
echo "  Baukalkulation – Installation"
echo "============================================================"
echo ""

# ── Vorhandene .env einlesen (falls Update) ─────────────────
if [ -f "$ENV_FILE" ]; then
    echo "Bestehende .env gefunden – Werte werden als Vorgaben verwendet."
    # shellcheck disable=SC1090
    set -a; source "$ENV_FILE"; set +a
    echo ""
fi

# ── Defaults ────────────────────────────────────────────────
DEF_COMPOSE_PROJECT="${COMPOSE_PROJECT_NAME:-}"
DEF_APP_NAME="${APP_NAME:-}"
DEF_APP_PORT="${APP_PORT:-8081}"
DEF_WA_PORT="${WA_PORT:-3002}"
DEF_OCR="${ENABLE_OCR:-false}"
DEF_WA="${ENABLE_WHATSAPP:-false}"
DEF_WA_TOKEN="${WA_API_TOKEN:-}"

# ── Hilfsfunktionen ─────────────────────────────────────────
ask() {
    # ask "Frage" "default"
    local q="$1" def="${2:-}" ans
    if [ -n "$def" ]; then
        read -r -p "$q [$def]: " ans
        echo "${ans:-$def}"
    else
        read -r -p "$q: " ans
        echo "$ans"
    fi
}

ask_yn() {
    # ask_yn "Frage" "j|n"
    local q="$1" def="$2" ans def_label
    case "$def" in
        j|J|true|yes|y) def_label="J/n"; def="j" ;;
        *)              def_label="j/N"; def="n" ;;
    esac
    read -r -p "$q [$def_label]: " ans
    ans="${ans:-$def}"
    case "$ans" in
        j|J|y|Y|ja|yes|true|1) echo "true" ;;
        *)                      echo "false" ;;
    esac
}

# ── Abfrage ─────────────────────────────────────────────────
echo "── Instanz-Identifikation (pro Installation eindeutig!) ─"
echo "Wenn mehrere Instanzen auf einem Server laufen, müssen diese"
echo "drei Werte für jede Instanz verschieden sein."
echo ""
COMPOSE_PROJECT_NAME="$(ask 'Projektname (z.B. baukalkulation-es)' "${DEF_COMPOSE_PROJECT:-baukalkulation}")" 
APP_NAME="$(ask 'Container-Name (z.B. baukalkulation-es-app)' "${DEF_APP_NAME:-baukalkulation-app}")"
echo ""
echo "── Ports ─────────────────────────────────────────────"
APP_PORT="$(ask 'App-Port (HTTP)' "$DEF_APP_PORT")"

echo ""
echo "── Datenpfad ─────────────────────────────────────────"
echo "Bind-Mount = Daten liegen direkt im Installationsordner."
echo "     → Zugriff über Thunar/Samba/NAS-Backup möglich."
echo "     Named Volume = Docker verwaltet Daten intern (Standard)."
DEF_BINDMOUNT="${USE_BIND_MOUNT:-false}"
def_bm_yn="n"; [ "$DEF_BINDMOUNT" = "true" ] && def_bm_yn="j"
USE_BIND_MOUNT="$(ask_yn 'Bind-Mount verwenden (empfohlen für Thunar/NAS-Backup)?' "$def_bm_yn")"

echo ""
echo "── Module ────────────────────────────────────────────"
echo "OCR = Tesseract + poppler. Erkennt Text in Scans/Fotos."
echo "     Kosten: ~+200 MB Image, ~+200 MB RAM, hohe CPU-Last."
echo "     Für NAS < 4 GB RAM: nicht empfohlen."
def_ocr_yn="n"; [ "$DEF_OCR" = "true" ] && def_ocr_yn="j"
ENABLE_OCR="$(ask_yn 'OCR aktivieren?' "$def_ocr_yn")"

echo ""
echo "WhatsApp-Bridge = Benachrichtigungen via WhatsApp."
echo "     Kosten: ~400–500 MB RAM dauerhaft (eigener Container)."
def_wa_yn="n"; [ "$DEF_WA" = "true" ] && def_wa_yn="j"
ENABLE_WHATSAPP="$(ask_yn 'WhatsApp-Bridge aktivieren?' "$def_wa_yn")"

WA_PORT="$DEF_WA_PORT"
WA_API_TOKEN="$DEF_WA_TOKEN"
if [ "$ENABLE_WHATSAPP" = "true" ]; then
    WA_PORT="$(ask 'WhatsApp-Port' "$DEF_WA_PORT")"
    if [ -z "$WA_API_TOKEN" ] || [ "$WA_API_TOKEN" = "change-me" ]; then
        WA_API_TOKEN="$(ask 'WhatsApp-API-Token (sicheres Geheimnis)' "$(openssl rand -hex 16 2>/dev/null || echo 'bitte-aendern')")"
    else
        WA_API_TOKEN="$(ask 'WhatsApp-API-Token' "$WA_API_TOKEN")"
    fi
fi

# ── .env schreiben ──────────────────────────────────────────
cat > "$ENV_FILE" <<EOF
# Automatisch erzeugt von install.sh am $(date '+%Y-%m-%d %H:%M:%S')
COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME
APP_NAME=$APP_NAME
APP_PORT=$APP_PORT
WA_PORT=$WA_PORT
ENABLE_OCR=$ENABLE_OCR
ENABLE_WHATSAPP=$ENABLE_WHATSAPP
WA_API_TOKEN=$WA_API_TOKEN
USE_BIND_MOUNT=$USE_BIND_MOUNT
EOF

echo ""
echo "── Zusammenfassung ───────────────────────────────────"
echo "  Projektname:      $COMPOSE_PROJECT_NAME"
echo "  Container-Name:   $APP_NAME"
echo "  App-Port:         $APP_PORT"
echo "  OCR:              $ENABLE_OCR"
echo "  WhatsApp:         $ENABLE_WHATSAPP"
[ "$ENABLE_WHATSAPP" = "true" ] && echo "  WhatsApp-Port:    $WA_PORT"
echo "  .env geschrieben: $ENV_FILE"
echo ""

# ── Docker-Compose-Aufruf wählen ────────────────────────────
COMPOSE_CMD="docker compose"
if ! command -v docker >/dev/null 2>&1; then
    echo "FEHLER: 'docker' nicht gefunden. Bitte Docker installieren." >&2
    exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
    if command -v docker-compose >/dev/null 2>&1; then
        COMPOSE_CMD="docker-compose"
    else
        echo "FEHLER: 'docker compose' oder 'docker-compose' nicht gefunden." >&2
        exit 1
    fi
fi

start_now="$(ask_yn 'Jetzt bauen und starten?' 'j')"
if [ "$start_now" != "true" ]; then
    echo "Abbruch. Später starten mit:"
    echo "  $COMPOSE_CMD up -d --build"
    [ "$ENABLE_WHATSAPP" = "true" ] && echo "  $COMPOSE_CMD --profile whatsapp up -d --build"
    exit 0
fi

echo ""
echo "── Build läuft ───────────────────────────────────────"

# ── Bind-Mount vorbereiten ──────────────────────────────────
if [ "$USE_BIND_MOUNT" = "true" ]; then
    DATA_DIR="$SCRIPT_DIR/data"
    echo "Erstelle Datenverzeichnis: $DATA_DIR"
    mkdir -p "$DATA_DIR"/{backups,archiv,exports,bautagebuch,datanorm,uploads}

    # Berechtigungen: www-data (UID 33) als Besitzer, 775 für Ordner
    sudo chown -R 33:33 "$DATA_DIR"
    sudo find "$DATA_DIR" -type d -exec chmod 775 {} \;
    sudo find "$DATA_DIR" -type f -exec chmod 664 {} \;

    # ACLs: aktueller User + www-data dauerhaft Lese-/Schreibzugriff
    if command -v setfacl >/dev/null 2>&1; then
        CURRENT_USER="$(whoami)"
        sudo setfacl -R -m u:"$CURRENT_USER":rwx "$DATA_DIR"
        sudo setfacl -R -d -m u:"$CURRENT_USER":rwx "$DATA_DIR"
        sudo setfacl -R -d -m u:33:rwx "$DATA_DIR"
        echo "ACLs gesetzt für $CURRENT_USER und www-data (UID 33)."
    else
        echo "HINWEIS: 'setfacl' nicht gefunden. ACL-Paket installieren: sudo apt install acl"
    fi

    # docker-compose.yml: Named Volumes → Bind Mounts
    if grep -q 'app-data:/var/www/html/data' docker-compose.yml 2>/dev/null; then
        sed -i "s|- app-data:/var/www/html/data|- $DATA_DIR:/var/www/html/data|g" docker-compose.yml
        sed -i '/- app-uploads:/d' docker-compose.yml
        echo "docker-compose.yml auf Bind-Mount umgestellt."
    fi

    # www-data Gruppe für aktuellen User (einmalig)
    if ! groups "$(whoami)" | grep -q www-data; then
        sudo usermod -aG www-data "$(whoami)"
        echo ""
        echo "WICHTIG: Bitte neu anmelden damit www-data Gruppe aktiv wird!"
        echo "         (SSH schließen + neu verbinden, oder: newgrp www-data)"
    fi
fi

if [ "$ENABLE_WHATSAPP" = "true" ]; then
    $COMPOSE_CMD --profile whatsapp up -d --build
else
    $COMPOSE_CMD up -d --build
fi

echo ""
echo "── Rechte setzen ─────────────────────────────────────"
if [ "$USE_BIND_MOUNT" = "true" ]; then
    echo "Bind-Mount aktiv – Rechte bereits gesetzt."
else
    $COMPOSE_CMD exec -T app bash -c \
        'chown -R www-data:www-data /var/www/html/data && chmod -R 775 /var/www/html/data' \
        2>/dev/null || echo "(Rechte-Setzung übersprungen – Container noch nicht bereit)"
fi

echo ""
echo "============================================================"
echo "  Fertig. App erreichbar unter:"
echo "    http://<server-ip>:$APP_PORT"
echo "  Standard-Login: admin / admin  (bitte sofort ändern!)"
echo "============================================================"
