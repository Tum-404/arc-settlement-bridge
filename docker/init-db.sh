#!/bin/sh
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    SELECT 'CREATE DATABASE solidinvoice'
    WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'solidinvoice')\gexec
    GRANT ALL PRIVILEGES ON DATABASE solidinvoice TO "$POSTGRES_USER";
EOSQL
