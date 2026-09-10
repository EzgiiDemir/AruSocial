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
