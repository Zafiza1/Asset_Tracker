#!/usr/bin/env sh
# Run the backend and frontend checks inside the development stack.
# The backend suite uses its own database (asset_tracker_test, see phpunit.xml).
set -eu

cd "$(dirname "$0")/../.."

docker compose exec -T postgres sh -c \
    'psql -U "$POSTGRES_USER" -d postgres -lqt | cut -d "|" -f1 | grep -qw asset_tracker_test || createdb -U "$POSTGRES_USER" asset_tracker_test'

docker compose exec -T -e DB_DATABASE=asset_tracker_test backend vendor/bin/phpunit "$@"
docker compose exec -T frontend sh -c 'npm run lint && npx tsc --noEmit'
