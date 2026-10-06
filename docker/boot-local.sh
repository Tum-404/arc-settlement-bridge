#!/bin/sh
set -eu

# The local stack is intentionally self-initialising. Production deployments
# should inject a stable APP_KEY and run migrations as a controlled release job.
if [ -z "${APP_KEY:-}" ]; then
    php artisan key:generate --force --no-interaction
fi

echo "Waiting for PostgreSQL database connection..."
MAX_TRIES=30
COUNT=0
until php -r '
    $host = getenv("DB_HOST") ?: "db";
    $port = getenv("DB_PORT") ?: "5432";
    $user = getenv("DB_USERNAME") ?: "settlement_bridge";
    $pass = getenv("DB_PASSWORD") ?: "settlement_local_password";
    $name = getenv("DB_DATABASE") ?: "settlement";
    try {
        new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        exit(0);
    } catch (Throwable $e) {
        exit(1);
    }
' > /dev/null 2>&1; do
    COUNT=$((COUNT + 1))
    if [ "$COUNT" -ge "$MAX_TRIES" ]; then
        echo "Database connection timed out."
        exit 1
    fi
    echo "Database not ready yet (attempt $COUNT/$MAX_TRIES), waiting 1s..."
    sleep 1
done

echo "Database ready. Running migrations..."
php artisan migrate --force --no-interaction

exec "$@"
