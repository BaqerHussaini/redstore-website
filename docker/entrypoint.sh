#!/bin/sh
set -e

# Render (and some local platforms) inject a PORT variable. Apache's default
# site listens on port 80, so rewrite it before starting.
PORT="${PORT:-80}"

case "$PORT" in
  ''|*[!0-9]*)
    echo "Invalid PORT value: $PORT" >&2
    exit 1
    ;;
esac

if [ "$PORT" != "80" ]; then
  sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
  sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

exec "$@"
