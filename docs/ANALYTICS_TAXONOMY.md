# Event taxonomy (product analytics)

Kod `AnalyticsEvents` sabitleri + `AnalyticsTracker.track`.
Varsayılan tracker `MockAnalyticsTracker` (yalnızca log). Firebase Analytics
veya Mixpanel hesabı bağlanınca aynı isimler gönderilir — sahte funnel
üretilmez.

## Auth

| Event | Properties |
|---|---|
| `auth_success` | `method`: password \| microsoft \| biometric \| session_restore |
| `auth_failure` | `method`, `error` |
| `auth_logout` | |

## Location / map

| Event | Properties |
|---|---|
| `location_permission` | `result` |
| `place_check_in` | `placeId`, `ok` |
| `route_started` | `place` |

## Social / content

| Event | Properties |
|---|---|
| `feed_post_created` | `pending` |
| `photo_added` | `placeId` |
| `service_contact` | `service` \| `sport` |
| `ask_query` | `conversationId` |

## Funnel (ölçüm hesabı gelince)

1. `auth_success`
2. `location_permission`
3. `place_check_in` veya `feed_post_created`
4. `route_started`

Retention: haftalık `auth_success` unique user. Bu repo sayacı tutmaz.
