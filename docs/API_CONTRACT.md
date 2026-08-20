# ARUCAD Campus API Contract v1

This prototype uses `MockCampusRepository`. Replace that adapter when the real API is ready.

Base path:

`/api/v1/`

Core endpoints:

- `GET /places`
- `GET /places/:id`
- `GET /events`
- `GET /events/:id`
- `POST /checkins`
- `GET /me`
- `GET /me/quests`
- `POST /memories`
- `GET /map`
- `POST /routes`
- `POST /ai/query`

Standard response:

```json
{
  "data": {},
  "meta": {"request_id": "..."},
  "error": null
}
```

Suggested machine-readable errors:

- `AUTH_REQUIRED`
- `PLACE_NOT_FOUND`
- `CHECKIN_TOO_FAR`
- `EVENT_FULL`
- `RATE_LIMITED`

The response contract and endpoint baseline intentionally follow the source specification so the Flutter UI can later be switched from mock data to the real service without rewriting screens. fileciteturn2file0L9-L27 fileciteturn2file0L32-L62
