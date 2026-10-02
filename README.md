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
| AI | Optional **AI lead brief** by Claude (summary, next best action, talking points, risk) and Claude-powered call analysis — connect Anthropic under Settings → Integrations (or set `ANTHROPIC_API_KEY`) |
| AI calling | **Voice agents** (goal, opening line, qualification questions, voice, language) that call leads one at a time or as **segment campaigns** via Vapi, Retell or Bland; transcript, recording, summary, outcome and sentiment land on the lead timeline, answered questions tick the BANT checklist, a follow-up task is created and the score updates. **AI receptionist** agents answer incoming calls, match or create the caller's lead, qualify them and can **hand the caller to the lead's owner** (warm transfer). **Call notes intelligence** turns pasted notes or an uploaded recording into a summary and next step. A built-in **simulator** demos it all with no vendor |
| Notifications | **Every feature notifies**: lead assigned or waiting for an owner, replies (SMS / WhatsApp / email), AI and incoming calls, tasks given to you, @mentions and teammates' notes / calls on your records, deals won / lost / assigned, leads converted, heating up or opting out, meetings booked, quotes opened / accepted, email campaigns sent, goals reached, speed-to-lead alerts, reminders, new sign-ins and security changes, broken connections and failing webhooks. Everything always lands in the **bell**; by default it also reaches people **outside the website**: phone and desktop push even when LeadFlow is closed, an unread count in the browser tab and on the installed app icon, plus email and Slack / Teams per person and per event. A one-time prompt helps people turn alerts on; the weekday **morning digest** sums up the day |
| Lead workspace | Aura header with score orb, blueprint-aware journey stepper, **smart insights + next best action**, qualification checklist (BANT), timeline, notes (pin), tasks, sequences, stage history, score breakdown |
| Lifecycle | Configurable statuses with categories, **blueprint required fields per stage**, lost reasons, conversion to Contact + Account + Deal |
| Routing | Assignment rules (round robin, least loaded, specific user, team pools, conditions), **unassigned queue with claim** |
| Scoring | Explainable rule-based scoring with manual adjustments, ratings (cold → very high intent) |
| Engagement | Activity logging, **email templates with merge fields + send**, **SMS & WhatsApp sending (Twilio)**, **sequences/cadences**, task calendar, reminders, notes & tasks on every record |
| Automation | WHEN / IF / THEN workflow builder (tasks, notifications, field updates, tags, status, assignment, notes) with execution log |
| Pipeline | **Multiple pipelines**, drag-and-drop deal board, **forecast** (commit / best case / pipeline by month and owner, with quotas), deal/contact/account workspaces |
| Quotes | Products catalogue, quotes with line items, discounts and tax, emailed link to a public quote page with **typed e-signature** (accept / decline) and print-to-PDF |
| Inbox & scheduling | **Shared inbox** for SMS, WhatsApp and email threads; **Gmail / Outlook sync** (emails with leads appear on timelines, sends go from your own address); personal **booking links** that respect your **Google / Outlook calendar**'s busy times and add booked meetings to it; iCal **calendar feed** of tasks and meetings |
| Marketing | **Email campaigns** with segment audiences, **A/B tests** that send the winner automatically, scheduling, open and click tracking; **landing pages** with a block editor; a **UTM link builder** with short, click-counting links; **multi-touch attribution** (first / last / linear) by campaign, source or channel |
| Analytics | **Ask in plain English** ("won revenue by rep this quarter"), **named dashboards**, **speed-to-lead** with response-target alerts, **predictive conversion score**. **Insights hub with a report page per area** — Leads, Pipeline, Activities, Tasks, AI calls, Messaging — each with KPI tiles (change vs previous period) and charts; lead funnel, source ROI, team leaderboard, campaign cost-per-lead, lead aging; date-range presets or custom dates; every chart has a table view and CSV export |
| Report studio | Build any report (record type × measure × grouping × split × filters × chart), save it, share it, **pin it to the dashboard**, email it now or **weekly / monthly** |
| Goals | Monthly or quarterly targets for each person or the whole team (revenue, deals, leads, conversions, activities, calls, meetings, tasks) with pace tracking on the dashboard |
| Common | Global search / command palette, notifications centre + page, saved views, **advanced segment builder**, custom fields on leads/contacts/accounts/deals, **custom page layouts** (drag-and-drop sections, hidden fields, live preview), **installable PWA**, bulk actions, **merge duplicates**, **recycle bin**, audit log, profile & theme, custom fields, teams, RBAC |
| Security & privacy | **Single sign-on** (Google Workspace, Microsoft Entra ID, Okta and any OpenID Connect provider), **two-step sign-in** (authenticator app + recovery codes), **field-level permissions** per role, per-channel **consent** with unsubscribe links and STOP keywords, **export / erase a person** (GDPR) |
| Integrations | **Vendor hub** in Settings → Integrations: SMTP, SendGrid, Twilio (SMS/WhatsApp + inbound), WhatsApp Cloud API, Vapi, Retell, Bland, Anthropic, Deepgram, Slack, Teams — secrets are encrypted per organization, each has a Test button and copyable inbound webhook URL. Plus signed outbound webhooks (HMAC-SHA256, retries), **REST hooks for Zapier / Make**, **OAuth 2.0 apps** (consent screen, scoped tokens), API keys, REST API under `/api/v1` |

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

Mobile app: `mobile/` wraps the SPA for iOS and Android with native push — see [`mobile/README.md`](mobile/README.md).

Demo logins (password `password`): `admin@lms.test`, `manager@lms.test`, `riley@lms.test` (sales rep), `viewer@lms.test`.

Vendors (email, SMS/WhatsApp, AI voice, Claude, Slack/Teams) are connected per organization in **Settings → Integrations**; the `backend/.env.example` values are server-wide fallbacks. The demo organization comes with the AI call simulator connected.

Background processes (needed for AI calls, webhooks and anything scheduled):

```bash
php artisan queue:work        # AI calls, email campaigns, webhook deliveries (QUEUE_CONNECTION=database)
php artisan schedule:work     # task reminders, morning digest, scheduled reports, speed-to-lead alerts,
                              # predictive score refresh, scheduled email campaigns and A/B winners, mailbox sync
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
| GET/POST | `calls`, `calls/stats`, `calls/{id}`, `calls/{id}/cancel`, `leads/{id}/calls`, `ai-agents/{id}/campaign` | AI calling |
| GET/PUT | `auth/notification-preferences`, POST `notifications/test` | Notification preferences |
| * | `settings/integrations`, `settings/ai-agents` | Vendor connections, voice agents |
| POST | `webhooks/voice/{vapi\|retell\|bland}/{token}`, `webhooks/messaging/twilio/{token}` | Inbound vendor callbacks (per-organization token) |
| GET | `reports/catalog`, `reports/type/{leads\|pipeline\|activities\|tasks\|calls\|messaging}` · POST `reports/run` | Report engine and type-wise report pages |
| * | `saved-reports`, `saved-reports/{id}/run`, POST `saved-reports/{id}/send`, `goals` | Saved / scheduled reports and goals |
| POST | `reports/ask` · GET `reports/attribution` · * `dashboards` | Plain-English reports, attribution, dashboards |
| GET | `deals/forecast` · * `deals/{id}/quotes`, `quotes/{id}`, POST `quotes/{id}/send` · * `settings/pipelines`, `settings/products` | Forecast, quotes, pipelines, products |
| GET | `inbox`, `inbox/{leadId}` · GET/PUT `booking-page` · GET/POST `auth/calendar-feed` · POST `leads/{id}/call-notes` | Inbox, booking links, calendar feed, call notes |
| * | `broadcasts`, POST `broadcasts/audience`, `broadcasts/{id}/launch`, `cancel`, `pick-winner`, GET `broadcasts/{id}/recipients` | Email campaigns (admins and managers) |
| POST | `ai-agents/{id}/simulate-inbound` | Ring the AI receptionist from a simulated caller |
| * | `auth/two-factor*` · PUT `leads/{id}/consent`, GET `leads/{id}/export`, POST `leads/{id}/erase` · GET/PUT `settings/field-permissions` | Two-step sign-in, consent & GDPR, field permissions |
| * | `hooks/me`, `hooks/subscriptions`, `hooks/leads`, `hooks/samples/{event}` | REST hooks for Zapier / Make (`X-Api-Key`) |
| GET/POST | `public/quotes/{token}`, `public/book/{slug}`, `public/calendar/{token}.ics`, `public/unsubscribe/…`, `t/o/{token}.gif`, `t/c/{token}/{sig}` | Public quote, booking, calendar, unsubscribe and email-tracking endpoints |
| POST | `auth/sso`, `auth/sso/exchange` · GET `sso/callback` | Single sign-on |
| GET/POST/PATCH/DELETE | `connected-accounts`, `connected-accounts/{google\|microsoft}/connect`, `connected-accounts/{id}/sync` | Gmail / Outlook mailbox and calendar |
| GET/POST/DELETE | `push`, `push/subscriptions` | Push devices (Web Push / FCM) |
| GET/POST | `oauth/authorize` (signed-in person) · POST `oauth/token`, `oauth/revoke` · * `settings/oauth-apps` | OAuth 2.0 apps |
| * | `landing-pages`, `tracked-links` · GET `public/pages/{slug}`, POST `public/pages/{slug}/submit` · GET `/l/{code}` | Landing pages and UTM short links |
| GET/POST | `forms/{slug}` | Public hosted form |
| POST | `capture/leads` | Server-to-server capture (`X-Api-Key`) |
