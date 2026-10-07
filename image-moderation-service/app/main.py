import time
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
    # Pin the thread count BEFORE any model loads.
    #
    # Left alone, PyTorch takes one thread per core in every worker, so
    # running four uvicorn workers on four cores gives sixteen threads
    # fighting over four cores — measurably slower than a single worker.
    # Set torch_threads to (cores / workers) when running more than one.
    if settings.torch_threads > 0:
        import torch

        torch.set_num_threads(settings.torch_threads)

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
            # Retrieval embeddings ride on the text model, so they are
            # available exactly when it is. Reported separately because a
            # caller that only wants embeddings should not have to infer
            # that from a moderation signal.
            "embeddings": (
                settings.text_model_id
                if settings.embed_enabled and text_classifier.ready
                else None
            ),
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


# `def`, not `async def`, and deliberately so.
#
# PyTorch inference is blocking. In an `async def` handler it runs ON the
# event loop, so requests are served strictly one at a time and the second
# caller waits for the first to finish before its own work even starts. A
# plain `def` handler is run in FastAPI's threadpool instead, and PyTorch
# releases the GIL during inference, so concurrent uploads and messages
# actually overlap. Moderation sits on the synchronous request path of
# every post and every chat message, so this is the difference between the
# classifier being a step and being a queue.
@app.post("/v1/moderate/text")
def moderate_text(payload: dict):
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


@app.post("/v1/embed")
def embed(payload: dict):
    """Unit-length embeddings for retrieval.

    This exists so Ask ARUVERSE can rank knowledge pages against a
    question by meaning rather than by shared keywords — "kayıt nasıl
    yapılır" and "başvuru süreci" share no word and describe the same
    page. It serves them from the multilingual sentence model this service
    already keeps in memory for text moderation, so the feature costs no
    extra weights, no extra RAM and no third party.

    Vectors are already L2-normalised, so a dot product is the cosine
    similarity. The caller must not normalise again.

    Returns 503 when the model is not loaded — never an empty list, which
    a caller could not tell apart from "nothing is similar".
    """
    if not settings.embed_enabled:
        raise HTTPException(
            status_code=503,
            detail={"code": "EMBED_DISABLED", "message": "Embeddings are disabled."},
        )

    if not text_classifier.ready:
        raise HTTPException(
            status_code=503,
            detail={"code": "MODEL_NOT_LOADED",
                    "message": text_classifier.load_error or "text model unavailable"},
        )

    raw = payload.get("texts")
    if isinstance(raw, str):
        raw = [raw]
    if not isinstance(raw, list) or raw == []:
        raise HTTPException(
            status_code=400,
            detail={"code": "EMPTY_TEXTS", "message": "Send a non-empty `texts` array."},
        )

    # Bounded on purpose: one request must not be able to tie up a worker
    # for an unbounded time, and the caller batches its own backfill.
    if len(raw) > settings.embed_max_texts:
        raise HTTPException(
            status_code=413,
            detail={"code": "TOO_MANY_TEXTS",
                    "message": f"At most {settings.embed_max_texts} texts per request."},
        )

    texts = []
    for item in raw:
        text = str(item or "").strip()
        if text == "":
            raise HTTPException(
                status_code=400,
                detail={"code": "EMPTY_TEXT", "message": "A text in the batch was empty."},
            )
        texts.append(text[: settings.embed_max_chars])

    started = time.perf_counter()
    try:
        vectors = text_classifier.embed_texts(texts)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(
            status_code=500,
            detail={"code": "EMBED_FAILED", "message": f"{type(exc).__name__}"},
        ) from exc

    return {
        "success": True,
        "model": settings.text_model_id,
        "model_version": settings.text_model_revision,
        "dimensions": len(vectors[0]) if vectors else 0,
        "vectors": vectors,
        "latency_ms": int((time.perf_counter() - started) * 1000),
    }


@app.post("/v1/moderate/image")
def moderate_image(file: Annotated[UploadFile, File()]):
    """Score one uploaded image.

    Accepts bytes only. There is deliberately no URL parameter: fetching
    a user-supplied address from inside our network is an SSRF hole, and
    an image the service downloads itself is not necessarily the image
    the user uploaded.

    Returns model signals, never a verdict — no "blocked", no "ban".
    Laravel owns policy.
    """
    # `file.file.read()` rather than `await file.read()`: this handler is
    # synchronous (see the note above) and the underlying spooled file is
    # an ordinary file object.
    raw = file.file.read()

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
