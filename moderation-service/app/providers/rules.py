import re
import unicodedata
from pathlib import Path

import yaml

from app.schemas import Finding


ZERO_WIDTH = re.compile(r"[\u200B-\u200D\u2060\uFEFF]")


def normalize_text(text: str) -> str:
    text = unicodedata.normalize("NFKC", text)
    text = ZERO_WIDTH.sub("", text)
    text = text.casefold()
    return re.sub(r"\s+", " ", text).strip()


class LocalRulesProvider:
    """Exact, high-confidence phrases only; never fuzzy substring blocks."""

    def __init__(self, path: str):
        policy = Path(path)
        self.rules = yaml.safe_load(policy.read_text("utf-8")) or {} if policy.exists() else {}

    def moderate(self, text: str) -> list[Finding]:
        normalized = normalize_text(text)
        if not normalized:
            return []

        findings: list[Finding] = []
        for rule in self.rules.get("block_phrases", []):
            phrase = normalize_text(str(rule.get("phrase", "")))
            if phrase and re.search(r"(?<!\w)" + re.escape(phrase) + r"(?!\w)", normalized):
                findings.append(Finding(
                    provider="local_rules",
                    category=rule.get("category", "policy_violation"),
                    confidence=1.0,
                    severity=rule.get("severity", "high"),
                    blocking=True,
                    reason_code="EXACT_POLICY_PHRASE",
                ))
        return findings
