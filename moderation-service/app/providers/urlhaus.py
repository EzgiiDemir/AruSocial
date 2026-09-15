from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

from app.config import Settings
from app.schemas import Finding, ProviderError


def canonicalize(url: str) -> str:
    try:
        parsed = urlsplit(url.strip())
        host = (parsed.hostname or "").lower()
        port = f":{parsed.port}" if parsed.port else ""
        return urlunsplit((parsed.scheme.lower(), host + port, parsed.path or "/", parsed.query, ""))
    except Exception:
        return url.strip()


class UrlhausProvider:
    def __init__(self, settings: Settings):
        self.s = settings
        self.urls: set[str] = set()
        if settings.urlhaus_enabled:
            self.reload()

    def reload(self) -> None:
        dataset = Path(self.s.urlhaus_file)
        if dataset.exists():
            self.urls = {
                canonicalize(line)
                for line in dataset.read_text("utf-8", errors="ignore").splitlines()
                if line.strip() and not line.lstrip().startswith("#")
            }

    def moderate(self, urls: list[str]) -> tuple[list[Finding], list[ProviderError]]:
        if not self.s.urlhaus_enabled or not urls:
            return [], []
        if not self.urls:
            return [], [ProviderError(
                provider="urlhaus",
                required=self.s.urlhaus_required,
                message="local_dataset_missing_or_empty",
            )]

        return [
            Finding(
                provider="urlhaus",
                category="malware_url",
                confidence=1.0,
                severity="critical",
                blocking=True,
                reason_code="URLHAUS_MATCH",
            )
            for url in urls
            if canonicalize(url) in self.urls
        ], []
