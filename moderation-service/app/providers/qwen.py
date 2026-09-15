import re

import httpx

from app.config import Settings
from app.schemas import Finding, ProviderError


CATEGORY_MAP = {
    "Violent": "violence",
    "Non-violent Illegal Acts": "illegal_activity",
    "Sexual Content or Sexual Acts": "sexual_content",
    "PII": "pii_disclosure",
    "Suicide & Self-Harm": "self_harm",
    "Unethical Acts": "abuse_or_unethical",
    "Politically Sensitive Topics": "political_sensitive",
    "Copyright Violation": "copyright",
    "Jailbreak": "jailbreak",
}


class QwenGuardProvider:
    def __init__(self, settings: Settings):
        self.s = settings

    async def moderate(self, text: str) -> tuple[list[Finding], list[ProviderError]]:
        if not self.s.qwen_enabled or not text.strip():
            return [], []
        try:
            async with httpx.AsyncClient(timeout=self.s.qwen_timeout_seconds) as client:
                response = await client.post(
                    f"{self.s.qwen_base_url.rstrip('/')}/chat/completions",
                    headers={"Authorization": "Bearer EMPTY"},
                    json={
                        "model": self.s.qwen_model,
                        "messages": [{"role": "user", "content": text}],
                        "temperature": 0,
                        "max_tokens": 128,
                    },
                )
                response.raise_for_status()
                content = response.json()["choices"][0]["message"]["content"]
        except Exception as exc:
            return [], [ProviderError(
                provider="qwen3guard",
                required=self.s.qwen_required,
                message=f"provider_unavailable:{type(exc).__name__}",
            )]

        match = re.search(r"Safety:\s*(Safe|Unsafe|Controversial)", content, re.I)
        label = match.group(1).lower() if match else "unknown"
        categories = [
            internal for upstream, internal in CATEGORY_MAP.items()
            if upstream.lower() in content.lower()
        ] or ["contextual_risk"]

        if label == "safe":
            return [], []
        if label == "controversial":
            return [Finding(
                provider="qwen3guard",
                category=category,
                confidence=0.55,
                severity="medium",
                blocking=False,
                reason_code="QWEN_CONTROVERSIAL",
            ) for category in categories], []
        if label == "unsafe":
            return [Finding(
                provider="qwen3guard",
                category=category,
                confidence=0.95,
                severity="high",
                blocking=True,
                reason_code="QWEN_UNSAFE",
            ) for category in categories], []

        return [], [ProviderError(
            provider="qwen3guard",
            required=self.s.qwen_required,
            message="unparseable_provider_response",
        )]
