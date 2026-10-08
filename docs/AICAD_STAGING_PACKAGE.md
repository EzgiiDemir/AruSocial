# AICAD staging package (Phase 4C)

The exact steps to bring AICAD up on a staging server and run the soak. **Nothing here runs against production**, and no production setting is changed. It supersedes the deployment list in `AICAD_ROLLOUT.md` §2–3, which predates the Phase 4A–4C migrations. The gates, rollback and UNCERTAIN review in `AICAD_ROLLOUT.md` still apply.

Commands run in `backend/` on the staging host. The server commands (build, cache, queue) are those in `DEPLOYMENT.md` §3–4.

## 0. Before you start
- **The staging host itself:** see [AICAD_STAGING_INFRASTRUCTURE.md](AICAD_STAGING_INFRASTRUCTURE.md). It covers the infrastructure request (dedicated host, DNS record, TLS, database, isolation), the provisioning commands and the exact staging `.env`.
- The staging database is a staging copy or a fresh seed. It is **not** production.
- You have a staff account with an admin-panel role grant. It is used for the full-answer evaluations and the fallback probes.
- The model host and the embedder are reachable from the staging app server.

## 1. Code and schema
1. Deploy the branch. Run `composer install --no-dev --optimize-autoloader`.
2. Run `php artisan migrate --force`. Expected, all additive and nullable, with nothing back-filled:

| Migration | Adds |
|---|---|
| `2026_10_05_100000_extend_language_of_instruction_phrases` | data only (planner hotfix) |
| `2026_10_06_100000_add_canonical_campus_fields` | `food_venues.place_id`; `clubs.email/website/instagram_url/place_id`; `opening_hours` table |
| `2026_10_07_100000_add_service_phone_and_sport_place` | `services.phone`; `sports.place_id` |

3. Check `php artisan migrate:status`: none pending.

## 2. Seeders (idempotent, additive; an operator's aliases are never overwritten)
```
php artisan db:seed --class=AiProgrammeAliasSeeder    # official en/ru programme names (still required)
php artisan db:seed --class=AiCampusAliasSeeder       # office and club names: official, product wording, Russian case forms
php artisan db:seed --class=AiEvaluationCaseSeeder    # evaluation cases; existing cases are never overwritten
```
`AiCampusAliasSeeder` replaces the Phase 4B `AiServiceAliasSeeder`, which was never deployed.

## 3. Configuration (`.env` on the staging server)
| Key | Staging value |
|---|---|
| `AICAD_SUPPORTED_FACT_GENERATION_ENABLED` | `on` |
| `AICAD_CAMPUS_TIMEZONE` | `Europe/Nicosia` |
| `AI_PROVIDER` | `local` |
| `LOCAL_AI_BASE_URL` / `LOCAL_AI_MODEL` | the GPU host (vLLM) or Ollama with the **`aicad-qwen3:8b`** build (`deploy/ai/ollama/aicad-qwen3-8b.Modelfile`). A bare `qwen3:8b` silently truncates the system prompt |
| `KNOWLEDGE_EMBEDDINGS_ENABLED` / `KNOWLEDGE_EMBEDDINGS_URL` | `true` / the embedder (e.g. `http://127.0.0.1:8801`) |
| `QUEUE_CONNECTION` | `database` (or the server's queue) |
| `ROUTING_BASE_URL` | the OSRM foot graph, if routes are to be tested |

Then run:
```
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```
Also start a worker (`DEPLOYMENT.md` §4): `php artisan queue:work --queue=default --tries=3 --backoff=10 --max-time=3600`

## 4. Staging data readiness (before the soak)
The soak must exercise both "data filled" and "data missing". **Never invent ARUCAD values.** There are two acceptable sources:

1. **Verified values from staff.** Enter them in the admin screens through AICAD Health → **Data-completion checklist**. Priority A first: office phones, the food venue's place and weekly hours, and the 2026–2027 academic year (Admin → Academic years, made active; the old year is deactivated automatically).
2. **Labelled fixtures**, where staff values aren't available yet:
   ```
   php artisan aicad:staging-fixtures          # install
   php artisan aicad:staging-fixtures --remove # remove after the soak
   ```
   - The fixtures are separate rows: ids start with `fixture-`, names with `[FIXTURE]`, e-mails use `example.edu`, phones `+90 000`. Real records are never touched.
   - The set is a building, an office with a phone, a café with a place and Mon–Fri hours, a club with Instagram, e-mail and room, and a team with a place.
   - The command refuses to run in production.

The checklist must show at least:
- one office with a phone
- one food venue with a place and weekly hours
- one club with a social profile and e-mail
- one club and one team with a place
- an active academic year that hasn't ended

Real club and team place links are entered only when staff confirm them.

## 5. Readiness
```
php artisan ask:readiness --live
php artisan ask:readiness --probe-fallbacks --as=<staff e-mail>
```
Expect no `FAIL`. Expect `campus_aliases OK`, and `academic_year OK` once the current year is active. `known_data_gaps WARN` is expected until the checklist is done.

## 6. Evaluations
Record the run ids, then compare with the accepted baseline (`docs/coverage/phase4b_baseline.json`):
```
php artisan ask:evaluate --retrieval-only                                   # all retrieval cases
php artisan ask:evaluate --full --as=<staff>  --id=88 --id=89 ...           # legacy full set (ids from the baseline file), with SupportedFacts off:
                                                                           #   run once with AICAD_SUPPORTED_FACT_GENERATION_ENABLED=off
php artisan ask:evaluate --full --as=<staff> --tag=fact_generation          # facts on
php artisan ask:evaluate --compare=<baseline run>,<new run>                 # 0 newly failing
```
Fixture cases (`staging_fixture` tag) ship **inactive**. Run them by id after installing the fixtures:
```
php artisan tinker --execute='echo App\Models\AiEvaluationCase::where("name","like","4c: fixture:%")->pluck("id")->map(fn($i)=>"--id=".$i)->implode(" ");'
php artisan ask:evaluate --retrieval-only <those --id options>
```

## 7. Controller smoke tests

Run the automated pack through the real API first, from the staging host (see `AICAD_STAGING_SOAK.md` §4):
```
php artisan ask:smoke --base=https://<staging-api>/api/v1 --as=<staff or approved test account>
```
Then the **two-user privacy pack**, which is mandatory: fixture students A and B plus a fixture staff account; B and staff must never receive A's data; personal answers are never cached; public answers still are:
```
php artisan aicad:staging-fixtures
php artisan ask:smoke --privacy --base=https://<staging-api>/api/v1
```
Then do these by hand, from the app or Search Playground, signed in:

| Ask | Expect |
|---|---|
| `dersler ne zaman başlıyor` | Direct, dated, cites the official calendar |
| `Görsel İletişim Tasarımı kaç yıl?` | "4 yıl", cites the programme page |
| `öğrenci işlerinin telefonu ne` | The e-mail, plus the phone if recorded, otherwise "kayıtlı bir telefon numarası yok" |
| `açık yemek yeri var mı` | Planned food chain; open venues only where hours exist |
| `Fotoğraf kulübü hakkında bilgi ver` → `kulübün instagramı ne ve odası nerede?` | Resolves the club; states only canonical fields |
| `basketbol takımı nerede ve maili ne` | Resolves the team; a place only if staff linked one |
| Two students ask "randevum ne zaman?" | Each gets their own answer (the privacy cache test, live) |

## 8. During the soak

Follow `AICAD_STAGING_SOAK.md`: metrics, the failure workflow, Priority-B data and the STAFF_ONLY gate.

- AICAD Health: watch the SupportedFacts rollout counters, UNCERTAIN rate, path errors and the Coverage table.
- A wrong answer becomes an evaluation case (`AICAD_ROLLOUT.md` §5).
- **Rollback** is a config change: `AICAD_SUPPORTED_FACT_GENERATION_ENABLED=off`, then `config:cache` and `queue:restart`. It needs no migration rollback.

## 9. After the soak
- Run `php artisan aicad:staging-fixtures --remove` if fixtures were used.
- Re-run the evaluations and record the results against the gates in `AICAD_ROLLOUT.md` §8.
