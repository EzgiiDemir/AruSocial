#!/usr/bin/env bash
# Build the student web app for app-aruverse.arucad.edu.tr.
#
# A shell script rather than PowerShell like build-android.ps1: the web bundle
# is built on the deploy host / CI runner (Linux), whereas the APK is built on
# a developer machine. Same contract though — every client-visible value is a
# --dart-define, and nothing secret is ever passed here. A --dart-define ends
# up readable inside the shipped bundle, so anything that must stay private
# belongs in backend/.env, not on this command line.
#
# GROQ_API_KEY in particular must NEVER be added below. GroqAiService accepts
# one as a --dart-define for local mock-mode development, and it would be
# readable by anyone in the shipped JS. USE_REST_API=true (set below) is what
# production uses: the assistant call goes through the backend's
# /api/v1/ai/query proxy, where the key stays server-side.
set -euo pipefail

ENVIRONMENT="${ENVIRONMENT:-production}"
API_BASE_URL="${API_BASE_URL:-https://api-aruverse.arucad.edu.tr/api/v1}"
REVERB_HOST="${REVERB_HOST:-api-aruverse.arucad.edu.tr}"
REVERB_PORT="${REVERB_PORT:-443}"
REVERB_SCHEME="${REVERB_SCHEME:-https}"
# Public client identifier, not a secret (the Reverb *secret* stays server-side).
REVERB_APP_KEY="${REVERB_APP_KEY:-}"
SENTRY_DSN="${SENTRY_DSN:-}"

cd "$(dirname "$0")/../../frontend"

if [[ "$API_BASE_URL" != https://* ]]; then
  # AppConfig also refuses a loopback URL in a release build, but failing here
  # gives a readable message instead of a Dart StateError mid-build.
  echo "refusing to build: API_BASE_URL must be an https:// origin (got: $API_BASE_URL)" >&2
  exit 1
fi

echo "Building ARUVERSE web"
echo "  environment : $ENVIRONMENT"
echo "  api base url: $API_BASE_URL"

flutter pub get

flutter build web \
  --release \
  --dart-define=APP_ENV="$ENVIRONMENT" \
  --dart-define=USE_REST_API=true \
  --dart-define=API_BASE_URL="$API_BASE_URL" \
  --dart-define=REVERB_HOST="$REVERB_HOST" \
  --dart-define=REVERB_PORT="$REVERB_PORT" \
  --dart-define=REVERB_SCHEME="$REVERB_SCHEME" \
  --dart-define=REVERB_APP_KEY="$REVERB_APP_KEY" \
  --dart-define=SENTRY_DSN="$SENTRY_DSN"

echo
echo "Built: frontend/build/web"
echo "Publish with:"
echo "  rsync -a --delete frontend/build/web/ /var/www/aruverse-app/"
