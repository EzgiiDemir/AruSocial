import io
import time
from dataclasses import dataclass

from PIL import Image

from app.config import Settings


class ImageRejected(Exception):
    """The bytes are not a usable image. This is validation, not policy."""

    def __init__(self, code: str, message: str):
        self.code = code
        self.message = message
        super().__init__(message)


@dataclass
class Prediction:
    scores: dict[str, float]
    model: str
    model_version: str
    latency_ms: int


class NsfwClassifier:
    """Wraps one image-classification model behind a stable contract.

    Deliberately an adapter: `predict` returns plain category → score, so
    swapping the model later is a change in this file only, and a
    candidate model can be benchmarked against the current one without
    touching Laravel.
    """

    def __init__(self, settings: Settings):
        self.s = settings
        self._pipe = None
        self._load_error: str | None = None

    # ---- lifecycle -------------------------------------------------

    def load(self) -> None:
        """Load the weights. Failure is recorded, never raised at import.

        A model that cannot load must make the service report unhealthy,
        so Laravel holds content. It must not crash the process into a
        restart loop, and it must not leave a half-initialised object
        that answers requests with nonsense.
        """
        if self._pipe is not None:
            return
        try:
            from transformers import pipeline

            self._pipe = pipeline(
                task="image-classification",
                model=self.s.model_id,
                revision=self.s.model_revision,
                device=-1 if self.s.device == "cpu" else 0,
            )
            self._load_error = None
        except Exception as exc:  # noqa: BLE001 - reported, not swallowed
            self._pipe = None
            self._load_error = f"{type(exc).__name__}: {exc}"

    @property
    def ready(self) -> bool:
        return self._pipe is not None

    @property
    def load_error(self) -> str | None:
        return self._load_error

    # ---- inference -------------------------------------------------

    def _decode(self, raw: bytes) -> Image.Image:
        if len(raw) > self.s.max_upload_bytes:
            raise ImageRejected("FILE_TOO_LARGE", "Image exceeds the maximum upload size.")

        try:
            image = Image.open(io.BytesIO(raw))
            # verify() checks structure without decoding pixels, so a
            # malformed file is caught before it costs memory.
            image.verify()
            image = Image.open(io.BytesIO(raw))
        except Exception as exc:  # noqa: BLE001
            raise ImageRejected("INVALID_IMAGE", "The file is not a decodable image.") from exc

        width, height = image.size
        if width * height > self.s.max_pixels:
            raise ImageRejected(
                "IMAGE_TOO_LARGE",
                f"Image is {width}x{height}, above the {self.s.max_pixels} pixel limit.",
            )

        # Drops EXIF and any alpha channel along the way, which is both
        # what the model expects and one less place for metadata to hide.
        return image.convert("RGB")

    def predict(self, raw: bytes) -> Prediction:
        if not self.ready:
            raise RuntimeError(self._load_error or "model not loaded")

        image = self._decode(raw)

        started = time.perf_counter()
        raw_scores = self._pipe(image)
        latency_ms = int((time.perf_counter() - started) * 1000)

        # The pipeline returns [{"label": ..., "score": ...}, ...]. Labels
        # are lowercased so Laravel's policy keys never depend on how a
        # particular checkpoint happened to capitalise them.
        scores = {str(row["label"]).lower(): float(row["score"]) for row in raw_scores}

        return Prediction(
            scores=scores,
            model=self.s.model_id,
            model_version=self.s.model_revision,
            latency_ms=latency_ms,
        )
