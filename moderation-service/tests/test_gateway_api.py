import subprocess
from io import BytesIO
from pathlib import Path

import pytest
from fastapi.testclient import TestClient
from PIL import Image

from app.config import Settings
from app.engine import ModerationEngine
from app.main import app


@pytest.fixture()
def client(monkeypatch, tmp_path):
    settings = Settings(
        qwen_enabled=False,
        pii_enabled=True,
        strict_pii_block=False,
        nsfw_enabled=False,
        ocr_enabled=False,
        whisper_enabled=False,
        urlhaus_enabled=True,
        urlhaus_required=False,
        urlhaus_file=str(Path(__file__).parents[1] / "data" / "urlhaus.txt"),
        rules_file=str(Path(__file__).parents[1] / "policies" / "rules.yml"),
    )
    monkeypatch.setattr("app.main.engine", ModerationEngine(settings))
    return TestClient(app)


@pytest.mark.parametrize("text", [
    "Bugün kampüste kahve içtik.",
    "Hello everyone, have a nice day!",
    "Сегодня мы закончили новый проект.",
    "Normal URL: https://example.edu/news/campus",
    "Kendi iletişim adresim student@example.edu",
])
def test_normal_text_and_urls_allow(client, text):
    response = client.post("/v1/moderate", data={
        "surface": "post",
        "text": text,
        "urls_json": '["https://example.edu/news/campus"]',
    })
    assert response.status_code == 200
    assert response.json()["decision"] == "allow"


def test_exact_clear_violation_blocks(client):
    response = client.post("/v1/moderate", data={
        "surface": "post",
        "text": "Kampüse bomba koyacağım.",
        "urls_json": "[]",
    })
    assert response.json()["decision"] == "block"
    assert response.json()["strike_recommended"] is True


def test_known_malware_url_blocks_but_unknown_domain_does_not(client):
    blocked = client.post("/v1/moderate", data={
        "surface": "post",
        "text": "",
        "urls_json": '["http://malware.test/steal"]',
    })
    assert blocked.json()["decision"] == "block"
    assert blocked.json()["categories"] == ["malware_url"]

    allowed = client.post("/v1/moderate", data={
        "surface": "post",
        "text": "",
        "urls_json": '["https://unknown-but-normal.example/path"]',
    })
    assert allowed.json()["decision"] == "allow"


def test_real_decoded_image_allows(client):
    image = BytesIO()
    Image.new("RGB", (64, 64), color=(40, 120, 180)).save(image, format="JPEG")
    response = client.post(
        "/v1/moderate",
        data={"surface": "media.upload", "text": "", "urls_json": "[]"},
        files={"files": ("campus.jpg", image.getvalue(), "image/jpeg")},
    )
    assert response.status_code == 200
    assert response.json()["decision"] == "allow"


def test_required_qwen_failure_is_error_not_block(monkeypatch):
    settings = Settings(
        qwen_enabled=True,
        qwen_required=True,
        qwen_base_url="http://127.0.0.1:1/v1",
        qwen_timeout_seconds=0.1,
        pii_enabled=False,
        nsfw_enabled=False,
        urlhaus_enabled=False,
        rules_file=str(Path(__file__).parents[1] / "policies" / "rules.yml"),
    )
    monkeypatch.setattr("app.main.engine", ModerationEngine(settings))
    response = TestClient(app).post("/v1/moderate", data={
        "surface": "post",
        "text": "Normal content",
        "urls_json": "[]",
    })
    assert response.json()["decision"] == "error"
    assert response.json()["strike_recommended"] is False


def test_optional_nsfw_failure_is_degraded_allow(monkeypatch):
    settings = Settings(
        qwen_enabled=False,
        pii_enabled=False,
        nsfw_enabled=True,
        nsfw_required=False,
        nsfw_base_url="http://127.0.0.1:1",
        nsfw_timeout_seconds=0.1,
        urlhaus_enabled=False,
        rules_file=str(Path(__file__).parents[1] / "policies" / "rules.yml"),
    )
    monkeypatch.setattr("app.main.engine", ModerationEngine(settings))
    image = BytesIO()
    Image.new("RGB", (32, 32), color="green").save(image, format="JPEG")
    response = TestClient(app).post(
        "/v1/moderate",
        data={"surface": "media.upload", "text": "", "urls_json": "[]"},
        files={"files": ("pet.jpg", image.getvalue(), "image/jpeg")},
    )
    assert response.json()["decision"] == "allow"
    assert response.json()["degraded"] is True


def test_optional_ocr_failure_is_degraded_allow(monkeypatch):
    settings = Settings(
        qwen_enabled=False,
        pii_enabled=False,
        nsfw_enabled=False,
        ocr_enabled=True,
        ocr_required=False,
        ocr_base_url="http://127.0.0.1:1",
        ocr_timeout_seconds=0.1,
        urlhaus_enabled=False,
        rules_file=str(Path(__file__).parents[1] / "policies" / "rules.yml"),
    )
    monkeypatch.setattr("app.main.engine", ModerationEngine(settings))
    image = BytesIO()
    Image.new("RGB", (32, 32), color="orange").save(image, format="JPEG")
    response = TestClient(app).post(
        "/v1/moderate",
        data={"surface": "media.upload", "text": "", "urls_json": "[]"},
        files={"files": ("food.jpg", image.getvalue(), "image/jpeg")},
    )
    assert response.json()["decision"] == "allow"
    assert response.json()["degraded"] is True


def test_optional_urlhaus_dataset_failure_is_degraded_allow(monkeypatch, tmp_path):
    settings = Settings(
        qwen_enabled=False,
        pii_enabled=False,
        nsfw_enabled=False,
        urlhaus_enabled=True,
        urlhaus_required=False,
        urlhaus_file=str(tmp_path / "missing.txt"),
        rules_file=str(Path(__file__).parents[1] / "policies" / "rules.yml"),
    )
    monkeypatch.setattr("app.main.engine", ModerationEngine(settings))
    response = TestClient(app).post("/v1/moderate", data={
        "surface": "post",
        "text": "Normal link",
        "urls_json": '["https://example.edu"]',
    })
    assert response.json()["decision"] == "allow"
    assert response.json()["degraded"] is True


def test_real_short_video_is_decoded_sampled_and_allowed(client, monkeypatch, tmp_path):
    ffmpeg = pytest.importorskip("imageio_ffmpeg").get_ffmpeg_exe()
    video = tmp_path / "campus.mp4"
    subprocess.run([
        ffmpeg,
        "-f", "lavfi",
        "-i", "color=c=blue:s=64x64:d=1",
        "-pix_fmt", "yuv420p",
        "-y", str(video),
    ], check=True, capture_output=True)

    settings = Settings(
        qwen_enabled=False,
        pii_enabled=False,
        nsfw_enabled=False,
        urlhaus_enabled=False,
        ffprobe_path="missing-ffprobe",
        ffmpeg_path=ffmpeg,
        rules_file=str(Path(__file__).parents[1] / "policies" / "rules.yml"),
    )
    monkeypatch.setattr("app.main.engine", ModerationEngine(settings))

    with video.open("rb") as video_file:
        response = TestClient(app).post(
            "/v1/moderate",
            data={"surface": "media.upload", "text": "", "urls_json": "[]"},
            files={"files": ("campus.mp4", video_file, "video/mp4")},
        )
    assert response.status_code == 200
    assert response.json()["decision"] == "allow"


def test_optional_whisper_failure_does_not_block_real_video(monkeypatch, tmp_path):
    ffmpeg = pytest.importorskip("imageio_ffmpeg").get_ffmpeg_exe()
    video = tmp_path / "campus-with-audio.mp4"
    subprocess.run([
        ffmpeg,
        "-f", "lavfi",
        "-i", "color=c=green:s=64x64:d=1",
        "-pix_fmt", "yuv420p",
        "-y", str(video),
    ], check=True, capture_output=True)
    settings = Settings(
        qwen_enabled=False,
        pii_enabled=False,
        nsfw_enabled=False,
        whisper_enabled=True,
        whisper_required=False,
        whisper_base_url="http://127.0.0.1:1",
        whisper_timeout_seconds=0.1,
        urlhaus_enabled=False,
        ffprobe_path="missing-ffprobe",
        ffmpeg_path=ffmpeg,
        rules_file=str(Path(__file__).parents[1] / "policies" / "rules.yml"),
    )
    monkeypatch.setattr("app.main.engine", ModerationEngine(settings))
    with video.open("rb") as video_file:
        response = TestClient(app).post(
            "/v1/moderate",
            data={"surface": "media.upload", "text": "", "urls_json": "[]"},
            files={"files": ("campus.mp4", video_file, "video/mp4")},
        )
    assert response.json()["decision"] == "allow"
    assert response.json()["degraded"] is True
