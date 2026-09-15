from enum import Enum
from typing import Any

from pydantic import BaseModel, Field


class Decision(str, Enum):
    allow = "allow"
    block = "block"
    error = "error"


class Finding(BaseModel):
    provider: str
    category: str
    confidence: float = Field(default=1.0, ge=0.0, le=1.0)
    severity: str = "medium"
    blocking: bool = False
    reason_code: str | None = None
    metadata: dict[str, Any] = Field(default_factory=dict)


class ProviderError(BaseModel):
    provider: str
    required: bool
    message: str


class ModerationResponse(BaseModel):
    decision: Decision
    categories: list[str] = Field(default_factory=list)
    findings: list[Finding] = Field(default_factory=list)
    provider_errors: list[ProviderError] = Field(default_factory=list)
    degraded: bool = False
    strike_recommended: bool = False
