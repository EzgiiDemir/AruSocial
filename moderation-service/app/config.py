from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    app_name: str = "community-moderation-service"

    qwen_enabled: bool = True
    qwen_required: bool = True
    qwen_base_url: str = "http://qwen-guard:8000/v1"
    qwen_model: str = "Qwen/Qwen3Guard-Gen-0.6B"
    qwen_timeout_seconds: float = 8.0

    pii_enabled: bool = True
    strict_pii_block: bool = False

    nsfw_enabled: bool = True
    nsfw_required: bool = False
    nsfw_base_url: str = "http://opennsfw2:8000"
    nsfw_block_threshold: float = 0.88
    nsfw_timeout_seconds: float = 10.0

    ocr_enabled: bool = False
    ocr_required: bool = False
    ocr_base_url: str = "http://ocr:8000"
    ocr_timeout_seconds: float = 15.0

    whisper_enabled: bool = False
    whisper_required: bool = False
    whisper_base_url: str = "http://whisper:8000"
    whisper_timeout_seconds: float = 30.0

    urlhaus_enabled: bool = True
    urlhaus_required: bool = False
    urlhaus_file: str = "data/urlhaus.txt"

    image_max_bytes: int = 12 * 1024 * 1024
    video_max_bytes: int = 100 * 1024 * 1024
    image_max_pixels: int = 50_000_000
    video_max_seconds: int = 180
    video_frame_count: int = 5
    ffprobe_path: str = "ffprobe"
    ffmpeg_path: str = "ffmpeg"

    rules_file: str = "policies/rules.yml"

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")


@lru_cache
def get_settings() -> Settings:
    return Settings()
