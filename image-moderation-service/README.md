# Image moderation service

An internal FastAPI service that scores uploaded images with a locally
hosted classifier. It returns **signals only** — category scores, the
model, the model revision and a latency figure. It never returns a
verdict, and it never touches an account.

Laravel owns every decision. Thresholds live in
`backend/config/moderation.php`, not here, because a threshold is policy:
it has to be versioned, auditable and explainable to a student. A
threshold defined in two places is two thresholds that will disagree.

## Model

| | |
|---|---|
| Model | `Falconsai/nsfw_image_detection` |
| Revision | `96cb0d0342c7afb80cab76ecc58b265fa44da256` (pinned) |
| Licence | Apache-2.0 |
| Labels | `normal`, `nsfw` |
| Device | CPU (no GPU required at our scale) |

The revision is pinned deliberately. A model card that updates silently
is a production model that silently changes its mind, and every threshold
calibrated against the old weights becomes wrong without warning.

**This model detects adult content only.** It does not detect gore, hate
symbols, weapons or drug imagery — those need separate models and
separate benchmarks. Do not assume one vision model covers everything.

## Running it

```bash
cd image-moderation-service
python -m venv .venv
.venv/Scripts/python.exe -m pip install -r requirements.txt   # Linux: .venv/bin/python
.venv/Scripts/python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8801
```

First start downloads the weights (~350 MB) and takes a few minutes.
After that, startup is seconds and inference is roughly 100–220 ms per
image on CPU.

Then in `backend/.env`:

```
IMAGE_MODERATION_ENABLED=true
IMAGE_MODERATION_URL=http://127.0.0.1:8801
```

## Endpoints

### `GET /health`

`200` with the model id and revision once the weights are loaded.
**`503` if the model failed to load** — reporting healthy while the model
is missing would tell Laravel the scanner is fine and let uploads through
unchecked, which is the exact failure this service exists to prevent.

### `POST /v1/moderate/image`

Multipart, field name `file`. Bytes only — there is deliberately no URL
parameter. Fetching a user-supplied address from inside our network is an
SSRF hole, and an image the service downloaded itself is not provably the
image the student uploaded.

```json
{
  "success": true,
  "model": "Falconsai/nsfw_image_detection",
  "model_version": "96cb0d0342c7afb80cab76ecc58b265fa44da256",
  "scores": { "normal": 0.0009, "nsfw": 0.9991 },
  "latency_ms": 219
}
```

Failure responses are HTTP errors, never a `200` with empty scores. A
caller must always be able to tell "nothing is wrong with this picture"
from "nothing looked at this picture".

| Status | Meaning |
|---|---|
| `400` | The file is unusable — not decodable, too large, too many pixels. Validation, not policy: it is the uploader's mistake, not a violation. |
| `503` | The model is not loaded. |
| `500` | Inference failed. |

## Deployment

Bind to localhost or a private network. This service performs no
authentication and must not be reachable from the internet — Laravel is
its only authorised caller.

## Thresholds are provisional

The values shipped in `backend/config/moderation.php` (review `0.35`,
block `0.85`) were chosen before any benchmark existed. They are
deliberately cautious: reviewing a few extra safe photos is a far cheaper
mistake than publishing one unsafe one.

They must be re-derived from our own labelled campus test set before
launch. A model author's reported accuracy says nothing about our
photographs, our lighting, or our students.
