#!/bin/sh
# Zamiast crona: worker co minutę, tak jak na hostingu (* * * * * php cli/worker.php).
# Najpierw jedno wejście na panel: na świeżej bazie część tabel zakłada dopiero pierwsze
# żądanie WWW (migracje panelu), a worker by się na nich wywrócił.
until curl -fs -o /dev/null http://app/admin/login.php; do sleep 2; done
while true; do
    su -s /bin/sh www-data -c "php /var/www/crm/cli/worker.php" || true
    sleep 60
done
