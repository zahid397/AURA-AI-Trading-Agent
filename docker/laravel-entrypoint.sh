#!/bin/sh
# Runs migrations (idempotent -- safe to re-run on every container start)
# before handing off to whatever CMD this container was given (serve, queue
# worker, or scheduler all share this entrypoint + image).
set -e
php artisan migrate --force
exec "$@"
