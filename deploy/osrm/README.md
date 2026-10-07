# OSRM routing graphs (Cyprus / TRNC)

Two OSRM instances, both built from the **Cyprus** OpenStreetMap extract:

| Service     | Profile    | Port (host) | Backend env                | Used for      |
| ----------- | ---------- | ----------- | -------------------------- | ------------- |
| `osrm-foot` | `foot.lua` | `5000`      | `ROUTING_BASE_URL`         | Walking       |
| `osrm-car`  | `car.lua`  | `5001`      | `ROUTING_DRIVING_BASE_URL` | Car and bus   |

Geofabrik's `europe/cyprus` extract is the **whole island in one file,
Northern Cyprus included** — no Turkey data is downloaded or needed. One
extract covers both the campus and anywhere off site a student routes to.

## Why two instances

An OSRM instance serves exactly **one** profile, and it answers
`/route/v1/driving/` from a pedestrian graph without complaint. So the
profile name in the URL proves nothing: the graph is chosen by **host**.

With a single foot graph, "car" and "bus" routes came back as pedestrian
geometry — down steps and through paths no vehicle can use — and nothing
on screen said so. `App\Services\RoutingService::baseFor()` now sends
walking to `ROUTING_BASE_URL` and vehicles to `ROUTING_DRIVING_BASE_URL`,
and a vehicle **never** falls back to the pedestrian graph. If no car
graph is configured, `POST /routing/directions` answers `501
ROUTING_NOT_CONFIGURED` and the app draws its honest straight-line
estimate instead.

## Starting it

```bash
cd deploy
docker compose --profile routing up -d
```

That is the whole procedure. Three one-shot services run first and then
exit:

1. **`osrm-data`** downloads `cyprus-latest.osm.pbf` into the shared
   `osrmsrc` volume and verifies it against Geofabrik's published MD5.
2. **`osrm-foot-data`** builds the `foot.lua` graph into `osrmfoot`.
3. **`osrm-car-data`** builds the `car.lua` graph into `osrmcar`.

`osrm-foot` and `osrm-car` start only once their own build has completed
successfully (`depends_on: service_completed_successfully`), so neither
ever serves a half-built graph.

The first run downloads ~30 MB and spends a few minutes on
extract/partition/customize per profile. Later runs are seconds: each
builder stamps the extract's MD5 next to the graph and exits immediately
when it already matches.

Then set both URLs in `backend/.env` (the `app` and `queue` services read
it through `env_file`):

```env
ROUTING_BASE_URL=http://osrm-foot:5000
ROUTING_DRIVING_BASE_URL=http://osrm-car:5000
```

From outside the compose network, use the published ports instead:
`http://127.0.0.1:5000` and `http://127.0.0.1:5001`.

## Separate volumes, on purpose

`osrm-extract` writes its output **next to the input file, under the same
basename**. Two profiles sharing one volume would have the second build
silently overwrite the first, leaving one graph serving both modes — the
exact failure this setup removes. So each graph gets its own volume and
its own basename:

| Volume    | Contents                       |
| --------- | ------------------------------ |
| `osrmsrc` | the downloaded `.osm.pbf` only |
| `osrmfoot`| `cyprus-latest-foot.osrm*`     |
| `osrmcar` | `cyprus-latest-car.osrm*`      |

## Updating the map data

Geofabrik refreshes the extract daily. To pick up a newer one:

```bash
cd deploy
docker compose --profile routing run --rm osrm-data
docker compose --profile routing up -d --force-recreate osrm-foot-data osrm-car-data osrm-foot osrm-car
```

`osrm-data` re-downloads when the remote MD5 no longer matches the local
file, and each builder rebuilds when the extract's checksum differs from
the one stamped beside its graph. Nothing rebuilds if nothing changed.

## Knobs

| Variable        | Default                                                      | Purpose                                   |
| --------------- | ------------------------------------------------------------ | ----------------------------------------- |
| `OSRM_PBF_URL`  | `https://download.geofabrik.de/europe/cyprus-latest.osm.pbf`   | Where the extract comes from              |
| `OSRM_PBF_BASE` | `cyprus-latest`                                                | File/graph basename; must match the URL's |
| `OSRM_IMAGE`    | `osrm/osrm-backend:v5.25.0`                                    | OSRM image for all four routing services  |

Set them in the shell or in `deploy/.env` when serving the extract from a
mirror. Changing `OSRM_PBF_BASE` renames the graph files and the
`osrm-routed` arguments together, so the two cannot drift apart.

`v5.25.0` is the newest tag OSRM actually publishes. This compose file
previously pinned `v5.27.1`, which has never existed on Docker Hub, so
`--profile routing up` failed on `manifest unknown` before anything could
route. Both scripts are invoked as `sh`/`bash <script>` rather than
relying on the executable bit, so a Windows checkout works unchanged.

## Building by hand

Only needed when Docker is not in play — the equivalent of what
`build-graph.sh` runs, per profile, with **separate working copies** so
the outputs do not collide:

```bash
wget https://download.geofabrik.de/europe/cyprus-latest.osm.pbf

cp cyprus-latest.osm.pbf cyprus-latest-foot.osm.pbf
osrm-extract -p /opt/foot.lua cyprus-latest-foot.osm.pbf
osrm-partition cyprus-latest-foot.osrm
osrm-customize cyprus-latest-foot.osrm

cp cyprus-latest.osm.pbf cyprus-latest-car.osm.pbf
osrm-extract -p /opt/car.lua cyprus-latest-car.osm.pbf
osrm-partition cyprus-latest-car.osrm
osrm-customize cyprus-latest-car.osrm

osrm-routed --algorithm mld cyprus-latest-foot.osrm   # port 5000
osrm-routed --algorithm mld cyprus-latest-car.osrm    # port 5001
```

MLD (`osrm-partition` + `osrm-customize`) is what the compose services
use; `osrm-routed --algorithm mld` must match how the graph was prepared.

## Checking it works

```bash
# Walking: ARUCAD Kyrenia campus -> Girne harbour
curl -s "http://127.0.0.1:5000/route/v1/driving/33.321358,35.337395;33.318611,35.341944?overview=false" | jq '.code, .routes[0].distance'

# Driving: same pair, expected to be noticeably longer — a car cannot
# take the direct pedestrian path.
curl -s "http://127.0.0.1:5001/route/v1/driving/33.321358,35.337395;33.318611,35.341944?overview=false" | jq '.code, .routes[0].distance'
```

For that pair the two graphs should disagree clearly. Measured against
the same two OSRM profiles:

| Profile | Distance | Route                                                              |
| ------- | -------- | ------------------------------------------------------------------ |
| foot    | ~736 m   | Şair Nedim Sk → Mustafa Çağatay Cd → **Cafer Paşha Sk**              |
| car     | ~1384 m  | Şair Nedim Sk → Karaca Sk → Ecevit Cd → Ziya Rızkı Cd → Kordonboyu Cd |

The car is ~88% longer and never touches Cafer Paşha Sokak, the
pedestrian cut-through the walker takes. **If the two distances come back
identical, both URLs are pointed at the same instance** and vehicles are
being routed over footpaths again.

There is also an end-to-end check through the backend, off by default
because it needs the network:

```bash
cd backend
ROUTING_LIVE_TEST=1 php artisan test --filter=TrncRoutingLiveTest
```

## Without a routing stack

`POST /routing/directions` answers `501 ROUTING_NOT_CONFIGURED` for any
mode whose graph is missing, and the app falls back to a dashed
straight-line estimate with a note that there are no turn-by-turn
directions. Nothing invents a road route.
