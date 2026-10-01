# LeadFlow — Plan of Action

Stack: **Laravel 13 API (Sanctum, PostgreSQL/SQLite)** + **React 19 SPA (Redux Toolkit + RTK Query, React Router, Tailwind v4, Recharts)**.
Architecture: modular monolith with clear domain services (Lead, Assignment, Scoring, Automation, Sequences, Webhooks, Audit), tenant-scoped on every query.

## 1. Feature parity matrix

Legend: ✅ built · 🆕 added in this iteration · ⏭ later phase

| Area | Pattern taken from | Status |
|---|---|---|
| Manual lead entry, custom fields, tags | Salesforce / HubSpot properties | ✅ |
| CSV import with header auto-mapping + duplicate skip | Salesforce Data Import Wizard | ✅ |
| CSV export (formula-injection safe) | all | ✅ |
| Public capture API (API key) + honeypot + UTM tracking | Salesforce Web-to-Lead | ✅ |
| **Hosted web forms + embeddable snippet (form builder)** | Salesforce Web-to-Lead, HubSpot Forms | 🆕 |
| Configurable lifecycle statuses with categories | Zoho / Salesforce lead status picklist | ✅ |
| **Blueprint: required fields per status transition** | Zoho Blueprint / Salesforce validation rules | 🆕 |
| **Qualification checklist (BANT, configurable)** | Salesforce Path "key fields" / HubSpot qualification | 🆕 |
| Assignment rules: round robin, least loaded, specific user, team, conditions | Salesforce assignment rules | ✅ |
| **Lead queues: unassigned queue + "claim" action** | Salesforce Queues | 🆕 |
| Rule-based explainable scoring + manual adjustments | HubSpot lead scoring | ✅ |
| Activity timeline (calls, emails, meetings, SMS, WhatsApp, notes, system) | HubSpot timeline | ✅ |
| Tasks, reminders, follow-up dates, overdue tracking | all | ✅ |
| **Email templates with merge fields + send/log email** | HubSpot templates, Zoho email | 🆕 |
| **Sequences / cadences (multi-step follow-up plans, enroll lead)** | Zoho Cadences, HubSpot Sequences | 🆕 |
| Workflow automation (WHEN/IF/THEN) with execution log | Zoho Workflow, Salesforce Flow | ✅ |
| Conversion: Lead → Contact + Account + Deal | Salesforce/Zoho conversion | ✅ |
| Deal pipeline kanban, weighted value, won/lost | all | ✅ |
| Duplicate detection on create | Salesforce duplicate rules | ✅ |
| **Merge duplicate leads** | Salesforce/Zoho merge | 🆕 |
| **Recycle bin (restore deleted leads)** | Zoho / Salesforce recycle bin | 🆕 |
| **Lead enrichment (domain → company/website)** | Zoho enrichment (Zia) | 🆕 (rule-based, pluggable) |
| **Smart insights & next-best-action** | Zoho Zia / Salesforce Einstein ("AI-ready layer") | 🆕 (deterministic rules, LLM-ready hook) |
| Dashboard, funnel, source, rep, campaign, aging reports | all | ✅ |
| **Activity heatmap & calendar of follow-ups** | HubSpot/Zoho calendar | 🆕 |
| Global search + ⌘K command palette | modern CRMs | ✅ |
| Saved views (personal/shared) | Salesforce list views | ✅ |
| RBAC (admin/manager/rep/viewer) + record-level visibility | Salesforce profiles/sharing | ✅ |
| Multi-tenant isolation, tenant-aware validation | Zoho multi-tenant model | ✅ |
| Audit log of field/ownership/status changes | Salesforce field history | ✅ |
| Notifications (assignment, automation, reminders) | all | ✅ |
| Signed webhooks + retry | HubSpot webhooks | ✅ |
| Teams, campaigns with cost/CPL | Salesforce campaigns | ✅ |
| Email (SMTP via Laravel Mail), **SMS & WhatsApp (Twilio driver, log driver for dev)** | HubSpot/Zoho omnichannel | ✅ |
| **AI lead brief** (Claude: summary, next action, talking points, risk; cached, optional) | Zoho Zia / Einstein | ✅ |
| **Advanced segmentation** (condition builder on the lead list, saved as views) | HubSpot lists / Salesforce list views | ✅ |
| **Custom fields on contacts, accounts and deals** | Salesforce/HubSpot properties | ✅ |
| **Custom page layouts** — drag-and-drop sections, rename/reorder, hide fields, live preview; drives forms and detail panels for leads, contacts, accounts, deals | Salesforce page layouts / Zoho layouts | ✅ |
| **Notes & tasks on every record**, lost-deal reasons, notifications centre page, webhook edit/pause | all | ✅ |
| **Installable mobile app (PWA)** — manifest, icons, offline shell, home-screen shortcuts | mobile CRM apps | ✅ (native store apps ⏭) |

## 2. Phases

1. **Foundation** — auth, organizations, roles, statuses, sources, lead CRUD, search, notes, tags. ✅
2. **Sales workflow** — activities, tasks, assignment, scoring, conversion, contacts/accounts/deals, pipeline. ✅
3. **Automation** — workflow engine, notifications, webhooks, import/export, duplicates, reminders. ✅
4. **Parity pack (this iteration)** — blueprint, qualification checklist, queues/claim, merge, recycle bin, email templates, sequences, web forms, enrichment, insights, calendar/heatmap. 🆕
5. **Analytics** — dashboard, funnel, source/rep/campaign, aging. ✅
6. **Experience redesign** — "Aurora" UI (below). 🆕
7. **Verification** — backend feature tests for every rule, TypeScript build, browser run-through with screenshots of each page.

## 3. UI direction — "Aurora"

Not a stock admin template. Principles:

- **Living canvas**: soft animated aurora gradient mesh behind the app; content floats on frosted-glass panels.
- **Floating dock navigation** instead of a flat sidebar — an icon rail with glowing active state and hover labels.
- **Command capsule** at the top centre (⌘K) as the primary way to move around.
- **Bento dashboard**: asymmetric tiles — a "Pulse" hero with an animated conic score orb, a flowing funnel "river", an activity heatmap, today's focus list.
- **Lead workspace**: status-coloured aura header, glowing score orb, clickable journey stepper (blueprint aware), insights panel, qualification checklist, timeline.
- **Spotlight cards**: cards light up under the cursor; gradient borders on focus; motion kept subtle and respecting `prefers-reduced-motion`.
- Display type **Sora**, body **Inter**; violet → fuchsia → cyan accent gradient; full dark mode (default follows system).

## 4. Definition of done

- All endpoints covered by feature tests (tenant isolation, RBAC, lifecycle, new parity features) and green on SQLite + PostgreSQL.
- `npm run build` (strict TypeScript) passes.
- Every page opened in a real browser against the seeded demo org, screenshots reviewed in light & dark.

## 5. Performance work (iteration 3)

Measured with 5,000 leads / 20,000 activities and a real browser:

| Metric | Before | After | Fix |
|---|---|---|---|
| Dashboard API | 880 ms | 66–104 ms | grouped SQL aggregates instead of loading rows into PHP |
| Reports API | 980 ms | 62 ms | one grouped query per dimension |
| Scroll smoothness | 13–16 fps | 60 fps | removed live `backdrop-filter` from cards and blurred background blobs |
| Page navigation | 320–420 ms | 12–93 ms | non-suspending code-split pages (React 19 holds Suspense fallbacks ≥300 ms) + idle prefetch |
| Parallel API requests (dev server) | serialized | concurrent | `PHP_CLI_SERVER_WORKERS=4` |

## 6. Iteration 4 — Integrations, AI calling, notifications

| Feature | Pattern | Status |
|---|---|---|
| **Integrations hub** — per-organization vendor connections (encrypted credentials, test, disconnect, inbound webhook URLs): SMTP / SendGrid email, Twilio SMS & WhatsApp, Meta WhatsApp Cloud API, Vapi / Retell / Bland AI voice, Anthropic AI, Slack & Microsoft Teams | Salesforce AppExchange / HubSpot App Marketplace | 🆕 |
| **AI voice agents** — goal, opening line, voice, qualification questions, max duration, provider | Vapi/Retell-style agent builders | 🆕 |
| **AI calling** — call one lead or a whole segment (campaign), live status, transcript, summary, outcome, sentiment, extracted answers applied to the lead (qualification, follow-up, callback task), owner notified, workflows triggered | outbound AI SDR | 🆕 |
| **Built-in call simulator** so the flow is demonstrable without a vendor account | — | 🆕 |
| **Calls workspace** — call log, filters, transcript viewer, recording player, campaign progress | call centre / dialer views | 🆕 |
| **Notification preferences** — per user, per event × channel (in-app, email, browser, Slack/Teams), test notification | HubSpot notification settings | 🆕 |
| **Daily email digest** — today's tasks, overdue follow-ups, new leads | Salesforce/HubSpot daily digests | 🆕 |
| **Inbound SMS/WhatsApp** — replies land on the lead timeline and notify the owner | two-way messaging | 🆕 |
| Real Web Push while the app is closed (VAPID) | — | ⏭ (browser notifications work while the app is open or installed) |

## 7. Iteration 5 — Reports for every area, report studio, goals

| Feature | Pattern | Status |
|---|---|---|
| **Report engine** — one whitelisted spec format (record type, measure, grouping, split, filters, date field, range) compiled to a single grouped SQL query on SQLite and PostgreSQL; dates bucketed by day / week / month with empty buckets filled; long tails folded into "Other"; every measure built from additive sums so rates stay correct when merged | Salesforce report types / HubSpot custom report builder | ✅ |
| **Type-wise report pages** — Leads, Pipeline, Activities, Tasks, AI calls, Messaging: KPI tiles with change vs the previous period plus 4–7 charts each (stacked time series, donuts, ranked bars, tables) | Zoho Analytics prebuilt dashboards | ✅ |
| **Report studio** — build any report with live preview, quick-start ideas, filters on fixed-choice fields, bar / stacked / line / area / donut / table / single-number charts, CSV export, table view on every chart | HubSpot custom report builder | ✅ |
| **Saved reports** — private or shared with the organization, pin to dashboard, email now, or schedule weekly (Mondays) / monthly (1st) to up to 10 recipients | Salesforce report subscriptions | ✅ |
| **Goals** — monthly or quarterly targets per person or the whole team (revenue won, deals won, new leads, conversions, activities, calls, meetings, AI-booked meetings, tasks completed) with pace marker and achieved / on track / behind status; on the dashboard and in Insights | HubSpot goals / Salesforce quotas | ✅ |
| Chart colours — fixed-order categorical palette validated for colour-vision deficiency in light and dark; single-series charts use one colour; ≥2 series always have a legend | — | ✅ |

Measured on PostgreSQL with 5,000 leads / 20,000 activities / 1,000 deals: each report page returns in 34–131 ms.

## 8. Roadmap — ideas for the next iterations

Grouped by the job they do. "Next" items reuse what already exists (report engine, integrations hub, AI layer, automation) and are the cheapest wins.

| Area | Idea | Builds on | When |
|---|---|---|---|
| Reports | **Ask in plain English** — "won revenue by rep this quarter" → Claude writes the report spec, the engine runs it | report engine spec is JSON; Anthropic integration | Next |
| Reports | Dashboard builder: several named dashboards, drag-and-drop tiles, shared team dashboards | saved reports + pinning | Next |
| Reports | Time-to-convert and speed-to-lead (first response time) reports, SLA alerts | status history, activities | Next |
| Reports | Report subscriptions to Slack / Teams channels | ChatAlerts + scheduled reports | Next |
| Engagement | Two-way Gmail / Outlook sync with open and click tracking | email integrations | Later |
| Engagement | Calendar sync and public booking links (meeting scheduler) | tasks, AI agents booking meetings | Later |
| Engagement | Shared conversations inbox for SMS / WhatsApp threads | inbound messaging webhook | Next |
| AI | Inbound AI receptionist (answers calls, qualifies, routes to the owner) | voice providers, assignment engine | Later |
| AI | Predictive lead score trained on won / lost history, shown beside the rule score | scoring engine, deals | Later |
| AI | Conversation intelligence for human calls (upload recording → transcript, summary, next step) | CallAnalyzer | Next |
| Sales | Products, price books, quotes with PDF and e-signature | deals | Later |
| Sales | Forecast categories (commit / best case) with manager roll-up | deals, goals | Next |
| Sales | Multiple pipelines (per product or region) | pipeline stages | Later |
| Marketing | Email campaigns with A/B tests and multi-touch attribution | campaigns, sequences, UTM capture | Later |
| Platform | SSO (Google / SAML), two-factor sign-in, field-level permissions | Sanctum, roles | Next |
| Platform | GDPR tools: consent tracking, export / erase a person | audit log | Next |
| Platform | Zapier / Make connector and OAuth apps for the public API | API keys, webhooks | Later |
| Platform | Native mobile shell for the PWA with push notifications | PWA, notification preferences | Later |
