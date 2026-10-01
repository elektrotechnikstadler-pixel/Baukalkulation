#!/bin/sh
# Update-Sidecar der Baukalkulation (AP-20261001-update, Stufe 2).
# Verarbeitet /update/anforderung/request.json der App: prüft die Zielversion gegen die
# GitHub-Releases, zieht das Image, sichert, ersetzt den App-Container und
# fällt bei Fehler auf den vorigen Tag zurück. Nur Linux-Docker-Hosts.
# In status.json stehen nur feste Meldungen; Rohausgaben nur in update.log.
# Rechte: /update gehört root (0755, App liest nur); www-data schreibt nur in anforderung/.
set -eu
umask 022

UPDATER_VERSION=1
DIR=/update
REQ_DIR=$DIR/anforderung
REQ=$REQ_DIR/request.json
REQ_MAX_BYTES=4096
REQ_MAX_ALTER=600
MARKER=$DIR/.updater-eigentum
WORK_MAX=20
LOGF=$DIR/update.log
COMPOSE_MOUNT=/compose
ENV_FILE=$COMPOSE_MOUNT/.env
APP=${BK_UPDATE_APP_CONTAINER:-baukalkulation-es-app}
REPO=${BK_UPDATE_REPO:-}
COMPOSE_DIR_ENV=${BK_COMPOSE_DIR:-}
case ${BK_UPDATER_DRY_RUN:-0} in
    1 | true | yes) DRY_RUN=1 ;;
    *) DRY_RUN=0 ;;
esac
WWW_UID=33 # www-data im App-Image (Debian)
POLL=5
HEARTBEAT=15
LOG_MAX=1048576
T_PULL=900
T_BACKUP=600
T_UP=300
T_HEALTH=420
NL='
'

REQ_ID=""
V_ALT=""
V_ZIEL=""
FEHLER=""
PROJECT=""
WD=""
CFG_FILES=""
APP_DATA=""
SICHERUNG=""
AUSTAUSCH_UNGUELTIG=0

MSG_AUSTAUSCH_UNGUELTIG="Austauschverzeichnis ungültig"
MSG_NICHT_KONFIGURIERT="Update-Quelle nicht eingerichtet: BK_UPDATE_REPO in .env setzen (owner/name, für Docker in Kleinbuchstaben)."
MSG_NICHT_UNTERSTUETZT="Automatisches Update wird unter Docker Desktop/Windows nicht unterstützt – bitte manuell aktualisieren."

# kein_link <pfad> – nie einem Symlink in /update folgen
kein_link() {
    if [ -L "$1" ]; then
        rm -f "$1"
    fi
}

# eigene_datei <pfad> – in $DIR nur root-eigene Dateien ohne weitere Hardlinks beschreiben, sonst entfernen
eigene_datei() {
    kein_link "$1"
    case $1 in "$DIR"/*) ;; *) return 0 ;; esac
    if [ -e "$1" ] && [ -z "$(find "$1" -maxdepth 0 -type f -user 0 -links 1 2>/dev/null)" ]; then
        rm -f "$1"
    fi
}

log() {
    eigene_datei "$LOGF"
    if [ -f "$LOGF" ] && [ "$(wc -c <"$LOGF")" -gt "$LOG_MAX" ]; then
        mv -f "$LOGF" "$LOGF.1"
    fi
    _line="$(date '+%Y-%m-%d %H:%M:%S') $*"
    printf '%s\n' "$_line" >>"$LOGF"
    printf '%s\n' "$_line" >&2
}

# write_json <ziel> <jq-Argumente…> – atomar über mktemp + mv im selben Verzeichnis
write_json() {
    _target=$1
    shift
    kein_link "$_target"
    _tmp=$(mktemp "$DIR/.json.XXXXXX") || return 1
    if jq -n "$@" >"$_tmp" && chmod 0644 "$_tmp" && mv -f "$_tmp" "$_target"; then
        return 0
    fi
    rm -f "$_tmp"
    return 1
}

# Wartungsmarker erneuern, damit die App ihn während langer Phasen nicht als verwaist (> 30 min) wertet
wartung_erneuern() {
    if [ -f "$DIR/maintenance" ] && [ ! -L "$DIR/maintenance" ]; then
        touch "$DIR/maintenance"
    fi
}

# status <phase> <ergebnis|""> <feste Meldung>
status() {
    wartung_erneuern
    # shellcheck disable=SC2016 # jq-Variablen
    write_json "$DIR/status.json" \
        --arg id "$REQ_ID" --arg phase "$1" --arg alt "$V_ALT" --arg ziel "$V_ZIEL" \
        --arg erg "$2" --arg msg "$3" --argjson ts "$(date +%s)" \
        '{id: (if $id == "" then null else $id end), phase: $phase,
          version_alt: (if $alt == "" then null else $alt end),
          version_ziel: (if $ziel == "" then null else $ziel end),
          ergebnis: (if $erg == "" then null else $erg end),
          meldung: $msg, ts: $ts}'
    log "Status: $1${2:+ ($2)} – $3"
}

# fail <feste Meldung> – schließt die Anforderung als fehlgeschlagen ab
fail() {
    status fehlgeschlagen fehler "$1"
}

single_line() {
    case $1 in *"$NL"*) return 1 ;; esac
    return 0
}

valid_repo() {
    single_line "$1" || return 1
    case $1 in *..*) return 1 ;; esac
    printf '%s\n' "$1" | grep -Eq '^[a-z0-9-]+/[a-z0-9_-][a-z0-9._-]*$'
}

valid_version() {
    single_line "$1" || return 1
    printf '%s\n' "$1" | grep -Eq '^[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}$'
}

is_windows_path() {
    case $1 in [A-Za-z]:*) return 0 ;; esac
    return 1
}

# version_gt <a> <b> – a > b (beide bereits validiert)
version_gt() {
    _a1=${1%%.*}
    _ar=${1#*.}
    _a2=${_ar%%.*}
    _a3=${_ar#*.}
    _b1=${2%%.*}
    _br=${2#*.}
    _b2=${_br%%.*}
    _b3=${_br#*.}
    if [ "$_a1" -gt "$_b1" ]; then return 0; elif [ "$_a1" -lt "$_b1" ]; then return 1; fi
    if [ "$_a2" -gt "$_b2" ]; then return 0; elif [ "$_a2" -lt "$_b2" ]; then return 1; fi
    [ "$_a3" -gt "$_b3" ]
}

label_of() {
    docker inspect -f "{{with index .Config.Labels \"$2\"}}{{.}}{{end}}" "$1" 2>>"$LOGF"
}

mount_source() {
    docker inspect -f "{{range .Mounts}}{{if eq .Destination \"$2\"}}{{.Source}}{{end}}{{end}}" "$1" 2>>"$LOGF"
}

# env_value <KEY> – letzter Wert aus der .env des Projekts, ohne Anführungszeichen
env_value() {
    sed -n "s/^[[:space:]]*$1=//p" "$ENV_FILE" | tail -n 1 | tr -d "\"'\r"
}

current_version() {
    docker exec "$APP" cat /var/www/html/VERSION 2>>"$LOGF" | tr -d '[:space:]'
}

release_versions() {
    _json=$(curl -fsS --proto '=https' --max-time 20 \
        -H 'Accept: application/vnd.github+json' \
        -H "User-Agent: baukalkulation-updater/$UPDATER_VERSION" \
        "https://api.github.com/repos/$REPO/releases?per_page=30" 2>>"$LOGF") || return 1
    printf '%s' "$_json" | jq -r '.[]
        | select((.draft | not) and (.prerelease | not))
        | .tag_name
        | select(test("^v[0-9]+\\.[0-9]+\\.[0-9]+$"))
        | ltrimstr("v")' 2>>"$LOGF"
}

umgebung_zustand() {
    if [ "$AUSTAUSCH_UNGUELTIG" = 1 ]; then
        echo fehler
        return 0
    fi
    if [ -z "$REPO" ] || ! valid_repo "$REPO"; then
        echo nicht_konfiguriert
        return 0
    fi
    if is_windows_path "$COMPOSE_DIR_ENV"; then
        echo nicht_unterstuetzt
        return 0
    fi
    _wd=$(docker inspect -f '{{with index .Config.Labels "com.docker.compose.project.working_dir"}}{{.}}{{end}}' "$APP" 2>/dev/null || true)
    if is_windows_path "$_wd"; then
        echo nicht_unterstuetzt
        return 0
    fi
    echo bereit
}

heartbeat_loop() {
    if [ "$DRY_RUN" = 1 ]; then _dry=true; else _dry=false; fi
    while :; do
        # shellcheck disable=SC2016 # jq-Variablen
        write_json "$DIR/updater.json" \
            --argjson ts "$(date +%s)" --arg v "$UPDATER_VERSION" --argjson dry "$_dry" \
            --arg zustand "$(umgebung_zustand)" \
            '{ts: $ts, updater_version: $v, dry_run: $dry, zustand: $zustand}' || true
        sleep "$HEARTBEAT"
    done
}

# run <befehl…> – im Trockenlauf nur protokollieren
run() {
    if [ "$DRY_RUN" = 1 ]; then
        log "TROCKENLAUF: $*"
        return 0
    fi
    log "Ausführen: $*"
    "$@" >>"$LOGF" 2>&1
}

# compose <timeout> <args…> – Projekt/Dateien aus den Labels, bereinigte Umgebung
compose() {
    _t=$1
    shift
    _n=$#
    _ifs=$IFS
    IFS=,
    set -f
    for _f in $CFG_FILES; do
        set -- "$@" -f "$_f"
    done
    IFS=$_ifs
    set +f
    while [ "$_n" -gt 0 ]; do
        set -- "$@" "$1"
        shift
        _n=$((_n - 1))
    done
    log "Ausführen: docker compose -p $PROJECT --project-directory $WD $*"
    timeout "$_t" env -i PATH="$PATH" HOME=/root \
        docker compose -p "$PROJECT" --project-directory "$WD" "$@"
}

# app_up – App-Container mit dem Tag aus .env neu erzeugen (ohne Build/Pull)
app_up() {
    if [ "$DRY_RUN" = 1 ]; then
        log "TROCKENLAUF: docker compose -p $PROJECT --project-directory $WD up -d --no-deps --no-build --pull never app"
        return 0
    fi
    compose "$T_UP" up -d --no-deps --no-build --pull never app >>"$LOGF" 2>&1
}

wartung_an() {
    if [ "$DRY_RUN" = 1 ]; then
        log "TROCKENLAUF: Wartungsmodus an"
        return 0
    fi
    eigene_datei "$DIR/maintenance"
    date +%s >"$DIR/maintenance"
}

wartung_aus() {
    rm -f "$DIR/maintenance"
}

set_env_tag() {
    if [ "$DRY_RUN" = 1 ]; then
        log "TROCKENLAUF: .env APP_IMAGE_TAG=$1"
        return 0
    fi
    cp -p "$ENV_FILE" "$ENV_FILE.bak-update" || return 1
    _tmp="$ENV_FILE.tmp-update"
    cp -p "$ENV_FILE" "$_tmp" || return 1
    if ! awk -v t="$1" '
        /^[[:space:]]*APP_IMAGE_TAG=/ { if (!d) { print "APP_IMAGE_TAG=" t; d = 1 } next }
        { print }
        END { if (!d) print "APP_IMAGE_TAG=" t }' "$ENV_FILE" >"$_tmp"; then
        rm -f "$_tmp"
        return 1
    fi
    mv -f "$_tmp" "$ENV_FILE" || return 1
    log ".env: APP_IMAGE_TAG=$1 (vorher in .env.bak-update)"
}

restore_env() {
    if [ "$DRY_RUN" = 1 ]; then
        log "TROCKENLAUF: .env aus .env.bak-update zurück"
        return 0
    fi
    [ -f "$ENV_FILE.bak-update" ] || return 1
    cp -p "$ENV_FILE.bak-update" "$ENV_FILE.tmp-update" || return 1
    mv -f "$ENV_FILE.tmp-update" "$ENV_FILE" || return 1
    log ".env aus .env.bak-update wiederhergestellt"
}

# wait_healthy <erwartete Version> <Limit s> – Health "healthy" und VERSION gleich
wait_healthy() {
    if [ "$DRY_RUN" = 1 ]; then
        log "TROCKENLAUF: warte auf Gesundheit und Version $1"
        return 0
    fi
    _ende=$(($(date +%s) + $2))
    _h=""
    _v=""
    while [ "$(date +%s)" -lt "$_ende" ]; do
        _h=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$APP" 2>/dev/null || true)
        if [ "$_h" = healthy ]; then
            _v=$(current_version || true)
            if [ "$_v" = "$1" ]; then
                log "App gesund, Version $_v"
                return 0
            fi
        fi
        wartung_erneuern
        sleep 5
    done
    log "App nach $2 s nicht bereit (Status ${_h:-unbekannt}, Version ${_v:-unbekannt}, erwartet $1)"
    return 1
}

# Projekt aus den Labels des App-Containers; Projektverzeichnis muss dem
# eingebundenen /compose entsprechen und wird unter dem Host-Pfad verknüpft,
# damit relative Bind-Pfade (./data, ./secrets) auf dem Host gleich auflösen.
pruefe_projekt() {
    PROJECT=$(label_of "$APP" com.docker.compose.project) || PROJECT=""
    WD=$(label_of "$APP" com.docker.compose.project.working_dir) || WD=""
    CFG_FILES=$(label_of "$APP" com.docker.compose.project.config_files) || CFG_FILES=""
    _envf=$(label_of "$APP" com.docker.compose.project.environment_file) || _envf=""

    if is_windows_path "$WD" || is_windows_path "$COMPOSE_DIR_ENV"; then
        FEHLER=$MSG_NICHT_UNTERSTUETZT
        return 1
    fi
    FEHLER="App-Container ist nicht mit Docker Compose gestartet oder nicht erreichbar."
    if [ -z "$PROJECT" ] || [ -z "$WD" ] || [ -z "$CFG_FILES" ]; then return 1; fi
    single_line "$PROJECT$WD$CFG_FILES" || return 1
    printf '%s\n' "$PROJECT" | grep -Eq '^[a-z0-9][a-z0-9_-]*$' || return 1

    FEHLER="Projektverzeichnis des App-Containers ist ungültig."
    case $WD in /) return 1 ;; /*) ;; *) return 1 ;; esac
    case $WD in */../* | */.. | */./* | */.) return 1 ;; esac

    FEHLER="Projektverzeichnis des Updaters passt nicht zum App-Container (BK_COMPOSE_DIR prüfen)."
    _self=$(hostname)
    [ "$(mount_source "$_self" "$COMPOSE_MOUNT")" = "$WD" ] || return 1

    FEHLER="Datenverzeichnis des Updaters passt nicht zum App-Container (DATA_DIR prüfen)."
    _data=$(mount_source "$APP" /var/www/html/data)
    [ -n "$_data" ] || return 1
    [ "$(mount_source "$_self" "$DIR")" = "$_data/update" ] || return 1
    APP_DATA=$_data

    FEHLER=".env fehlt im Projektverzeichnis."
    [ -f "$ENV_FILE" ] || return 1
    FEHLER="Abweichende env-Datei (--env-file) wird nicht unterstützt."
    [ -z "$_envf" ] || [ "$_envf" = "$WD/.env" ] || return 1

    FEHLER="Projektverzeichnis kann im Updater nicht verknüpft werden."
    if [ "$WD" != "$COMPOSE_MOUNT" ]; then
        if [ -L "$WD" ]; then
            [ "$(readlink "$WD")" = "$COMPOSE_MOUNT" ] || return 1
        elif [ -e "$WD" ]; then
            return 1
        else
            mkdir -p "$(dirname "$WD")" || return 1
            ln -s "$COMPOSE_MOUNT" "$WD" || return 1
        fi
    fi

    FEHLER="Compose-Dateien des App-Containers liegen nicht im Projektverzeichnis."
    _ifs=$IFS
    IFS=,
    set -f
    for _f in $CFG_FILES; do
        case $_f in "$WD"/*) ;; *)
            IFS=$_ifs
            set +f
            return 1
            ;;
        esac
        if [ ! -f "$_f" ]; then
            IFS=$_ifs
            set +f
            return 1
        fi
    done
    IFS=$_ifs
    set +f
    FEHLER=""
    return 0
}

rueckfall() {
    status rueckfall "" "Neue Version nicht bereit – Rückfall auf Version $V_ALT."
    if restore_env &&
        app_up &&
        wait_healthy "$V_ALT" "$T_HEALTH"; then
        wartung_aus
        status rueckfall rueckfall "Update fehlgeschlagen – bisherige Version $V_ALT läuft wieder."
    else
        wartung_aus
        status manuell manuell "Update und Rückfall fehlgeschlagen – manuell eingreifen: Sicherung $SICHERUNG (data/backups) mit bin/console backup:import einspielen, siehe Anleitung."
    fi
}

ablauf() {
    status pruefe "" "Anforderung wird geprüft."
    case $(umgebung_zustand) in
        nicht_konfiguriert) fail "$MSG_NICHT_KONFIGURIERT"; return 0 ;;
        nicht_unterstuetzt) fail "$MSG_NICHT_UNTERSTUETZT"; return 0 ;;
    esac
    if ! pruefe_projekt; then
        fail "$FEHLER"
        return 0
    fi

    V_ALT=$(current_version || true)
    if ! valid_version "$V_ALT"; then
        V_ALT=""
        fail "Installierte Version der App nicht ermittelbar."
        return 0
    fi
    if ! _releases=$(release_versions); then
        fail "Release-Liste von GitHub nicht abrufbar."
        return 0
    fi
    if ! printf '%s\n' "$_releases" | grep -Fxq "$V_ZIEL"; then
        fail "Zielversion ist keine veröffentlichte Release."
        return 0
    fi
    if ! version_gt "$V_ZIEL" "$V_ALT"; then
        fail "Zielversion ist nicht neuer als die installierte Version."
        return 0
    fi

    if [ "$(env_value BK_UPDATE_REPO)" != "$REPO" ]; then
        fail "BK_UPDATE_REPO in .env weicht vom Updater ab – .env prüfen und neu starten."
        return 0
    fi
    _variante=$(env_value APP_IMAGE_VARIANT)
    case $_variante in
        "" | -noocr) ;;
        *) fail "Unbekannte Image-Variante (APP_IMAGE_VARIANT)."; return 0 ;;
    esac
    _tag_alt=$(env_value APP_IMAGE_TAG)
    [ -n "$_tag_alt" ] || _tag_alt=local
    if ! printf '%s\n' "$_tag_alt" | grep -Eq '^[A-Za-z0-9_.-]{1,64}$'; then
        fail "APP_IMAGE_TAG in .env ist ungültig."
        return 0
    fi
    _image_neu="ghcr.io/$REPO:$V_ZIEL$_variante"
    _image_alt="ghcr.io/$REPO:$_tag_alt$_variante"
    log "Update $V_ALT -> $V_ZIEL (Projekt $PROJECT, $WD, Image $_image_neu, Rückfall $_image_alt)"

    # Compose im Updater muss dieselbe App erzeugen wie bisher (Image, Datenpfad auf dem Host)
    _cfg=$(compose 60 config --format json 2>>"$LOGF" || true)
    _cfg_image=$(printf '%s' "$_cfg" | jq -r '.services.app.image // ""' 2>/dev/null || true)
    _cfg_data=$(printf '%s' "$_cfg" | jq -r '[.services.app.volumes[]? | select(.target == "/var/www/html/data") | .source][0] // ""' 2>/dev/null || true)
    if [ "$_cfg_image" != "$_image_alt" ] || [ "$_cfg_data" != "$APP_DATA" ]; then
        log "Compose-Abgleich: Image '$_cfg_image' (erwartet $_image_alt), Daten '$_cfg_data' (erwartet $APP_DATA)"
        fail "Compose-Konfiguration im Updater weicht vom laufenden App-Container ab – Update abgebrochen."
        return 0
    fi

    # Rückfall-Image muss lokal unter dem bisherigen Namen vorhanden sein (up --pull never)
    if ! docker image inspect "$_image_alt" >/dev/null 2>&1; then
        _id_alt=$(docker inspect -f '{{.Image}}' "$APP" 2>>"$LOGF" || true)
        if [ -z "$_id_alt" ] || ! run docker tag "$_id_alt" "$_image_alt"; then
            fail "Rückfall-Image nicht vorhanden – Update abgebrochen."
            return 0
        fi
    fi

    status lade "" "Image $V_ZIEL wird geladen."
    if ! run timeout "$T_PULL" docker pull "$_image_neu"; then
        fail "Image konnte nicht geladen werden (Netz, Architektur oder GHCR-Sichtbarkeit prüfen)."
        return 0
    fi

    status sichere "" "Sicherung wird erstellt."
    wartung_an
    _name="pre-update-$(printf '%s' "$V_ZIEL" | tr . -)"
    SICHERUNG="*_$_name"
    if ! run timeout "$T_BACKUP" docker exec -u www-data -w /var/www/html "$APP" \
        php bin/console backup:create --name "$_name"; then
        wartung_aus
        fail "Sicherung fehlgeschlagen – Update abgebrochen, bisherige Version läuft weiter."
        return 0
    fi

    status ersetze "" "App wird auf Version $V_ZIEL umgestellt."
    if ! set_env_tag "$V_ZIEL"; then
        wartung_aus
        fail ".env konnte nicht geschrieben werden – Update abgebrochen, bisherige Version läuft weiter."
        return 0
    fi
    if ! app_up; then
        rueckfall
        return 0
    fi

    status pruefe_gesundheit "" "Neue Version startet (Migration, Gesundheitsprüfung)."
    if ! wait_healthy "$V_ZIEL" "$T_HEALTH"; then
        rueckfall
        return 0
    fi

    wartung_aus
    if [ "$DRY_RUN" = 1 ]; then
        status fertig erfolg "Trockenlauf abgeschlossen – nichts verändert (Ziel $V_ZIEL)."
    else
        status fertig erfolg "Update auf Version $V_ZIEL abgeschlossen."
    fi
}

verarbeite() {
    REQ_ID=""
    V_ALT=""
    V_ZIEL=""
    SICHERUNG=""

    if ! mkdir "$DIR/lock" 2>/dev/null; then
        log "Sperre vorhanden – Anforderung wartet"
        return 0
    fi
    if ! echo "$$" >"$DIR/lock/pid" || ! _ein=$(mktemp -d "$DIR/work/.eingang.XXXXXX"); then
        rm -rf "$DIR/lock"
        fail "Anforderung nicht übernehmbar (Speicherplatz in data/update prüfen)."
        return 0
    fi

    # Erst in den root-eigenen Bereich verschieben (rename bewegt auch einen Symlink nur als Link),
    # dann prüfen: nur reguläre Datei, kein Symlink, Größe begrenzt.
    _inhalt=$_ein/inhalt
    _gueltig=0
    if mv "$REQ" "$_ein/request.json" 2>>"$LOGF"; then
        if [ -f "$_ein/request.json" ] && [ ! -L "$_ein/request.json" ]; then
            head -c "$((REQ_MAX_BYTES + 1))" "$_ein/request.json" >"$_inhalt"
            if [ "$(wc -c <"$_inhalt")" -le "$REQ_MAX_BYTES" ]; then
                _gueltig=1
            fi
        elif [ -d "$_ein/request.json" ] && [ ! -L "$_ein/request.json" ]; then
            # fremdes Verzeichnis vor dem Löschen der App entziehen
            chown -h 0:0 "$_ein/request.json"
            chmod 0700 "$_ein/request.json"
        fi
    else
        log "Anforderung nicht übernehmbar"
    fi

    _id=""
    _ver=""
    _req_ts=""
    if [ "$_gueltig" = 1 ]; then
        _id=$(jq -r 'if type == "object" and (.id | type) == "string" then .id else "" end' "$_inhalt" 2>/dev/null || true)
        _ver=$(jq -r 'if type == "object" and (.version | type) == "string" then .version else "" end' "$_inhalt" 2>/dev/null || true)
        # requested_at (ISO 8601 mit Offset, PHP-Format "c") als Unix-Zeit
        _req_ts=$(jq -r 'if type == "object" and (.requested_at | type) == "string"
                and (.requested_at | test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|[+-][0-9]{2}:[0-9]{2})$"))
            then .requested_at
                | ((.[0:19] + "Z" | fromdateiso8601)
                   - (if .[19:] == "Z" then 0
                      else ((.[20:22] | tonumber) * 3600 + (.[23:25] | tonumber) * 60)
                           * (if .[19:20] == "-" then -1 else 1 end) end))
                | floor | tostring
            else "" end' "$_inhalt" 2>/dev/null || true)
    fi
    if single_line "$_id" && printf '%s\n' "$_id" | grep -Eq '^[0-9a-f]{16}$'; then
        _work=$DIR/work/$_id.json
    else
        _id=""
        _work=$DIR/work/ungueltig-$(date +%s).json
    fi
    if [ -e "$_work" ] || [ -L "$_work" ]; then
        log "Anforderung $_id bereits verarbeitet – verworfen"
        rm -rf "$_ein"
        rm -rf "$DIR/lock"
        return 0
    fi
    if [ "$_gueltig" = 1 ]; then
        mv -f "$_inhalt" "$_work"
    fi
    rm -rf "$_ein"
    work_begrenzen
    REQ_ID=$_id
    log "Anforderung ${_id:-ohne gültige ID} übernommen"

    if [ -z "$_id" ]; then
        fail "Anforderung ungültig."
    elif ! valid_version "$_ver"; then
        fail "Zielversion ungültig."
    elif ! single_line "$_req_ts" || ! printf '%s\n' "$_req_ts" | grep -Eq '^[0-9]{1,12}$'; then
        V_ZIEL=$_ver
        fail "Anforderung ungültig."
    elif [ "$(($(date +%s) - _req_ts))" -gt "$REQ_MAX_ALTER" ]; then
        V_ZIEL=$_ver
        fail "Anforderung abgelaufen."
    else
        V_ZIEL=$_ver
        ablauf
    fi
    rm -rf "$DIR/lock"
}

# work/ auf die letzten WORK_MAX verarbeiteten Anforderungen begrenzen (Namen: <id>.json, ungueltig-<ts>.json)
work_begrenzen() {
    # shellcheck disable=SC2012 # Namen sind vom Updater selbst vergeben
    ls -1t "$DIR/work" 2>/dev/null | tail -n +$((WORK_MAX + 1)) | while IFS= read -r _alt; do
        case $_alt in *.json) rm -f "$DIR/work/$_alt" ;; esac
    done
}

# root_datei <pfad> – reguläre Datei, kein Symlink, Eigentümer root
root_datei() {
    [ -f "$1" ] && [ ! -L "$1" ] && [ -n "$(find "$1" -maxdepth 0 -type f -user 0 2>/dev/null)" ]
}

# Gehört /update nachweislich dem Updater? Nur ein leeres Verzeichnis wird übernommen (Marker anlegen).
# Schutz gegen ausgetauschtes data/update (Rename + Symlink durch die App, aufgelöst beim Containerstart).
austausch_eigen() {
    [ ! -L "$DIR" ] && [ -d "$DIR" ] || return 1
    if [ -e "$MARKER" ] || [ -L "$MARKER" ]; then
        root_datei "$MARKER"
        return
    fi
    [ -z "$(find "$DIR" -mindepth 1 -maxdepth 1 2>/dev/null | head -n 1)" ] || return 1
    (set -C && : >"$MARKER") 2>/dev/null || return 1
    chown -h 0:0 "$MARKER" && chmod 0644 "$MARKER" && root_datei "$MARKER"
}

# Fremdes Austauschverzeichnis: nichts schreiben, nur ins Container-Log melden (kein Heartbeat → HEALTHCHECK unhealthy)
austausch_ungueltig() {
    AUSTAUSCH_UNGUELTIG=1
    LOGF=/dev/null
    log "$MSG_AUSTAUSCH_UNGUELTIG: $DIR ist kein leeres Verzeichnis und hat keinen gültigen Marker $MARKER (root, reguläre Datei) – nichts verändert, Update deaktiviert."
    trap 'exit 0' TERM INT
    while :; do
        sleep "$HEARTBEAT" &
        wait "$!"
    done
}

# /update root-eigen machen (App liest nur); einziges von www-data beschreibbares Verzeichnis ist anforderung/.
# Nur nach austausch_eigen; Symlinks nur unter bekannten Namen entfernen, Unbekanntes nur protokollieren.
sichere_verzeichnisse() {
    chown -h 0:0 "$DIR"
    chmod 0755 "$DIR"
    for _n in anforderung lock work status.json updater.json maintenance update.log; do
        if [ -L "$DIR/$_n" ]; then
            rm -f "$DIR/$_n"
        fi
    done
    for _n in lock work; do
        if [ -e "$DIR/$_n" ] && [ ! -d "$DIR/$_n" ]; then
            rm -f "$DIR/$_n"
        fi
    done
    if [ -e "$REQ_DIR" ] && [ ! -d "$REQ_DIR" ]; then
        rm -f "$REQ_DIR"
    fi
    _fremd=$(find "$DIR" -mindepth 1 -maxdepth 1 ! -name anforderung ! -name lock ! -name work \
        ! -name status.json ! -name updater.json ! -name maintenance ! -name 'update.log*' \
        ! -name .updater-eigentum ! -name '.json.*' 2>/dev/null | head -n 5 | tr '\n' ' ')
    if [ -n "$_fremd" ]; then
        log "Unbekannte Einträge in $DIR (unverändert): $_fremd"
    fi
    mkdir -p "$REQ_DIR" "$DIR/work"
    chown -h "$WWW_UID:$WWW_UID" "$REQ_DIR"
    chmod 0770 "$REQ_DIR"
    chown -h 0:0 "$DIR/work"
    chmod 0755 "$DIR/work"
}

main() {
    if ! austausch_eigen; then
        austausch_ungueltig
    fi
    sichere_verzeichnisse

    if [ -d "$DIR/lock" ]; then
        REQ_ID=$(jq -r '.id // ""' "$DIR/status.json" 2>/dev/null || true)
        V_ALT=$(jq -r '.version_alt // ""' "$DIR/status.json" 2>/dev/null || true)
        V_ZIEL=$(jq -r '.version_ziel // ""' "$DIR/status.json" 2>/dev/null || true)
        rm -rf "$DIR/lock"
        status fehlgeschlagen fehler "Update abgebrochen (Updater neu gestartet) – Zustand der App prüfen."
        REQ_ID=""
        V_ALT=""
        V_ZIEL=""
    fi
    wartung_aus
    [ -f "$DIR/status.json" ] || status wartet "" "Bereit."
    log "Updater $UPDATER_VERSION gestartet (Zustand $(umgebung_zustand), Trockenlauf $DRY_RUN, App $APP)"

    heartbeat_loop &
    HB_PID=$!
    trap 'kill "$HB_PID" 2>/dev/null; exit 0' TERM INT

    while :; do
        if [ -e "$REQ" ] || [ -L "$REQ" ]; then
            verarbeite
        fi
        sleep "$POLL" &
        wait "$!"
    done
}

main
