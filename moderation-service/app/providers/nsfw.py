import base64
from pathlib import Path

import httpx

from app.config import Settings
from app.schemas import Finding, ProviderError


class NsfwProvider:
    def __init__(self, settings: Settings):
        self.s = settings

    async def moderate_image(self, path: Path) -> tuple[list[Finding], list[ProviderError]]:
        if not self.s.nsfw_enabled:
            return [], []
        try:
            encoded = base64.b64encode(path.read_bytes()).decode("ascii")
            async with httpx.AsyncClient(timeout=self.s.nsfw_timeout_seconds) as client:
                response = await client.post(
                    f"{self.s.nsfw_base_url.rstrip('/')}/predict/image",
                    json={"input": {"type": "base64", "data": encoded}},
                )
                response.raise_for_status()
                score = float(response.json()["result"]["nsfw_probability"])
        except Exception as exc:
            return [], [ProviderError(
                provider="opennsfw2",
                required=self.s.nsfw_required,
                message=f"provider_unavailable:{type(exc).__name__}",
            )]

        if score >= self.s.nsfw_block_threshold:
            return [Finding(
                provider="opennsfw2",
                category="explicit_nsfw",
                confidence=score,
                severity="high",
                blocking=True,
                reason_code="NSFW_HIGH_CONFIDENCE",
            )], []
        if score >= max(0.65, self.s.nsfw_block_threshold - 0.15):
            return [Finding(
                provider="opennsfw2",
                category="possible_nsfw",
                confidence=score,
                severity="medium",
                blocking=False,
                reason_code="NSFW_UNCERTAIN",
            )], []
        return [], []
