# RunPlan AI — Build Plan & Spec Conformance

Spec: `PROJECT SPECIFICATION.md` v1.1 (Team SDLC worksheet 2026-10-15).
Audited: API `5fee220` + LavaLust 4.6.0, frontend `runplan_frontend` (React 19 / Vite 8).
**Strava is explicitly excluded from this plan** (spec §5.5 policy gate). Everything here is built so
that a Strava connector can be added later without ever entering the analytics or AI path.

Legend: ✅ done · 🟡 partial · ❌ missing · ⚠️ built but deviates from spec

---

## 1. Conformance matrix

### Functional requirements

| ID | Requirement | Status | Gap |
|---|---|---|---|
| FR-01 | Register/login/logout, **password recovery**, Google sign-in, reCAPTCHA, profile | 🟡 | email/pw auth ✅, profile ✅. **Missing:** recovery, Google, reCAPTCHA, account deletion |
| FR-02 | Personalised plan generation, Gemini-assisted, backend validation, preview→approve | ⚠️ | Generator + draft/approve flow ✅ and validation is genuinely strict. **Missing:** Gemini path (`ai_plan_requests` never written), preferred time-of-day not used in scheduling |
| FR-03 | Activity logging, metrics, dashboard, AI feedback | 🟡 | CRUD + pace/consistency/dashboard ✅ (well done). **Missing:** AI feedback (`ai_feedback` never written), progress charts data shape |
| FR-04 | Revise/reschedule/skip/regenerate future sessions, **validate**, version history, preserve completed | ⚠️ | reschedule/skip/rebuild + `plan_revisions` ✅. **Deviation:** rebuild applies immediately with no confirm step; `decision` never recorded |
| FR-05 | 6 integrations, isolated server-side modules, status + error reporting | ❌ | no service layer exists at all |

### Integrations (spec §5) — all six are ❌ and none are scaffolded

| # | Integration | Backend today | Tables ready | Frontend today |
|---|---|---|---|---|
| 1 | Google Gemini (plan draft **and** feedback) | none | `ai_plan_requests`, `ai_feedback` | none |
| 2 | OpenWeatherMap | none | `weather_cache`, `settings` | none |
| 3 | Google Calendar | none | `integration_accounts`, `calendar_event_mappings` | none |
| 4 | Firebase Cloud Messaging | none | `notification_preferences`, `push_subscriptions`, `notification_jobs` | none |
| 5 | Google Identity Services | none | `user_identities` | no button; no link flow |
| 6 | Google reCAPTCHA | none | — (never store tokens) | no widget on register |

Supporting tables already exist with sane columns and FKs — the DB work is mostly **additions**, not redesign.

### Test cases §10.2 — TC-01…TC-15 are all `Pending`, and there is **no test harness in either repo**
(no `composer.json`, no PHPUnit, no CI, no JS tests).

---

## 2. Deviations to correct (not just gaps)

| # | Finding | Why it matters | Fix |
|---|---|---|---|
| **D1** | `POST /plans/{id}/rebuild` deletes and rewrites future sessions **immediately**. Spec Module 4 workflow steps 4–6 require the UI to show affected sessions, then the runner confirms or cancels. | An unreviewed, irreversible edit to a user's plan — and it silently contradicts §2.6 "automatic acceptance of AI-generated schedules" being out of scope | Split into `rebuild` → proposal + `POST /plans/{id}/revisions/{n}/apply` / `…/reject` |
| **D2** | `plan_revisions` = (`version`, `change_summary`, `snapshot_json`). Spec §8 wants `previous_version`, `proposed_changes`, `decision`, `decision_timestamp`, `resulting_version`, `reason`. | Cannot express "proposed" or "rejected", so the audit trail the spec promises doesn't exist | Migration `012` adds the missing columns; keep the old three (already in use) |
| **D3** | `runner_profiles` has no **location** (weather needs it) and no **timezone** (`notification_preferences.timezone` is the only one). | §5.2 and §5.4 both require these | Add `location`, `location_lat/lon` or `location_query`; keep timezone on notification_preferences as the single source |
| **D4** | No password recovery anywhere — no table, no endpoint, no UI. | FR-01 requires it, and it is a reCAPTCHA-protected form per §5.7 | `password_reset_tokens` table + `POST /api/auth/forgot-password`, `/reset-password` + frontend page |
| **D5** | No account/data deletion workflow (§3.1, §9.2, §11.3 "provider data"). | Explicit privacy requirement | `deletion_requests` + `DELETE /api/account` with cascade + Google Calendar event cleanup |
| **D6** | `settings` (`provider_config_key`, `enabled`) and `integration_accounts.connection_status` exist but no **connect/disconnect/status endpoints**. | §6.1 "Integrations & Settings" screen has nothing to call | `GET /api/integrations`, `POST /api/integrations/{p}/connect`, `DELETE …` |
| **D7** | 3 backend endpoints have **no frontend consumer**: `/api/auth/me`, `/api/plans/{id}/rebuild`, `/api/plans/{id}/revisions`. | Dead surface — the revision history you just built is invisible to users | Wire into frontend: session bootstrap via `/me`, RevisionPanel |
| **D8** | Preferred **time of day** is stored (`preferred_time`) but never used when generating sessions; sessions carry only a date. | §2.3 and FR-02 both list it; and Calendar events + reminders need a start time (§5.3 "Event creation requires start and end values") | Add `planned_start_time` to `training_sessions`, set from profile at generation |
| **D9** | `activities.source` is `VARCHAR(20)` default `manual` — good — but **no query filters on it**. | TC-10/TC-15 require Strava data be excluded from analytics/AI. Excluding nothing is not enforcement; the filter must exist before a Strava path ever lands | Scope metrics + AI summaries to `source='manual'` now |
| **D10** | Framework edits in `scheme/libraries/Api.php` include the `token_hash` column name. | App schema hardcoded into a vendored framework; a LavaLust upgrade deletes it | Move to `app/config/api.php` as `refresh_token_column` |
| **D11** | `app/config/middleware.php` is empty — every controller hand-calls `require_jwt()`. | 4 new controllers coming; one forgotten call = a data leak | Add an `auth` middleware + route groups, keep per-controller checks as defence in depth |

Fixed already in this pass: **`PlanModel::build()` guard mismatch** (wrong message + the deleted
`MAX_WEEKS` upper bound that let a 52-week race produce a 1-year plan), and the 8 mistyped-shell
junk files are now untracked.

---

## 3. Build order (Core-First, External Integrations Deferred to Stage B)

Each phase is independently shippable and leaves the app working. `A`=API repo, `F`=frontend repo.

### Stage A — Core First-Party Backend + React Frontend (No External Integrations)

#### Phase 0 — Core Foundations & Safety Net ✅ *(Complete)*
- A: `PlanModel::build()` race-date guard fix (`MIN_WEEKS` "too close" vs `MAX_WEEKS` "more than 30 weeks away"), untracked 8 shell-junk files, hardened `.gitignore`.
- A: `app/libraries/HttpClient.php` — thin zero-dependency cURL wrapper ready for Stage B.
- A: `app/config/integrations.php` + `app/helpers/integration_helper.php` + `GET /api/integrations/status` — all providers OFF by default (`core_enabled: true`), zero secrets exposed.
- A: `.env.example` (`DB_NAME=runplandb`), `README.md`, `composer.json`, `scripts/check.sh`, `tests/run.php`, and `.github/workflows/ci.yml`.
- A: MVC layering cleanup — `UserModel`, `RefreshTokenModel`, `SessionModel`, `ActivityModel::longest_recent_run_km()`, `?from=&to=` activity date filter, `GET /api/activities/{id}`, `source='manual'` boundary (`D9`), configurable `refresh_token_column` (`D10`), zero `$this->db->…` calls in any controller.
- A: `AuthMiddleware` (`D11`) wired onto protected routes + `OPTIONS /api/*` CORS preflight (`Health::preflight`).

#### Phase 1 — Core First-Party Account Management *(Next — Guided Step-by-Step)*
- A: Migration `012` (core tables/columns: `password_reset_tokens`, `account_deletion_requests`, `auth_security_events`, plus core plan/session/profile columns).
- A: `PasswordResetModel`, `SecurityEventModel`, `Auth::forgot_password`, `Auth::reset_password`, and `Account` controller (`GET /api/account`, `POST /api/account/password`, `DELETE /api/account`).
- F: `ForgotPassword.jsx`, `ResetPassword.jsx`, and `Account.jsx` (change password + delete account confirmation).

#### Phase 2 — Core Plan Revisions, Rescheduling & Frontend Completion *(FR-04, D1/D2/D7/D8)*
- A: `planned_start_time` from `preferred_time`, `desired_sessions_per_week`, `skip_reason`, and two-step revision workflow (`propose` preview → `apply` confirm / `reject` cancel) with full `decision` / `reason` history.
- F: `Calendar.jsx` session date rescheduling + skip reason, `Plan.jsx` rebuild preview/confirm/cancel + revision history viewer, `ActivityList.jsx` date-range filter, `Profile.jsx` desired sessions/week.

---

### Stage B — Deferred External Integrations *(After Stage A is Verified)*

#### Phase 3 — Google Identity Services + Google reCAPTCHA *(§5.6, §5.7)*
- A/F: Google ID token verification (`user_identities`, explicit linking, no email auto-link) + reCAPTCHA verification on public forms.

#### Phase 4 — Google Gemini *(FR-02, FR-03, §5.1)*
- A/F: Structured AI plan proposals validated through `PlanModel::build()` with deterministic template fallback (`ai_plan_requests`) + coaching feedback (`ai_feedback`).

#### Phase 5 — OpenWeatherMap Advisories *(§5.2)*
- A/F: Forecast horizon check, `weather_cache`, and workout weather strip.

#### Phase 6 — Google Calendar Sync *(§5.3)*
- A/F: Opt-in OAuth flow, encrypted tokens in `integration_accounts`, idempotent `calendar_event_mappings` create/update.

#### Phase 7 — Push Reminders & Final Verification *(§5.4, §10)*
- A/F: Web Push / FCM subscriptions, `notification_jobs` worker, quiet hours, in-app fallback, and full TC-01…TC-15 verification *(Strava remains excluded)*.

---

## 4. Confirmed environment notes
Laragon · MySQL 8.4.3 · `127.0.0.1` · live database `runplandb` (`DB_NAME=runplandb`) · user `root`, no password · `utf8mb4` · API proxied at `127.0.0.1:3000` by Vite (`http://localhost:5173`).