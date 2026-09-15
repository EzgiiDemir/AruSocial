"""Second visual signal: CLIP scored against prompts we write.

Why a second model at all. The NSFW classifier detects direct, well-lit
nudity and nothing else. Measured on 10 September 2026 it missed a
dark-background nude photograph (0.0090), a phone photo of a monitor
showing nudes (0.0005), and a gore image — all three scoring *below* the
safe set's own maximum of 0.0663, so no threshold could separate them. It
is a capability limit, and only a differently-trained model addresses it.

Why CLIP rather than a purpose-built classifier. Two purpose-built
candidates were benchmarked and rejected: the leading violence model
loads with randomly initialised weights and scored safe content higher
than unsafe, and the leading weapons model classifies Counter-Strike
skins. CLIP is widely used, its weights certainly load, and scoring
against prompts means the category list is *policy we can read* rather
than whatever a checkpoint was trained on.

How it decides, and the one thing that makes it usable here. CLIP scores
by competition across all prompts, so a risky category only wins if it
beats every benign description. The benign list therefore names what this
university actually photographs — and names sculpture explicitly and
specifically. With only a generic "a sculpture or a work of art" to
compete against, Rodin's Age of Bronze scored 0.9152 on nudity, which
would have blocked the collection the campus is built around. With the
three sculpture prompts below, the safe set's maximum nudity score falls
to 0.3749 while real nudity stays above 0.7383 — a clean gap where there
was none.

Thresholds are NOT here. This returns scores; Laravel decides.
"""

from __future__ import annotations

import io
import time
from dataclasses import dataclass

from PIL import Image

from app.config import Settings

# Risky categories. The key becomes `clip_<name>` in the response, so a
# category added here needs a matching threshold in Laravel's
# config/moderation.php before it can affect any decision — adding one
# alone is inert, which is the safe direction.
RISK_PROMPTS: dict[str, str] = {
    # Every prompt below says "a photograph of a real person" on purpose.
    #
    # The policy distinguishes by MEDIUM, not by subject: a charcoal life
    # study of a nude figure is coursework at an art and design
    # university; a photograph of the same subject is not. CLIP scores by
    # competition, so naming the medium on both the risky and the benign
    # side is what lets the two separate — the same mechanism that pulled
    # Rodin's Age of Bronze out of the nudity band once sculpture was
    # named opposite it.
    "nudity": "a photograph of a real naked person, or explicit sexual content",
    "kissing": "a photograph of two real people kissing each other on the mouth",
    "swimwear": "a photograph of a real person in a bikini, swimsuit or swimming trunks",
    "gore": "a photograph of blood, a wound, or a mutilated body",
    "weapon": "a photograph of a real gun, rifle, or knife held as a weapon",
    "hate_symbol": "a photograph of a nazi swastika or a white supremacist hate symbol",
    "drugs": "a photograph of illegal drugs, syringes, or drug paraphernalia",
    "self_harm": "a photograph of self-inflicted cuts or a person harming themselves",
}

# The competition. Each entry is something ordinary at ARUCAD; without
# them every image scores high on something, which is the failure mode
# that made a zero-shot text attempt unusable.
BENIGN_PROMPTS: list[str] = [
    # --- Artistic media -------------------------------------------------
    #
    # These carry the policy's central distinction. Every medium a student
    # here actually works in is named explicitly, because an unnamed
    # medium has nothing to win against: a charcoal life study with only
    # "a work of art" to compete with lands on "a photograph of a naked
    # person", and the department's own coursework gets refused.
    #
    # Figure drawing, life study and the nude are a syllabus at ARUCAD,
    # not an edge case — so this list is deliberately longer and more
    # specific than the risky side it argues with.
    "a charcoal drawing of a nude human figure",
    "a pencil sketch or life drawing of the human body",
    "an anatomical figure study drawn on paper",
    "an oil painting of a nude figure",
    "a watercolour or ink illustration of a person",
    "a digital illustration or concept art of a character",
    "an engraving, etching or print of a classical scene",
    "a bronze sculpture of a nude human figure",
    "a marble or stone statue of a person",
    "a museum or gallery photograph of a classical sculpture",
    "a sculpture of two figures embracing or kissing",
    # --- Ordinary clothed people ----------------------------------------
    #
    # Without these the swimwear prompt has nothing to lose to, and it
    # does not fail gracefully: an ordinary selfie in a sleeveless summer
    # top scored 0.6125 and would have been refused as a bikini photo.
    # Bare shoulders are not swimwear, and a campus in Kyrenia is full of
    # them for most of the academic year.
    "a selfie of a person wearing a t-shirt or a top",
    "a photograph of a person in a sleeveless top or summer clothing",
    "a portrait photograph of a clothed person indoors",
    "a photograph of students wearing everyday clothes",
    # --- Ordinary campus life -------------------------------------------
    "a photograph of a university building or campus",
    "a photograph of a classroom, studio, or workshop",
    "a sculpture or a work of art",
    "a photograph of people talking or studying",
    "a logo, poster, or graphic design",
    "a photograph of food or a cafeteria",
    "a landscape or a photograph of the sky",
    "a screenshot of a user interface",

    # --- Competition for the four inert categories ----------------------
    #
    # weapon, hate_symbol, drugs and self_harm had NOTHING on this side of
    # the argument, and CLIP scores by competition — so an image with no
    # good benign match drifts toward whichever risky prompt is least
    # wrong. These name what those four are actually competing against in
    # a design school: tools, printed graphics, cafeteria drinks, hands.
    #
    # Measured over the 54-image safe campus library, added prompts vs not:
    #
    #                        without    with
    #   worst hate_symbol     0.1676   0.0996
    #   safe max nudity       0.3204   0.2110
    #   held at 0.10         23 (43%)  8 (15%)
    #   held at 0.30          2 (4%)   0 (0%)
    #
    # Nudity recall is unchanged at 3/24 unseparable, and the gap between
    # the safe ceiling and the lowest separable nude WIDENED from 0.192 to
    # 0.291 — so this costs nothing on the one category that is calibrated.
    #
    # It lowers the safe ceiling, which is half a calibration. The other
    # half still needs lawful positive examples this repository does not
    # have, so those four categories remain inert.
    "a photograph of craft tools, scissors, or a scalpel on a workbench",
    "a photograph of a chisel, hammer, or sculpting tools",
    "a photograph of kitchen utensils or cutlery",
    "a photograph of sports equipment or a gym",
    "a photograph of a metal railing, pole, or piece of scaffolding",

    "a geometric pattern, ornament, or architectural detail",
    "a typographic composition or lettering study",
    "a university crest, emblem, or institutional logo",
    "a flag, banner, or signage photographed outdoors",
    "a religious or historical building photographed as architecture",

    "a photograph of coffee, tea, or a drink on a table",
    "a photograph of tubes of paint, brushes, and art materials",
    "a photograph of laboratory glassware or science equipment",
    "a photograph of medicine or a first aid kit in a clinic",
    "a photograph of cigarettes or an ashtray on a cafe table",

    "a close-up photograph of hands working or holding something",
    "a photograph of skin, an arm, or a person's hand",
    "a photograph of red paint, ink, or dye on a surface",
    "a photograph of a bandage or a minor everyday injury",
    "a textured surface, wall, or material close-up",
]


@dataclass
class ClipPrediction:
    scores: dict[str, float]
    model: str
    model_version: str
    latency_ms: int


class ClipRiskClassifier:
    """Adapter with the same shape as NsfwClassifier, so main.py can hold
    both without knowing how either works."""

    def __init__(self, settings: Settings):
        self.s = settings
        self._model = None
        self._processor = None
        self._text_features = None
        self._load_error: str | None = None
        self._labels = list(RISK_PROMPTS)

    def load(self) -> None:
        if self._model is not None or not self.s.clip_enabled:
            return
        try:
            import torch
            from transformers import CLIPModel, CLIPProcessor

            model = CLIPModel.from_pretrained(
                self.s.clip_model_id, revision=self.s.clip_model_revision or None
            )
            processor = CLIPProcessor.from_pretrained(
                self.s.clip_model_id, revision=self.s.clip_model_revision or None
            )
            model.eval()

            # Prompt embeddings never change, so they are computed once at
            # startup rather than per upload. It is most of the text-side
            # cost and it is identical for every image.
            prompts = [RISK_PROMPTS[k] for k in self._labels] + BENIGN_PROMPTS
            with torch.no_grad():
                inputs = processor(text=prompts, return_tensors="pt", padding=True)
                feats = model.get_text_features(**inputs)
                feats = feats / feats.norm(dim=-1, keepdim=True)

            self._model = model
            self._processor = processor
            self._text_features = feats
            self._load_error = None
        except Exception as exc:  # noqa: BLE001 - reported, never raised at import
            self._model = None
            self._load_error = f"{type(exc).__name__}: {exc}"

    @property
    def enabled(self) -> bool:
        return bool(self.s.clip_enabled)

    @property
    def ready(self) -> bool:
        return self._model is not None

    @property
    def load_error(self) -> str | None:
        return self._load_error

    def predict(self, raw: bytes) -> ClipPrediction:
        if not self.ready:
            raise RuntimeError(self._load_error or "clip model not loaded")

        import torch

        image = Image.open(io.BytesIO(raw)).convert("RGB")

        started = time.perf_counter()
        with torch.no_grad():
            inputs = self._processor(images=image, return_tensors="pt")
            feats = self._model.get_image_features(**inputs)
            feats = feats / feats.norm(dim=-1, keepdim=True)
            logits = self._model.logit_scale.exp() * feats @ self._text_features.t()
            probs = logits.softmax(dim=-1)[0].tolist()
        latency_ms = int((time.perf_counter() - started) * 1000)

        # Prefixed so a CLIP score can never be confused with the NSFW
        # model's own `nsfw` key, in the response or in a threshold.
        scores = {f"clip_{k}": float(probs[i]) for i, k in enumerate(self._labels)}

        return ClipPrediction(
            scores=scores,
            model=self.s.clip_model_id,
            model_version=self.s.clip_model_revision or "unpinned",
            latency_ms=latency_ms,
        )
