#!/usr/bin/env bash
set -e

INVOICE="${1:-INV-001}"
DUPLICATE="${2:-3}"

echo "Running naughty agent against $INVOICE with $DUPLICATE duplicate requests..."
php artisan settlements:naughty-agent "$INVOICE" --duplicate="$DUPLICATE" "${@:3}"
