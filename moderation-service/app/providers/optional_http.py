from pathlib import Path

import httpx

from app.schemas import ProviderError


async def extract_text_from_file(
    *,
    provider: str,
    base_url: str,
    endpoint: str,
    path: Path,
    timeout: float,
    required: bool,
) -> tuple[str, list[ProviderError]]:
    try:
        async with httpx.AsyncClient(timeout=timeout) as client:
            with path.open("rb") as file_handle:
                response = await client.post(
                    f"{base_url.rstrip('/')}/{endpoint.lstrip('/')}",
                    files={"file": (path.name, file_handle, "application/octet-stream")},
                )
            response.raise_for_status()
            return str(response.json().get("text", "")).strip(), []
    except Exception as exc:
        return "", [ProviderError(
            provider=provider,
            required=required,
            message=f"provider_unavailable:{type(exc).__name__}",
        )]
