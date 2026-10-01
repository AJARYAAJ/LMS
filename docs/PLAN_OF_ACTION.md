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

## 8. Iteration 6 — the roadmap, built

Everything marked "Next" and most of "Later" in the previous roadmap is now in the product.

| Area | Feature | Pattern | Status |
|---|---|---|---|
| Reports | **Ask in plain English** — "won revenue by rep this quarter" becomes a report spec (Claude with structured output, rule-based fallback without a key), run by the report engine and saveable | HubSpot AI report assistant | ✅ |
| Reports | **Dashboards** — several named dashboards per person or shared with the team, tiles from saved reports, reorder and resize | Salesforce dashboards | ✅ |
| Reports | **Speed to lead** — first-response time on every lead, response-target (SLA) alerts to the owner and managers, response metrics in the report engine | Salesforce lead response SLAs | ✅ |
| Reports | Scheduled reports delivered to **Slack / Teams** as well as email | Salesforce report subscriptions to Slack | ✅ |
| Sales | **Multiple pipelines** with their own stages; board and reports per pipeline | Pipedrive / HubSpot pipelines | ✅ |
| Sales | **Forecast** — commit / best case / pipeline / closed by month and owner, overrides, quota from goals | Salesforce forecast categories | ✅ |
| Sales | **Products and quotes** — catalogue, line items with discounts and tax, quote email with a public page, **typed e-signature** (accept / decline), printable PDF; acceptance wins the deal | HubSpot quotes / Zoho CPQ | ✅ |
| AI | **Predictive conversion score** — learned per organization from won / lost history, shown next to the rule score with its top reasons, refreshed hourly | Salesforce Einstein lead scoring | ✅ |
| Engagement | **Shared inbox** — SMS, WhatsApp and email threads per lead, unread / needs-reply filters, reply on any channel | HubSpot conversations inbox | ✅ |
| Engagement | **Booking links** — personal "book a meeting" page with working hours, buffers and notice; bookings create or match the lead, add the meeting and notify the host | Calendly / HubSpot meetings | ✅ |
| Engagement | **Calendar feed** (iCal) of tasks and meetings for Google / Outlook / Apple Calendar | — | ✅ |
| AI | **Call notes intelligence** — paste notes or upload a recording (Deepgram) → transcript, summary, outcome, next step and follow-up task | Gong / HubSpot call intelligence | ✅ |
| Platform | **Two-step sign-in** (authenticator app + recovery codes) | — | ✅ |
| Platform | **Consent and GDPR** — per-channel consent with source and time, one-click unsubscribe link in emails, STOP keywords for SMS/WhatsApp, export or erase a person | HubSpot GDPR tools | ✅ |
| Platform | **Field-level permissions** — hide or make read-only any lead / contact / account / deal field per role; enforced in the API, layouts and reports | Salesforce field-level security | ✅ |
| Platform | **REST hooks** for Zapier / Make (subscribe / unsubscribe, sample payloads, polling triggers) | Zapier REST hooks | ✅ |
| Marketing | **Email campaigns** — audience from the segment builder (people who opted out are skipped), personalised content, **A/B test** two versions on a share of the audience and send the winner (by opens or clicks) to the rest automatically, schedule for later, open pixel and signed click tracking, recipient drill-down | Mailchimp / HubSpot marketing email | ✅ |
| Marketing | **Multi-touch attribution** — touches recorded on capture, web form, booking, inbound call and email click; first touch / last touch / linear credit for leads, conversions and won revenue by campaign, source or channel | HubSpot attribution reports | ✅ |
| AI | **AI receptionist** — inbound voice agents answer calls (Vapi / Retell inbound, or the simulator's "Test call"), match the caller by phone or create a lead, learn their name and company, qualify, book or schedule a callback, and notify the owner | AI receptionists | ✅ |

Honest limits: vendor paths that need live accounts (Deepgram transcription, Vapi / Retell inbound calls, SendGrid HTML email) are built to the vendors' documented formats and covered by faked HTTP tests, but were not exercised against the real services. Email opens come from a tracking pixel, so they are approximate (image blocking hides opens; privacy proxies can inflate them); clicks are reliable.

## 9. Iteration 7 — the rest of the roadmap

| Area | Feature | Pattern | Status |
|---|---|---|---|
| Platform | **Single sign-on** — Google Workspace, Microsoft Entra ID or any OpenID Connect provider (Okta, Auth0, OneLogin, Keycloak…) per organization: email domains pick the workspace, new people are created with a chosen role, SSO can be required (admins keep a password fallback). Authorization code + PKCE; the SPA receives a one-time code, never a token in the URL | Salesforce / HubSpot SSO | ✅ |
| Engagement | **Gmail / Outlook mailbox sync** — each person connects their own Google or Microsoft account; emails exchanged with leads land on timelines and in the inbox (deduplicated), owners are notified of replies, and emails sent from LeadFlow go out from the person's real address. Tokens are encrypted and refreshed automatically | HubSpot / Salesforce inbox connect | ✅ |
| Engagement | **Two-way calendar sync** — busy times from the connected calendar block booking-page slots; booked meetings are created on the calendar with the lead invited | Calendly / HubSpot meetings | ✅ |
| Platform | **Push notifications** — standard Web Push (RFC 8030 / 8291 / 8292: aes128gcm encryption and VAPID signing implemented on OpenSSL) to phones and desktops even when LeadFlow is closed; per-event "Push" column, per-device on/off, dead subscriptions pruned | — | ✅ |
| Platform | **Native mobile shell** — `mobile/` Capacitor project for iOS and Android with native push through Firebase Cloud Messaging (HTTP v1, service-account auth) and tap-to-open | Salesforce / HubSpot mobile apps | ✅ (scaffold; build with Xcode / Android Studio) |
| Platform | **OAuth apps for the public API** — admins register server or public (PKCE) apps; people approve them on a consent screen; hour-long access tokens limited to `read` / `write` scopes and kept away from settings, account and security endpoints; rotating refresh tokens with reuse detection; RFC 7009 revocation | Salesforce connected apps / HubSpot OAuth | ✅ |
| Marketing | **Landing pages** — hero, text, features, testimonial, lead form and call-to-action blocks with a live-preview editor, drafts / publishing, SEO fields, views / leads / conversion rate; leads are tagged with the page's campaign and the visitor's UTM tags | HubSpot landing pages | ✅ |
| Marketing | **UTM link builder** — tagged links with short `/l/{code}` URLs that count clicks; every web form now stores UTM tags | HubSpot tracking URLs | ✅ |
| AI | **Receptionist warm transfer** — hand callers to the lead's owner (falling back to a team number); Vapi's per-call assistant request gets the caller's context and a transfer tool; forwarded calls are recorded as "Transferred" | AI receptionists | ✅ |

Honest limits: these vendor paths follow each vendor's documented format and are covered by faked HTTP tests, but were not run against live accounts:

- Google and Microsoft sign-in and mailbox / calendar APIs
- Firebase Cloud Messaging
- Vapi assistant requests and transfers

Web Push encryption is verified end to end in the test suite: the test decrypts the message the way a browser does and checks the VAPID signature. The mobile shell is a scaffold: `npx cap add ios|android` creates the native projects. SAML-only identity providers need an OpenID Connect bridge (most offer one).

## 10. Roadmap — ideas from here

| Area | Idea | Builds on |
|---|---|---|
| Platform | SAML 2.0 for identity providers without OpenID Connect | SSO |
| Platform | SCIM user provisioning and deprovisioning | SSO, roles |
| Engagement | Shared team mailboxes (sales@) routed through assignment rules | mailbox sync, assignment engine |
| Marketing | A/B tests on landing pages, and forms embedded on any site with UTM capture | landing pages, broadcasts |
| AI | Receptionist business hours and voicemail-to-task | receptionist, tasks |
| Platform | Marketplace listing for the OAuth app (Zapier / Make public app) | OAuth apps, REST hooks |
