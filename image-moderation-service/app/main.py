from contextlib import asynccontextmanager
from typing import Annotated

from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.responses import JSONResponse

from app.classifier import ImageRejected, NsfwClassifier
from app.config import get_settings

settings = get_settings()
classifier = NsfwClassifier(settings)


@asynccontextmanager
async def lifespan(_: FastAPI):
    if settings.preload_model:
        classifier.load()
    yield


app = FastAPI(title=settings.app_name, lifespan=lifespan)


@app.get("/health")
async def health():
    """Ready only when the model can actually answer.

    Reporting healthy while the weights failed to load would tell Laravel
    the scanner is fine and let uploads through unchecked — the exact
    failure this whole service exists to prevent. 503 keeps content held.
    """
    if classifier.ready:
        return {
            "ok": True,
            "service": settings.app_name,
            "model": settings.model_id,
            "model_version": settings.model_revision,
        }

    return JSONResponse(
        status_code=503,
        content={
            "ok": False,
            "service": settings.app_name,
            "error": "MODEL_NOT_LOADED",
            "detail": classifier.load_error or "model has not been loaded",
        },
    )


@app.post("/v1/moderate/image")
async def moderate_image(file: Annotated[UploadFile, File()]):
    """Score one uploaded image.

    Accepts bytes only. There is deliberately no URL parameter: fetching
    a user-supplied address from inside our network is an SSRF hole, and
    an image the service downloads itself is not necessarily the image
    the user uploaded.

    Returns model signals, never a verdict — no "blocked", no "ban".
    Laravel owns policy.
    """
    raw = await file.read()

    if not raw:
        raise HTTPException(status_code=400, detail={"code": "EMPTY_FILE", "message": "No image bytes received."})

    if not classifier.ready:
        # 503, not 200-with-empty-scores: a caller must be able to tell
        # "nothing is wrong with this picture" from "nothing looked at it".
        raise HTTPException(
            status_code=503,
            detail={"code": "MODEL_NOT_LOADED", "message": classifier.load_error or "model unavailable"},
        )

    try:
        prediction = classifier.predict(raw)
    except ImageRejected as exc:
        raise HTTPException(status_code=400, detail={"code": exc.code, "message": exc.message}) from exc
    except Exception as exc:  # noqa: BLE001
        # Surfaced as a failure so the caller holds the content. Never
        # downgraded to an empty-but-successful response.
        raise HTTPException(
            status_code=500,
            detail={"code": "INFERENCE_FAILED", "message": f"{type(exc).__name__}"},
        ) from exc

    return {
        "success": True,
        "model": prediction.model,
        "model_version": prediction.model_version,
        "scores": prediction.scores,
        "latency_ms": prediction.latency_ms,
    }
