#!/bin/sh
set -eu

# Docker named volumes may be created as root after the image is built. SQLite
# needs to write its database plus WAL/journal files, so normalize ownership
# before Apache switches to www-data.
mkdir -p /var/www/storage/database /var/www/storage/uploads /var/www/storage/backups /var/www/storage/logs
chown -R www-data:www-data /var/www/storage

exec docker-php-entrypoint "$@"
