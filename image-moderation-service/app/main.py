from contextlib import asynccontextmanager
from typing import Annotated

from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.responses import JSONResponse

from app.classifier import ImageRejected, NsfwClassifier
from app.clip_classifier import ClipRiskClassifier
from app.config import get_settings
from app.text_classifier import SemanticTextClassifier

settings = get_settings()
classifier = NsfwClassifier(settings)
clip = ClipRiskClassifier(settings)
text_classifier = SemanticTextClassifier(settings)


@asynccontextmanager
async def lifespan(_: FastAPI):
    if settings.preload_model:
        classifier.load()
        clip.load()
        text_classifier.load()
    yield


app = FastAPI(title=settings.app_name, lifespan=lifespan)


@app.get("/health")
async def health():
    """Ready only when the model can actually answer.

    Reporting healthy while the weights failed to load would tell Laravel
    the scanner is fine and let uploads through unchecked — the exact
    failure this whole service exists to prevent. 503 keeps content held.
    """
    # Both signals must answer, or neither is trusted.
    #
    # Reporting healthy with CLIP silently absent would be the same class
    # of mistake as reporting healthy with no model at all: uploads would
    # be judged on the NSFW score alone, which is exactly the weaker
    # pipeline that published a nude at 0.0090. A degraded scanner that
    # does not say so is worse than one that is plainly down.
    clip_missing = clip.enabled and not clip.ready
    text_missing = text_classifier.enabled and not text_classifier.ready

    if classifier.ready and not clip_missing and not text_missing:
        return {
            "ok": True,
            "service": settings.app_name,
            "model": settings.model_id,
            "model_version": settings.model_revision,
            "signals": {
                "nsfw": settings.model_id,
                "clip": settings.clip_model_id if clip.enabled else None,
                "text": settings.text_model_id if text_classifier.enabled else None,
            },
        }

    which, error = "nsfw", classifier.load_error
    if clip_missing:
        which, error = "clip", clip.load_error
    elif text_missing:
        which, error = "text", text_classifier.load_error

    return JSONResponse(
        status_code=503,
        content={
            "ok": False,
            "service": settings.app_name,
            "error": "MODEL_NOT_LOADED",
            "detail": error or "model has not been loaded",
            "which": which,
        },
    )


@app.post("/v1/moderate/text")
async def moderate_text(payload: dict):
    """Score one piece of text against every policy category.

    Returns margins, never a verdict: "how much more does this resemble
    a threat than ordinary campus writing". Laravel owns the thresholds,
    for the same reason it owns the image ones — a threshold is policy,
    and policy has to be auditable and explainable to a student.
    """
    text = str(payload.get("text") or "")
    if text.strip() == "":
        raise HTTPException(
            status_code=400,
            detail={"code": "EMPTY_TEXT", "message": "No text received."},
        )

    if not text_classifier.enabled:
        raise HTTPException(
            status_code=503,
            detail={"code": "TEXT_DISABLED", "message": "Semantic text signal is disabled."},
        )

    if not text_classifier.ready:
        # 503 rather than empty margins, so a caller can tell "this looks
        # fine" from "nothing read it".
        raise HTTPException(
            status_code=503,
            detail={"code": "MODEL_NOT_LOADED",
                    "message": text_classifier.load_error or "text model unavailable"},
        )

    try:
        prediction = text_classifier.predict(text)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(
            status_code=500,
            detail={"code": "INFERENCE_FAILED", "message": f"{type(exc).__name__}"},
        ) from exc

    return {
        "success": True,
        "model": prediction.model,
        "model_version": prediction.model_version,
        "margins": prediction.margins,
        "latency_ms": prediction.latency_ms,
        # How many readings produced these margins (the whole post plus its
        # clauses), and the clause that drove the highest one — so a
        # moderator opening a long post is not left guessing which sentence
        # the score came from.
        "segments_scored": prediction.segments_scored,
        "evidence": prediction.evidence,
    }


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

    if clip.enabled and not clip.ready:
        # Same rule for the second signal. Answering with only the NSFW
        # score would quietly return the pipeline to the state that
        # published a nude photograph at 0.0090, and the caller would have
        # no way to know a signal was missing.
        raise HTTPException(
            status_code=503,
            detail={"code": "CLIP_NOT_LOADED", "message": clip.load_error or "clip unavailable"},
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

    scores = dict(prediction.scores)
    latency = prediction.latency_ms
    models = {"nsfw": prediction.model}

    if clip.enabled:
        try:
            clip_prediction = clip.predict(raw)
        except Exception as exc:  # noqa: BLE001
            # Held, not degraded. Falling back to the NSFW score alone
            # here would silently reinstate the weaker pipeline for
            # exactly the images most likely to break inference.
            raise HTTPException(
                status_code=500,
                detail={"code": "CLIP_INFERENCE_FAILED", "message": f"{type(exc).__name__}"},
            ) from exc
        scores.update(clip_prediction.scores)
        latency += clip_prediction.latency_ms
        models["clip"] = clip_prediction.model

    return {
        "success": True,
        "model": prediction.model,
        "model_version": prediction.model_version,
        "models": models,
        "scores": scores,
        "latency_ms": latency,
    }
