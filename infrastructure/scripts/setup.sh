#!/usr/bin/env sh
# First-time setup of the development stack from a fresh clone.
# Usage: infrastructure/scripts/setup.sh [--no-seed]
set -eu

cd "$(dirname "$0")/../.."

[ -f .env ] || cp .env.example .env
[ -f backend/.env ] || cp backend/.env.example backend/.env

docker compose up -d --build

# Wait for php-fpm's container to accept commands (postgres/redis have healthchecks).
docker compose exec -T backend php -v >/dev/null

if ! grep -q '^APP_KEY=base64:' backend/.env; then
    docker compose exec -T backend php artisan key:generate --force
fi

docker compose exec -T backend php artisan migrate --force

if [ "${1:-}" != "--no-seed" ]; then
    # Roles & permissions, module catalog, templates and demo tenants.
    docker compose exec -T backend php artisan db:seed --force
else
    docker compose exec -T backend php artisan db:seed --class=RoleAndPermissionSeeder --force
    docker compose exec -T backend php artisan modules:sync
fi

echo
echo "Ready: http://localhost (API: http://localhost/api/v1, health: http://localhost/health)"
