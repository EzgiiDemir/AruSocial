from pathlib import Path

from app.config import Settings
from app.media import (
    MediaValidationError,
    MediaTechnicalError,
    classify_and_validate,
    extract_representative_frames,
)
from app.providers.nsfw import NsfwProvider
from app.providers.optional_http import extract_text_from_file
from app.providers.pii import PiiProvider
from app.providers.qwen import QwenGuardProvider
from app.providers.rules import LocalRulesProvider
from app.providers.urlhaus import UrlhausProvider
from app.schemas import Decision, Finding, ModerationResponse, ProviderError


class ModerationEngine:
    def __init__(self, settings: Settings):
        self.s = settings
        self.qwen = QwenGuardProvider(settings)
        self.rules = LocalRulesProvider(settings.rules_file)
        self.pii = PiiProvider(settings)
        self.urlhaus = UrlhausProvider(settings)
        self.nsfw = NsfwProvider(settings)

    async def moderate(
        self,
        *,
        text: str,
        urls: list[str],
        media_paths: list[Path],
        work_dir: Path,
    ) -> ModerationResponse:
        findings: list[Finding] = []
        errors: list[ProviderError] = []

        if text.strip():
            findings.extend(self.rules.moderate(text))
            pii_findings, pii_errors = self.pii.moderate(text)
            findings.extend(pii_findings)
            errors.extend(pii_errors)
            qwen_findings, qwen_errors = await self.qwen.moderate(text)
            findings.extend(qwen_findings)
            errors.extend(qwen_errors)

        url_findings, url_errors = self.urlhaus.moderate(urls)
        findings.extend(url_findings)
        errors.extend(url_errors)

        for media_path in media_paths:
            try:
                kind = classify_and_validate(media_path, self.s)
                if kind == "image":
                    media_findings, media_errors = await self._moderate_image(media_path)
                    findings.extend(media_findings)
                    errors.extend(media_errors)
                else:
                    frames = extract_representative_frames(media_path, self.s, work_dir)
                    for frame in frames:
                        media_findings, media_errors = await self._moderate_image(frame)
                        findings.extend(media_findings)
                        errors.extend(media_errors)

                    if self.s.whisper_enabled:
                        transcript, transcript_errors = await extract_text_from_file(
                            provider="faster_whisper",
                            base_url=self.s.whisper_base_url,
                            endpoint="/transcribe",
                            path=media_path,
                            timeout=self.s.whisper_timeout_seconds,
                            required=self.s.whisper_required,
                        )
                        errors.extend(transcript_errors)
                        if transcript:
                            transcript_findings, transcript_qwen_errors = await self.qwen.moderate(transcript)
                            findings.extend(transcript_findings)
                            errors.extend(transcript_qwen_errors)
            except MediaValidationError as exc:
                return ModerationResponse(
                    decision=Decision.block,
                    categories=["invalid_media"],
                    findings=[Finding(
                        provider="media_validator",
                        category="invalid_media",
                        confidence=1.0,
                        severity="high",
                        blocking=True,
                        reason_code=str(exc),
                    )],
                    provider_errors=errors,
                    degraded=bool(errors),
                    strike_recommended=False,
                )
            except MediaTechnicalError as exc:
                return ModerationResponse(
                    decision=Decision.error,
                    provider_errors=[*errors, ProviderError(
                        provider="ffmpeg",
                        required=True,
                        message=str(exc),
                    )],
                    degraded=True,
                    strike_recommended=False,
                )

        blockers = [finding for finding in findings if finding.blocking]
        required_errors = [error for error in errors if error.required]

        if blockers:
            non_policy_reasons = {
                "INVALID_FILE_CONTENTS",
                "FILE_TOO_LARGE",
                "IMAGE_TOO_LARGE",
                "VIDEO_TOO_LONG",
                "UNSUPPORTED_FILE_TYPE",
            }
            return ModerationResponse(
                decision=Decision.block,
                categories=sorted({finding.category for finding in blockers}),
                findings=findings,
                provider_errors=errors,
                degraded=bool(errors),
                strike_recommended=any(
                    finding.reason_code not in non_policy_reasons for finding in blockers
                ),
            )

        if required_errors:
            return ModerationResponse(
                decision=Decision.error,
                provider_errors=errors,
                degraded=True,
                strike_recommended=False,
            )

        return ModerationResponse(
            decision=Decision.allow,
            findings=findings,
            provider_errors=errors,
            degraded=bool(errors),
            strike_recommended=False,
        )

    async def _moderate_image(self, path: Path) -> tuple[list[Finding], list[ProviderError]]:
        findings, errors = await self.nsfw.moderate_image(path)

        if self.s.ocr_enabled:
            ocr_text, ocr_errors = await extract_text_from_file(
                provider="paddleocr",
                base_url=self.s.ocr_base_url,
                endpoint="/ocr",
                path=path,
                timeout=self.s.ocr_timeout_seconds,
                required=self.s.ocr_required,
            )
            errors.extend(ocr_errors)
            if ocr_text:
                findings.extend(self.rules.moderate(ocr_text))
                qwen_findings, qwen_errors = await self.qwen.moderate(ocr_text)
                findings.extend(qwen_findings)
                errors.extend(qwen_errors)

        return findings, errors
