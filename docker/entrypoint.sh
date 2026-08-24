#!/bin/sh
set -eu

# Docker named volumes may be created as root after the image is built. SQLite
# needs to write its database plus WAL/journal files, so normalize ownership
# before Apache switches to www-data.
storage_path=/var/www/storage

if [ "$(id -u)" -ne 0 ]; then
    echo "[csv-viewer] The storage initializer must run as root; remove any user: override from the stack." >&2
    exit 1
fi

mkdir -p "$storage_path/database" "$storage_path/uploads" "$storage_path/backups" "$storage_path/logs"
chown -R www-data:www-data "$storage_path"

# SQLite writes the database plus -wal and -shm files in its parent directory.
# Explicit modes also fix volumes first created by Docker with restrictive modes.
find "$storage_path" -type d -exec chmod 0770 {} \;
find "$storage_path" -type f -exec chmod 0660 {} \;

if ! su -s /bin/sh www-data -c "test -w '$storage_path' && test -w '$storage_path/database'"; then
    echo "[csv-viewer] Persistent storage is not writable by www-data. Check the volume driver and its host permissions." >&2
    exit 1
fi

exec docker-php-entrypoint "$@"
