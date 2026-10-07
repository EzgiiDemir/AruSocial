#!/usr/bin/env bash
#
# Build one OSRM graph, in place, inside the osrm/osrm-backend image.
#
# Run once per profile by the `osrm-foot-data` / `osrm-car-data` one-shot
# services in deploy/docker-compose.yml. Each call owns its own /data
# volume, so the foot and car graphs never share a file name or overwrite
# each other -- which matters because osrm-extract writes its output
# alongside the input .osm.pbf using the same basename.
#
# Idempotent: a graph that is already built for this profile is left
# alone, so `docker compose up` after a restart starts serving in seconds
# instead of re-running a multi-minute extract.
#
#   OSRM_PROFILE   foot | car   (selects /opt/<profile>.lua)
#   OSRM_PBF       basename of the extract in /src, e.g. cyprus-latest.osm.pbf
#   OSRM_ALGORITHM mld (default) | ch
#
set -euo pipefail

PROFILE="${OSRM_PROFILE:?OSRM_PROFILE must be foot or car}"
PBF="${OSRM_PBF:-cyprus-latest.osm.pbf}"
ALGORITHM="${OSRM_ALGORITHM:-mld}"

SRC="/src/${PBF}"
# Name the working copy after the profile: the two graphs live in separate
# volumes, but naming them apart makes a mis-mounted volume obvious rather
# than silently serving the wrong graph.
BASENAME="$(basename "${PBF}" .osm.pbf)-${PROFILE}"
WORK="/data/${BASENAME}.osm.pbf"
GRAPH="/data/${BASENAME}.osrm"
STAMP="/data/.built-${PROFILE}"

log() { printf '[osrm-%s] %s\n' "${PROFILE}" "$*"; }

if [ ! -f "${SRC}" ]; then
  log "ERROR: ${SRC} is missing. The osrm-data service downloads it; check its logs."
  exit 1
fi

# The stamp records which extract the graph was built from, so replacing
# the .osm.pbf with a newer Cyprus export rebuilds instead of serving a
# stale graph forever.
SRC_FINGERPRINT="$(md5sum "${SRC}" | cut -d' ' -f1)"

# .mldgr is osrm-customize's output, .hsgr is osrm-contract's: one of the
# two has to exist or the graph is half-built and must be redone.
if [ -f "${STAMP}" ] \
  && { [ -f "${GRAPH}.mldgr" ] || [ -f "${GRAPH}.hsgr" ]; } \
  && [ "$(cat "${STAMP}")" = "${SRC_FINGERPRINT}" ]; then
  log "graph already built from this extract - nothing to do"
  exit 0
fi

log "building ${PROFILE} graph from ${PBF} (this takes a few minutes)"
rm -f /data/"${BASENAME}".osrm* "${STAMP}"
cp "${SRC}" "${WORK}"

osrm-extract -p "/opt/${PROFILE}.lua" "${WORK}"

if [ "${ALGORITHM}" = "ch" ]; then
  osrm-contract "${GRAPH}"
else
  osrm-partition "${GRAPH}"
  osrm-customize "${GRAPH}"
fi

# The working .osm.pbf is only an input to osrm-extract; the graph files
# beside it are what osrm-routed serves. Dropping it keeps the volume to
# roughly the graph size.
rm -f "${WORK}"

printf '%s' "${SRC_FINGERPRINT}" > "${STAMP}"
log "done - ${GRAPH} ready"
