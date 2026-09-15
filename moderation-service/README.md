# Self-hosted moderation gateway

Central `ALLOW | BLOCK | ERROR` service used by the Laravel backend. It
combines exact local rules, Qwen3Guard, validated PII signals, a local URLhaus
dataset, OpenNSFW2 and five-frame video sampling. OCR and Whisper are optional.

```bash
cp .env.example .env
docker compose -f docker-compose.example.yml up -d --build
curl http://127.0.0.1:8080/health
```

Then configure Laravel:

```dotenv
MODERATION_SERVICE_ENABLED=true
MODERATION_SERVICE_URL=http://moderation:8080
MODERATION_SERVICE_TIMEOUT=15
```

Required provider failures return `decision=error`; optional failures only set
`degraded=true`. Invalid media is blocked without recommending a strike.
