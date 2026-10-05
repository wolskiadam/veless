#!/bin/sh
# Sandbox wtyczek CRM: sprawdza wtyczkę (katalog albo ZIP) w odizolowanym Dockerze i pisze raport.
#
#   sh docker/sandbox/run.sh <katalog-wtyczki | wtyczka.zip> [katalog-raportu]
#   sh docker/sandbox/run.sh --oczekuj-odrzucenia tests/fixtures/sandbox/zlodziej   # test samego sandboxu
#
# Co robi:
#   1. skan kodu (scan.php) - bez uruchamiania,
#   2. CRM w Dockerze na fikcyjnych danych, z „kanarkami” zamiast tokenów, bez internetu
#      (każde połączenie łapie sink.php), z dziennikiem zapytań bazy,
#   3. przebieg bez wtyczki i z wtyczką: te same strony panelu, zamówienie, automatyzacje, worker,
#   4. raport (report.php): co wtyczka zmieniła w plikach i bazie, dokąd się łączyła, czy wyniosła kanarki.
# Wynik: <katalog-raportu>/raport.md i raport.json. Kod wyjścia: 0 - przeszła (OK albo do przeglądu),
# 1 - odrzucona, 2 - błąd sandboxu. Wymaga Dockera z compose.
set -eu

EXPECT_REJECT=0
if [ "${1:-}" = "--oczekuj-odrzucenia" ]; then EXPECT_REJECT=1; shift; fi
SRC="${1:-}"
[ -n "$SRC" ] || { echo "Użycie: sh docker/sandbox/run.sh <katalog-wtyczki | wtyczka.zip> [katalog-raportu]"; exit 2; }
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
OUT="${2:-$PWD/sandbox-raport}"
IMAGE="${SBX_IMAGE:-veless:local}"
PROJECT="crm-sbx-$$"

docker compose version >/dev/null 2>&1 || { echo "Brak Dockera z compose."; exit 2; }
docker image inspect "$IMAGE" >/dev/null 2>&1 || { echo "Buduję obraz $IMAGE..."; docker build -q -t "$IMAGE" "$ROOT" >/dev/null; }

rm -rf "$OUT"; mkdir -p "$OUT/net"
OUT="$(cd "$OUT" && pwd)"
TMP="$(mktemp -d)"
WORK="$TMP/crm"
mkdir -p "$WORK" "$TMP/plugin"

log() { printf '\n== %s\n' "$*"; }
dc() { SBX_IMAGE="$IMAGE" SBX_WORK="$WORK" SBX_NET_DIR="$OUT/net" SBX_DB_PASS="$DB_PASS" SBX_DB_ROOT_PASS="$DB_ROOT_PASS" \
       docker compose -p "$PROJECT" -f "$HERE/compose.yml" "$@"; }
cleanup() {
    dc down -v --remove-orphans >/dev/null 2>&1 || true
    # Pliki w kopii roboczej należą do www-data z kontenera - sprzątamy je kontenerem.
    docker run --rm --network none -v "$TMP":/w "$IMAGE" sh -c 'rm -rf /w/* /w/.[!.]* 2>/dev/null' >/dev/null 2>&1 || true
    rm -rf "$TMP" 2>/dev/null || true
}
trap cleanup EXIT INT TERM
rnd() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c "$1"; }
DB_PASS="SBXCANARY-dbpass-$(rnd 12)"
DB_ROOT_PASS="$(rnd 24)"

# ---------------------------------------------------------------- wtyczka
log "Wtyczka: $SRC"
if [ -f "$SRC" ]; then
    case "$SRC" in
        *.zip) if command -v unzip >/dev/null 2>&1; then unzip -q "$SRC" -d "$TMP/plugin"; else python3 -m zipfile -e "$SRC" "$TMP/plugin"; fi ;;
        *) echo "Oczekiwano katalogu albo pliku .zip"; exit 2 ;;
    esac
elif [ -d "$SRC" ]; then
    cp -R "$SRC" "$TMP/plugin/"
else
    echo "Nie ma takiej wtyczki: $SRC"; exit 2
fi
REG="$(find "$TMP/plugin" -maxdepth 3 -name register.php | head -n 1)"
[ -n "$REG" ] || { echo "W paczce nie ma register.php - to nie jest wtyczka CRM."; exit 2; }
PDIR="$(dirname "$REG")"
SLUG="$(basename "$PDIR" | tr 'A-Z' 'a-z')"
echo "$SLUG" | grep -Eq '^[a-z0-9_]+$' || { echo "Nieprawidłowa nazwa katalogu wtyczki: $SLUG"; exit 2; }
if grep -q 'PaseExt\\' "$REG"; then KIND=extension; DEST=extensions; else KIND=integration; DEST=integrations; fi
echo "Nazwa: $SLUG, rodzaj: $KIND"

# ---------------------------------------------------------------- 1. skan kodu
log "Skan kodu"
docker run --rm --network none -v "$PDIR":/plugin:ro -v "$HERE":/sandbox:ro "$IMAGE" \
    php /sandbox/scan.php /plugin > "$OUT/scan.json"
php_count() { docker run --rm --network none -v "$OUT":/o:ro "$IMAGE" php -r '$r=json_decode(file_get_contents("/o/scan.json"),true); echo json_encode($r["counts"]);'; }
php_count; echo

# ---------------------------------------------------------------- 2. kopia CRM z wtyczką
log "Kopia CRM i fikcyjna instalacja"
tar -C "$ROOT" --exclude=./.git --exclude=./dane --exclude=./storage --exclude=./.env --exclude=./sandbox-raport \
    --exclude=./node_modules --exclude=./agent-app -cf - . | tar -C "$WORK" -xf -
mkdir -p "$WORK/storage"
rm -rf "${WORK:?}/$DEST/$SLUG"
mkdir -p "$WORK/$DEST"
cp -R "$PDIR" "$WORK/$DEST/$SLUG"
rm -f "$WORK/$DEST/$SLUG/.disabled"
sed -e "s|^DB_HOST=.*|DB_HOST=db|" -e "s|^DB_NAME=.*|DB_NAME=crm|" -e "s|^DB_USER=.*|DB_USER=crm|" \
    -e "s|^DB_PASS=.*|DB_PASS=$DB_PASS|" -e "s|^WORKER_HTTP_SECRET=.*|WORKER_HTTP_SECRET=SBXCANARY-worker-$(rnd 12)|" \
    -e "s|^ADMIN_DEFAULT_PASSWORD=.*|ADMIN_DEFAULT_PASSWORD=SBXCANARY-admin-$(rnd 12)|" \
    -e "s|^APP_URL=.*|APP_URL=http://localhost|" -e "s|^APP_DEBUG=.*|APP_DEBUG=true|" -e "s|^TOTP_REQUIRE_ADMIN=.*|TOTP_REQUIRE_ADMIN=0|" \
    "$ROOT/.env.example" > "$WORK/.env"
touch "$WORK/$DEST/$SLUG/.disabled"      # przebieg bazowy: wtyczka leży na dysku, ale jest wyłączona

dc up -d >/dev/null 2>&1 || { dc logs --no-color | tail -40; echo "Sandbox nie wstał."; exit 2; }
i=0
until [ -f "$OUT/net/sink.ready" ] && dc exec -T app curl -fs -o /dev/null http://localhost/admin/login.php 2>/dev/null; do
    i=$((i + 1)); [ "$i" -lt 90 ] || { dc logs --no-color | tail -60; echo "Panel w sandboxie nie wstał."; exit 2; }
    sleep 2
done
# CA sinka w zaufanych (HTTPS wtyczki da się odczytać), kopia robocza w posiadaniu www-data jak na hostingu.
dc exec -T -u root app sh -c 'cp /dev/stdin /usr/local/share/ca-certificates/crm-sandbox.crt && update-ca-certificates >/dev/null 2>&1 && chown -R www-data:www-data /var/www/crm' < "$OUT/net/ca/ca.crt"

SNAP='find /var/www /tmp /var/tmp /dev/shm -type f -print0 2>/dev/null | xargs -0 -r sha256sum | sort -k2'
PROCS='for p in /proc/[0-9]*; do u=$(awk "/^Uid:/{print \$2}" $p/status 2>/dev/null); [ "$u" = 33 ] && tr "\0" " " < $p/cmdline 2>/dev/null && echo; done | grep -v "^apache2\|^$" || true'
sql() { dc exec -T db mariadb -uroot -p"$DB_ROOT_PASS" -N -B -e "$1" 2>/dev/null; }
netmark() { wc -l < "$OUT/net/network.jsonl" 2>/dev/null | tr -d ' ' || echo 0; }

log "Dane testowe i kanarki"
dc exec -T -u www-data app php /sandbox/probe.php seed "$SLUG" "$KIND" > "$OUT/canaries.json"
ACC="$(docker run --rm --network none -v "$OUT":/o:ro "$IMAGE" php -r 'echo json_decode(file_get_contents("/o/canaries.json"),true)["account_id"] ?? "";')"

phase() {   # $1 = base | plugin
    dc exec -T -u root app sh -c "$SNAP" > "$OUT/files-$1-before.txt"
    sql "SET GLOBAL general_log = 0; TRUNCATE mysql.general_log; SET GLOBAL general_log = 1;"
    netmark > "$OUT/net-$1-start.txt"
    dc exec -T -u www-data app php /sandbox/probe.php exercise "$SLUG" "$KIND" $ACC > "$OUT/pages-$1.json" || true
    sleep 1
    netmark > "$OUT/net-$1-end.txt"
    sql "SET GLOBAL general_log = 0;"
    sql "SELECT argument FROM mysql.general_log WHERE command_type IN ('Query','Execute') AND user_host LIKE 'crm[crm]%'" > "$OUT/queries-$1.txt" || true
    dc exec -T -u root app sh -c "$SNAP" > "$OUT/files-$1-after.txt"
    dc exec -T -u root app sh -c "$PROCS" > "$OUT/procs-$1.txt" || true
    dc exec -T -u root app sh -c 'grep -rlI SBXCANARY /var/www /tmp /var/tmp /dev/shm 2>/dev/null | sort' > "$OUT/canary-files-$1.txt" || true
}

log "Przebieg bez wtyczki"
phase base
log "Przebieg z wtyczką"
dc exec -T -u root app rm -f "/var/www/crm/$DEST/$SLUG/.disabled"
phase plugin
dc exec -T -u root app sh -c 'cat /var/www/crm/storage/app.log 2>/dev/null; cat /var/log/apache2/error.log 2>/dev/null' > "$OUT/app.log" || true
dc logs --no-color app > "$OUT/app-container.log" 2>&1 || true

# ---------------------------------------------------------------- 3. raport
log "Raport"
set +e
docker run --rm --network none -v "$OUT":/o -v "$HERE":/sandbox:ro "$IMAGE" \
    php /sandbox/report.php /o "$SLUG" "$KIND" "$DEST"
CODE=$?
set -e
echo "Raport: $OUT/raport.md"
if [ "$EXPECT_REJECT" = 1 ]; then
    [ "$CODE" = 1 ] && { echo "Zgodnie z oczekiwaniem: wtyczka odrzucona."; exit 0; }
    echo "BŁĄD: sandbox nie odrzucił wtyczki, która powinna być odrzucona."; exit 1
fi
exit "$CODE"
