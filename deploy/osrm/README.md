# OSRM walking graph

`docker compose --profile routing up osrm` only works after an OSM PBF is
imported into the `osrmdata` volume. This repo does not ship map data.

Example (Linux host with ~4GB RAM):

```bash
wget https://download.geofabrik.de/europe/cyprus-latest.osm.pbf
docker run --rm -v "$PWD:/data" osrm/osrm-backend:v5.27.1 osrm-extract -p /opt/foot.lua /data/cyprus-latest.osm.pbf
docker run --rm -v "$PWD:/data" osrm/osrm-backend:v5.27.1 osrm-partition /data/cyprus-latest.osrm
docker run --rm -v "$PWD:/data" osrm/osrm-backend:v5.27.1 osrm-customize /data/cyprus-latest.osrm
```

Then set `ROUTING_BASE_URL=http://osrm:5000` on the Laravel service.
Until then the API returns `501 ROUTING_NOT_CONFIGURED`.
