# Uncommitted AICAD work: change inventory

Nothing below is committed. The Ask ARUCAD AI stack and every AICAD phase
live only in the working tree, alongside unrelated uncommitted work
(moderation, frontend assets and more). Stage each group deliberately; never
`git add -A`.

## 1. Privacy hotfix (ship first)

See `docs/HOTFIX_AI_PERSONAL_CACHE.md` and
`docs/patches/hotfix-ai-personal-answer-cache.patch`.

- `backend/app/Services/Ai/AiResponder.php`: the personal bypass and the `v2`
  namespace. This file is untracked as a whole and also carries the rollout's
  `|sf:` key line.
- `backend/app/Http/Controllers/Api/AiController.php`: the generate call's
  cache basis and acceptance callback (the file also carries all AICAD phase
  changes).
- `backend/tests/Feature/AiPersonalAnswerCacheTest.php` (new)
- `backend/tests/Feature/AiCapacityTest.php` and
  `backend/tests/Feature/AicadStabilizationTest.php`: an explicit public
  privacy argument.

## 2. SupportedFacts rollout hardening (Phase 3C.1) and staging readiness

**New files:**
- `backend/app/Services/Ai/Facts/SupportedFactsRollout.php`
- `backend/app/Services/Ai/Facts/FactSupplement.php`
- `backend/app/Services/Ai/ProgrammeCatalog.php`
- `backend/app/Services/Ai/AicadReadiness.php`
- `backend/app/Console/Commands/AskReadiness.php`
- `backend/database/seeders/AiProgrammeAliasSeeder.php`
- `backend/database/seeders/data/aicad_programme_aliases.json`
- `backend/tests/Feature/AicadRolloutHardeningTest.php`
- `backend/tests/Feature/AicadStagingRolloutTest.php`
- `docs/AICAD_ROLLOUT.md`
- `docs/HOTFIX_AI_PERSONAL_CACHE.md`
- `docs/patches/*`

**Modified (rollout parts):**
- **Planning:** `app/Services/Ai/Planning/{OpeningHours,TaskPlanner,PlanningResult}.php`
- **Facts:** `app/Services/Ai/Facts/{ClaimVerifier,SupportedFactBuilder,FactPromptBlock,FactValidator,CandidateFact,SupportedFact,AnswerPlan}.php`
- **AI services:** `app/Services/Ai/Evidence/EvidenceCollector.php`,
  `app/Services/Ai/AskDiagnostics.php`, `app/Services/Ai/AicadHealth.php`,
  `app/Services/Agent/AruverseAgent.php`
- **Evaluation:** `app/Services/Ai/Evaluation/{AssertionEvaluator,EvaluationMetrics,EvaluationComparator,AssertionSuggester,EvaluationRunner}.php`,
  `app/Console/Commands/RunAskEvaluation.php`
- **Aliases:** `app/Models/AiEntityAlias.php` (the `programme` type),
  `app/Filament/Resources/AiEntityAliases/AiEntityAliasResource.php`
- **App wiring:** `app/Providers/AppServiceProvider.php` (scoped rollout
  binding), `app/Http/Controllers/Api/AiController.php` (rollout flow, shadow
  check, fallback, fact verification)
- **Config:** `config/ai.php` (`supported_facts.mode`, `campus_timezone`),
  `backend/.env.example`
- **UI:** `lang/{en,tr,ru}/panel.php` (`playground.*`, `aicad_health.*`,
  `aicad_aliases.types.programme`);
  `resources/views/filament/pages/{ask-playground,aicad-health,ask-evaluation-compare}.blade.php`
- **Seed data:** `database/seeders/data/aicad_evaluation_cases.json`
  (`3c1:*` and `staging:*` cases)
- **Docs:** `docs/AICAD_KNOWLEDGE.md`, `docs/ENVIRONMENTS.md`,
  `docs/DEPLOYMENT.md` §10

## 3. Earlier AICAD phases (1 → 3C), also uncommitted

Knowledge and evaluation, planning (`app/Services/Ai/Planning/*`), evidence
(`app/Services/Ai/Evidence/*`), facts (`app/Services/Ai/Facts/*`) and their
tests (`backend/tests/Feature/Aicad*Test.php`). Many files in group 2 also
carry these phases' changes, so groups 2 and 3 can realistically only be
committed together, as one AI-stack commit or a series built by review.

## Never commit

`.env`, `.env.backup-*`, `storage/logs/*`, evaluation run rows (database), or
the scratch outputs of the acceptance runs.
