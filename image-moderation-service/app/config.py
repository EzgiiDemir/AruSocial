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

    # Loading the weights takes seconds; doing it per request would make
    # every upload pay for it. Preload at startup so /health only reports
    # ready once the model can actually answer.
    preload_model: bool = True
    device: str = "cpu"

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
