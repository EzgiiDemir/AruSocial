#!/usr/bin/env sh
#
# Download the OSM extract the routing graphs are built from, into the
# shared `osrmsrc` volume. Run once by the `osrm-data` one-shot service in
# deploy/docker-compose.yml, before either graph build.
#
# Cyprus, not Turkey: Geofabrik's `europe/cyprus` extract is the whole
# island in one file, Northern Cyprus included, so one download serves
# both the campus and anywhere a student routes to off site.
#
# Runs in curlimages/curl (busybox sh, no bash) rather than in the OSRM
# image, which ships no download tool.
#
#   OSRM_PBF_URL  source URL (Geofabrik by default)
#   OSRM_PBF      file name written into /src
#
set -eu

URL="${OSRM_PBF_URL:-https://download.geofabrik.de/europe/cyprus-latest.osm.pbf}"
PBF="${OSRM_PBF:-cyprus-latest.osm.pbf}"
DEST="/src/${PBF}"

log() { printf '[osrm-data] %s\n' "$*"; }

# Geofabrik publishes a checksum next to every extract. Using it makes
# "already downloaded" mean "downloaded and intact", so a truncated file
# from an interrupted pull is re-fetched instead of being handed to
# osrm-extract, which would fail several minutes later with a far less
# obvious error.
remote_md5() {
  curl -fsSL --max-time 60 "${URL}.md5" 2>/dev/null | cut -d' ' -f1 || true
}

WANT="$(remote_md5)"

if [ -f "${DEST}" ]; then
  if [ -z "${WANT}" ]; then
    log "checksum unavailable; keeping the extract already in the volume"
    exit 0
  fi
  HAVE="$(md5sum "${DEST}" | cut -d' ' -f1)"
  if [ "${HAVE}" = "${WANT}" ]; then
    log "${PBF} is present and current - skipping download"
    exit 0
  fi
  log "${PBF} is stale or truncated - re-downloading"
fi

log "downloading ${URL}"
# -L: Geofabrik redirects `-latest` to the dated file.
curl -fSL --retry 3 --retry-delay 5 --max-time 1800 -o "${DEST}.part" "${URL}"

if [ -n "${WANT}" ]; then
  GOT="$(md5sum "${DEST}.part" | cut -d' ' -f1)"
  if [ "${GOT}" != "${WANT}" ]; then
    log "ERROR: checksum mismatch (want ${WANT}, got ${GOT})"
    rm -f "${DEST}.part"
    exit 1
  fi
fi

mv "${DEST}.part" "${DEST}"
log "ready: ${DEST}"
