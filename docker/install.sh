#!/bin/sh
# Instalacja lokalna CRM w Dockerze (Linux / macOS). Uruchom z katalogu CRM:  ./docker/install.sh
# Tworzy .env z losowymi hasłami (tylko za pierwszym razem), buduje obraz i startuje
# bazę, panel i worker. Ponowne uruchomienie = aktualizacja/restart bez utraty danych.
set -e
cd "$(dirname "$0")/.."

if ! docker compose version >/dev/null 2>&1; then
    echo "Brak Dockera. Zainstaluj Docker Desktop: https://www.docker.com/products/docker-desktop/"
    exit 1
fi

rnd() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c "$1"; }

PORT="${CRM_PORT:-8081}"
if [ ! -f .env ]; then
    ADMIN_PASS="$(rnd 16)"
    sed -e "s|^DB_HOST=.*|DB_HOST=db|" \
        -e "s|^DB_NAME=.*|DB_NAME=crm|" \
        -e "s|^DB_USER=.*|DB_USER=crm|" \
        -e "s|^DB_PASS=.*|DB_PASS=$(rnd 32)|" \
        -e "s|^WORKER_HTTP_SECRET=.*|WORKER_HTTP_SECRET=$(rnd 48)|" \
        -e "s|^ADMIN_DEFAULT_PASSWORD=.*|ADMIN_DEFAULT_PASSWORD=${ADMIN_PASS}|" \
        -e "s|^APP_MODE=.*|APP_MODE=local|" \
        -e "s|^APP_URL=.*|APP_URL=http://localhost:${PORT}|" \
        -e "s|^ALLEGRO_REDIRECT_URI=.*|ALLEGRO_REDIRECT_URI=http://localhost:${PORT}/auth_allegro_callback.php|" \
        .env.example > .env
    printf '\nCRM_PORT=%s\n' "$PORT" >> .env
    echo "Utworzono .env (hasła wygenerowane losowo)."
else
    echo "Plik .env już istnieje - zostawiam go bez zmian."
    ADMIN_PASS="$(sed -n 's/^ADMIN_DEFAULT_PASSWORD=//p' .env)"
fi

mkdir -p dane/mysql storage
docker compose up -d --build

printf 'Czekam na panel'
i=0
until curl -fs -o /dev/null "http://localhost:${PORT}/admin/login.php"; do
    i=$((i + 1))
    if [ "$i" -gt 90 ]; then echo; echo "Panel nie wstał - sprawdź: docker compose logs"; exit 1; fi
    printf '.'; sleep 2
done
echo
echo "Gotowe. Panel: http://localhost:${PORT}/admin/"
echo "Login: admin   Hasło: ${ADMIN_PASS:-(zobacz storage/ADMIN_PASSWORD.txt)}"
echo "Zmień hasło po pierwszym logowaniu. Dane leżą w tym katalogu (dane/ i storage/) - rób kopie."
