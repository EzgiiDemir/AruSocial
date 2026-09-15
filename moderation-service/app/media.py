import json
import re
import subprocess
from pathlib import Path

from PIL import Image

from app.config import Settings


IMAGE_SUFFIXES = {".jpg", ".jpeg", ".png", ".webp", ".heic", ".heif"}
VIDEO_SUFFIXES = {".mp4", ".mov", ".webm"}


class MediaValidationError(Exception):
    pass


class MediaTechnicalError(Exception):
    pass


def classify_and_validate(path: Path, settings: Settings) -> str:
    size = path.stat().st_size
    suffix = path.suffix.lower()
    if suffix in IMAGE_SUFFIXES:
        if size > settings.image_max_bytes:
            raise MediaValidationError("FILE_TOO_LARGE")
        try:
            with Image.open(path) as image:
                image.verify()
            with Image.open(path) as image:
                if image.width * image.height > settings.image_max_pixels:
                    raise MediaValidationError("IMAGE_TOO_LARGE")
        except MediaValidationError:
            raise
        except Exception as exc:
            raise MediaValidationError("INVALID_FILE_CONTENTS") from exc
        return "image"

    if suffix in VIDEO_SUFFIXES:
        if size > settings.video_max_bytes:
            raise MediaValidationError("FILE_TOO_LARGE")
        if probe_video_duration(path, settings.ffprobe_path, settings.ffmpeg_path) > settings.video_max_seconds:
            raise MediaValidationError("VIDEO_TOO_LONG")
        return "video"

    raise MediaValidationError("UNSUPPORTED_FILE_TYPE")


def probe_video_duration(path: Path, ffprobe_path: str, ffmpeg_fallback_path: str | None = None) -> float:
    try:
        process = subprocess.run(
            [
                ffprobe_path,
                "-v", "error",
                "-show_entries", "format=duration",
                "-of", "json",
                str(path),
            ],
            capture_output=True,
            text=True,
            timeout=15,
            check=True,
        )
        return _positive_duration(float(json.loads(process.stdout)["format"]["duration"]))
    except FileNotFoundError as exc:
        if not ffmpeg_fallback_path:
            raise MediaTechnicalError("MEDIA_TOOL_UNAVAILABLE") from exc
    except (subprocess.CalledProcessError, KeyError, ValueError, json.JSONDecodeError):
        if not ffmpeg_fallback_path:
            raise MediaValidationError("INVALID_FILE_CONTENTS")

    # A plain ffmpeg binary can probe duration too. This fallback keeps local
    # development/test environments usable when a distribution ships ffmpeg
    # without the separate ffprobe executable.
    try:
        process = subprocess.run(
            [ffmpeg_fallback_path, "-hide_banner", "-i", str(path), "-f", "null", "-"],
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )
    except FileNotFoundError as exc:
        raise MediaTechnicalError("MEDIA_TOOL_UNAVAILABLE") from exc
    except subprocess.TimeoutExpired as exc:
        raise MediaTechnicalError("MEDIA_TOOL_TIMEOUT") from exc

    match = re.search(r"Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)", process.stderr)
    if not match:
        raise MediaValidationError("INVALID_FILE_CONTENTS")
    return _positive_duration(
        int(match.group(1)) * 3600 + int(match.group(2)) * 60 + float(match.group(3))
    )


def _positive_duration(duration: float) -> float:
    if duration < 0:
        raise MediaValidationError("INVALID_FILE_CONTENTS")
    return duration


def extract_representative_frames(path: Path, settings: Settings, output_dir: Path) -> list[Path]:
    duration = probe_video_duration(path, settings.ffprobe_path, settings.ffmpeg_path)
    count = max(1, settings.video_frame_count)
    frames: list[Path] = []

    for index, second in enumerate(duration * (i + 1) / (count + 1) for i in range(count)):
        output = output_dir / f"frame-{index:02d}.jpg"
        try:
            subprocess.run(
                [
                    settings.ffmpeg_path,
                    "-hide_banner", "-loglevel", "error",
                    "-ss", f"{second:.3f}",
                    "-i", str(path),
                    "-frames:v", "1",
                    "-q:v", "3",
                    "-y", str(output),
                ],
                capture_output=True,
                text=True,
                timeout=20,
                check=True,
            )
        except FileNotFoundError as exc:
            raise MediaTechnicalError("MEDIA_TOOL_UNAVAILABLE") from exc
        except subprocess.TimeoutExpired as exc:
            raise MediaTechnicalError("MEDIA_TOOL_TIMEOUT") from exc
        except subprocess.CalledProcessError as exc:
            raise MediaValidationError("INVALID_FILE_CONTENTS") from exc
        if output.exists() and output.stat().st_size:
            frames.append(output)

    if not frames:
        raise MediaValidationError("INVALID_FILE_CONTENTS")
    return frames
