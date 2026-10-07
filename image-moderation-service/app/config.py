from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Service configuration.

    Note what is *not* here: thresholds. This service reports what the
    model saw and nothing else. Deciding what a score means — allow,
    review, block — is Laravel's job, because that decision is policy and
    policy has to be versioned, audited and explained to a student. A
    threshold living in two places is a threshold that will disagree with
    itself.
    """

    app_name: str = "aruverse-image-moderation"

    # Pinned model *and* revision. A model card that silently updates is a
    # production model that silently changes its mind, and every threshold
    # calibrated against the old weights becomes wrong without warning.
    # Apache-2.0. Verified against the Hub, not copied from a model card.
    # Labels: "normal", "nsfw" — the second is the key Laravel thresholds on.
    model_id: str = "Falconsai/nsfw_image_detection"
    model_revision: str = "96cb0d0342c7afb80cab76ecc58b265fa44da256"

    # Second signal: violence / graphic content. NOT SHIPPED.
    #
    # The NSFW model above detects adult content and nothing else. It does
    # not detect gore, and no threshold change makes it — a separate model
    # is the only honest way to cover that category.
    #
    # The obvious candidate was benchmarked and rejected. Recorded here so
    # nobody spends the afternoon rediscovering it:
    #
    #   jaranohaal/vit-base-violence-detection  (Apache-2.0, the most
    #   downloaded result for "violence" image-classification, with three
    #   near-identical forks) does not load. Its checkpoint is in timm
    #   `blocks.*` layout, which ViTForImageClassification cannot map, so
    #   every encoder layer and the classifier head are randomly
    #   initialised. Transformers says so plainly: "You should probably
    #   TRAIN this model on a down-stream task."
    #
    #   Its output is noise, and noise in the dangerous direction. On our
    #   labelled set it scored SAFE content *higher* on the violent label
    #   (median 0.67) than the unsafe set (0.47), with 38 of 46 safe
    #   images above 0.50. Shipping it would have blocked most of the
    #   campus photo library while catching nothing.
    #
    # Run probe_candidate.py against benchmark_manifest.json before
    # enabling any replacement. A second model can only *add* false
    # positives to a pipeline whose safe set currently passes at zero.
    violence_enabled: bool = False
    violence_model_id: str = ""
    violence_model_revision: str = ""

    # Second signal: CLIP scored against prompts (see clip_classifier.py).
    #
    # Added because the NSFW model above was measured missing three real
    # uploads — a dark-background nude (0.0090), a phone photo of a
    # monitor showing nudes (0.0005), and a gore image — all scoring below
    # the safe set's own maximum, so no threshold could separate them.
    #
    # Measured on the same labelled set, with sculpture-aware benign
    # prompts:
    #
    #   nudity   safe max 0.3749   unsafe min 0.7383   clean gap
    #   gore     safe max 0.0339   true gore 0.7683    clean gap
    #   at a 0.50 threshold, 0 of 50 safe campus photos are held
    #
    # weapon / hate_symbol / drugs / self_harm return scores too, but
    # there are no lawful positive examples for them in this repository,
    # so their recall is unmeasured. They are deliberately left without
    # thresholds in Laravel: scored, recorded, and unable to decide
    # anything until someone supplies test assets.
    # Semantic text signal (see text_classifier.py). Self-hosted, because
    # the remote moderation account has no quota and the project is
    # deliberately not dependent on a paid API.
    #
    # Measured on text_benchmark.json: harmful margins min -0.0182,
    # safe margins max -0.1295 — a +0.1113 gap — at 27 ms/text on CPU.
    text_enabled: bool = True
    text_model_id: str = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
    # Read from the resolved snapshot, not guessed. A hash invented from
    # memory here took the whole service down with "Unrecognized model" —
    # which at least proved the fail-closed path works, since /health
    # reported 503 and every upload was refused rather than scored by a
    # silently missing signal.
    text_model_revision: str = "e8f8c211226b894fcb81acc59f3b34ba3efd5f42"

    clip_enabled: bool = True
    clip_model_id: str = "openai/clip-vit-base-patch32"
    # Pinned for the same reason as the model above: a silently updated
    # checkpoint invalidates every threshold calibrated against it.
    clip_model_revision: str = "3d74acf9a28c67741b2f4f2ea7635f0aaf6f0268"

    # Loading the weights takes seconds; doing it per request would make
    # every upload pay for it. Preload at startup so /health only reports
    # ready once the model can actually answer.
    preload_model: bool = True
    device: str = "cpu"

    # Retrieval embeddings, served from the sentence model already loaded
    # above for text moderation.
    #
    # It is the same weights, the same process and the same memory: a
    # second copy of a multilingual encoder, loaded to answer "which page
    # is this question about", would cost ~470 MB to duplicate a vector
    # this service can already produce in ~30 ms. The alternative was an
    # external embedding API, which would have sent every student question
    # to a third party.
    #
    # Disabled turns the endpoint off without touching moderation; Laravel
    # then falls back to keyword retrieval, which is a quality loss and
    # never an outage.
    embed_enabled: bool = True
    embed_max_texts: int = 64
    embed_max_chars: int = 4000

    # Threads PyTorch may use per worker. Left at 0 the library grabs one
    # per core, and several uvicorn workers then fight over the same cores
    # and run slower than one would. Set this to (cores / workers).
    torch_threads: int = 0

    # Decompression-bomb and resource guards. A 50k x 50k PNG is a few
    # hundred KB on the wire and gigabytes once decoded.
    max_upload_bytes: int = 12 * 1024 * 1024
    max_pixels: int = 50_000_000

    model_config = SettingsConfigDict(
        env_file=".env", env_prefix="IMOD_", extra="ignore"
    )


@lru_cache
def get_settings() -> Settings:
    return Settings()
