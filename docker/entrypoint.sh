#!/bin/sh
# Wspólny start kontenerów app i worker: storage/ musi być zapisywalny dla www-data
# (na Linuksie podmontowany katalog należy do użytkownika hosta).
set -e
mkdir -p /var/www/crm/storage
chown -R www-data:www-data /var/www/crm/storage
exec "$@"
