import json
import tempfile
from pathlib import Path
from typing import Annotated

from fastapi import FastAPI, File, Form, HTTPException, UploadFile

from app.config import get_settings
from app.engine import ModerationEngine
from app.schemas import ModerationResponse


settings = get_settings()
engine = ModerationEngine(settings)
app = FastAPI(title=settings.app_name)


@app.get("/health")
async def health():
    return {"ok": True, "service": settings.app_name}


@app.post("/v1/moderate", response_model=ModerationResponse)
async def moderate(
    surface: Annotated[str, Form()] = "post",
    text: Annotated[str, Form()] = "",
    urls_json: Annotated[str, Form()] = "[]",
    files: Annotated[list[UploadFile], File()] = [],
):
    try:
        urls = json.loads(urls_json)
        if not isinstance(urls, list) or not all(isinstance(x, str) for x in urls):
            raise ValueError()
    except Exception as exc:
        raise HTTPException(
            status_code=400,
            detail="VALIDATION: urls_json must be a JSON string array",
        ) from exc

    with tempfile.TemporaryDirectory(prefix="moderation-") as tmp:
        work = Path(tmp)
        media_paths: list[Path] = []

        for idx, upload in enumerate(files):
            suffix = Path(upload.filename or f"upload-{idx}").suffix.lower()
            target = work / f"upload-{idx}{suffix}"
            with target.open("wb") as fh:
                while chunk := await upload.read(1024 * 1024):
                    fh.write(chunk)
            media_paths.append(target)

        return await engine.moderate(
            text=text,
            urls=urls,
            media_paths=media_paths,
            work_dir=work,
        )
