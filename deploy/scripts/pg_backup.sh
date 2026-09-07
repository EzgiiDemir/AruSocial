#!/usr/bin/env bash
set -euo pipefail
# Daily Postgres dump. Run on the host that can reach the compose network.
# Example cron: 15 3 * * * /opt/arucad/deploy/scripts/pg_backup.sh

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${BACKUP_DIR:-$ROOT/backups}"
mkdir -p "$OUT"
STAMP="$(date +%Y%m%d-%H%M%S)"
FILE="$OUT/arucad-$STAMP.sql.gz"

docker compose -f "$ROOT/docker-compose.yml" exec -T postgres \
  pg_dump -U "${POSTGRES_USER:-arucad}" "${POSTGRES_DB:-arucad}" \
  | gzip -c > "$FILE"

echo "Wrote $FILE"
# Keep 14 days.
find "$OUT" -name 'arucad-*.sql.gz' -mtime +14 -delete
