# LeadFlow — Lead Management Platform

A CRM-style lead management platform: **capture → qualify → assign → follow up → convert → analyse**.

- **Backend:** Laravel 13 API (Sanctum tokens, PostgreSQL or SQLite), modular-monolith domain services
- **Frontend:** React 19 SPA with Redux Toolkit + RTK Query, React Router, Tailwind CSS v4, Recharts
- **Design:** "Aurora" — living gradient canvas, frosted-glass surfaces, floating dock navigation, ⌘K command capsule, light & dark

See [`docs/PLAN_OF_ACTION.md`](docs/PLAN_OF_ACTION.md) for the feature-parity matrix against Salesforce, HubSpot and Zoho patterns and the phased plan.

## Features

| Area | What you get |
|---|---|
| Capture | Manual entry with duplicate warning, CSV import (auto header mapping, duplicate skip), CSV export, API-key capture endpoint with UTM tracking, **hosted/embeddable web forms** |
| Lead workspace | Aura header with score orb, blueprint-aware journey stepper, **smart insights + next best action**, qualification checklist (BANT), timeline, notes (pin), tasks, sequences, stage history, score breakdown |
| Lifecycle | Configurable statuses with categories, **blueprint required fields per stage**, lost reasons, conversion to Contact + Account + Deal |
| Routing | Assignment rules (round robin, least loaded, specific user, team pools, conditions), **unassigned queue with claim** |
| Scoring | Explainable rule-based scoring with manual adjustments, ratings (cold → very high intent) |
| Engagement | Activity logging (call/email/meeting/SMS/WhatsApp), **email templates with merge fields + send**, **sequences/cadences**, task calendar, reminders |
| Automation | WHEN / IF / THEN workflow builder (tasks, notifications, field updates, tags, status, assignment, notes) with execution log |
| Pipeline | Drag-and-drop deal board, weighted forecast, deal/contact/account workspaces |
| Analytics | Dashboard (KPIs, lead flow, sources, stages, temperature, engagement heatmap), funnel, source ROI, team leaderboard, campaign cost-per-lead, lead aging |
| Common | Global search / command palette, notifications centre, saved views, bulk actions, **merge duplicates**, **recycle bin**, audit log, profile & theme, custom fields, teams, RBAC |
| Integrations | Signed webhooks (HMAC-SHA256, retries), API keys, REST API under `/api/v1` |

### Roles
`admin` (everything) · `manager` (team leads, reassign, delete, playbooks, audit) · `sales_rep` (own leads, claim from queue) · `viewer` (read-only). Every record is scoped to its organization.

## Running locally

Requirements: PHP 8.3+, Composer, Node 20+.

```bash
# API
cd backend
cp .env.example .env
composer install
php artisan key:generate
touch database/database.sqlite          # or point DB_* at PostgreSQL (docker compose up -d)
php artisan migrate --seed              # demo org + 64 realistic leads
php artisan serve                       # http://localhost:8000

# SPA (new terminal)
cd frontend
npm install
npm run dev                             # http://localhost:5173 (proxies /api to :8000)
```

Demo logins (password `password`): `admin@lms.test`, `manager@lms.test`, `riley@lms.test` (sales rep), `viewer@lms.test`.

Optional background processes:

```bash
php artisan queue:work        # webhook deliveries (QUEUE_CONNECTION=database)
php artisan schedule:work     # task reminders every 5 minutes
```

## Tests & checks

```bash
cd backend && php artisan test && ./vendor/bin/pint --test
cd frontend && npm run build   # strict TypeScript + production bundle
```

## API overview

All endpoints are under `/api/v1` and require `Authorization: Bearer <token>` except auth, public forms and capture.

| Method | Path | Purpose |
|---|---|---|
| POST | `auth/register`, `auth/login` | Create workspace / sign in |
| GET/POST/PATCH/DELETE | `leads`, `leads/{id}` | Lead CRUD (filters: search, status, source, owner=me/unassigned, priority, rating, tag, follow_up, campaign) |
| GET | `leads/board`, `leads/queue`, `leads/trash`, `leads/export` | Kanban, claim queue, recycle bin, CSV |
| POST | `leads/{id}/status`, `assign`, `claim`, `convert`, `merge`, `email`, `enrollments` | Lifecycle actions |
| PUT/GET | `leads/{id}/qualification`, `leads/{id}/insights`, `leads/{id}/score` | Qualification, insights, score breakdown |
| POST | `leads/bulk`, `leads/import` | Bulk actions, CSV import |
| GET/POST | `{leads\|deals\|contacts\|accounts}/{id}/activities`, `/notes` | Timeline & notes |
| * | `tasks`, `deals`, `contacts`, `accounts` | Work & CRM records |
| GET | `dashboard`, `reports/leads`, `search`, `meta`, `notifications`, `audit-logs` | Analytics & common |
| * | `settings/*` | Statuses, sources, stages, tags, custom fields, teams, users, rules, workflows, templates, sequences, web forms, webhooks, API keys |
| GET/POST | `forms/{slug}` | Public hosted form |
| POST | `capture/leads` | Server-to-server capture (`X-Api-Key`) |
