import re

from app.config import Settings
from app.schemas import Finding, ProviderError


TCKN_RE = re.compile(r"(?<!\d)([1-9]\d{10})(?!\d)")
IBAN_RE = re.compile(r"\bTR\d{2}(?:\s?\d{4}){5}\s?\d{2}\b", re.I)
TR_PHONE_RE = re.compile(r"(?<!\d)(?:\+?90|0)?5\d{2}[\s.-]?\d{3}[\s.-]?\d{2}[\s.-]?\d{2}(?!\d)")
EMAIL_RE = re.compile(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", re.I)


def valid_tckn(value: str) -> bool:
    if len(value) != 11 or not value.isdigit() or value[0] == "0":
        return False
    digits = [int(x) for x in value]
    return (
        (sum(digits[0:9:2]) * 7 - sum(digits[1:8:2])) % 10 == digits[9]
        and sum(digits[:10]) % 10 == digits[10]
    )


def valid_iban(value: str) -> bool:
    compact = re.sub(r"\s+", "", value).upper()
    if len(compact) != 26 or not compact.startswith("TR"):
        return False
    rearranged = compact[4:] + compact[:4]
    numeric = "".join(str(ord(ch) - 55) if ch.isalpha() else ch for ch in rearranged)
    return int(numeric) % 97 == 1


class PiiProvider:
    """PII is contextual evidence unless STRICT_PII_BLOCK is explicitly enabled."""

    def __init__(self, settings: Settings):
        self.s = settings
        self._presidio = None
        if settings.pii_enabled:
            try:
                from presidio_analyzer import AnalyzerEngine
                self._presidio = AnalyzerEngine()
            except Exception:
                self._presidio = None

    def moderate(self, text: str) -> tuple[list[Finding], list[ProviderError]]:
        if not self.s.pii_enabled or not text.strip():
            return [], []

        found: list[Finding] = []
        if any(valid_tckn(value) for value in TCKN_RE.findall(text)):
            found.append(self._finding("tr_national_id", 0.99))
        if any(valid_iban(value) for value in IBAN_RE.findall(text)):
            found.append(self._finding("iban", 0.99))
        if TR_PHONE_RE.search(text):
            found.append(self._finding("phone", 0.85))
        if EMAIL_RE.search(text):
            found.append(self._finding("email", 0.85))

        if self._presidio:
            try:
                known = {item.category for item in found}
                for result in self._presidio.analyze(text=text, language="en"):
                    category = f"pii:{result.entity_type.lower()}"
                    if category not in known and result.score >= 0.8:
                        found.append(Finding(
                            provider="presidio",
                            category=category,
                            confidence=float(result.score),
                            severity="medium",
                            blocking=self.s.strict_pii_block,
                            reason_code="PII_DETECTED",
                        ))
            except Exception:
                pass
        return found, []

    def _finding(self, kind: str, confidence: float) -> Finding:
        return Finding(
            provider="pii",
            category=f"pii:{kind}",
            confidence=confidence,
            severity="high" if kind in {"tr_national_id", "iban"} else "medium",
            blocking=self.s.strict_pii_block,
            reason_code="VALIDATED_PII",
        )
