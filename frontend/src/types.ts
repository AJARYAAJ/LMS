export type Role = 'admin' | 'manager' | 'sales_rep' | 'viewer'
export type Priority = 'low' | 'medium' | 'high' | 'urgent'
export type Rating = 'cold' | 'warm' | 'hot' | 'very_high'
export type StatusCategory = 'open' | 'qualified' | 'converted' | 'lost'

export interface Organization {
  id: number
  name: string
  slug: string
  industry: string | null
  website: string | null
  phone: string | null
  timezone: string
  currency: string
  settings?: Record<string, unknown> | null
}

export interface UserLite {
  id: number
  name: string
  email?: string
  role?: Role
  avatar_color?: string | null
}

export interface User extends UserLite {
  email: string
  role: Role
  organization_id: number
  phone: string | null
  job_title: string | null
  is_active: boolean
  last_login_at: string | null
  preferences: Record<string, unknown> | null
  organization?: Organization
  teams?: Team[]
  open_leads_count?: number
  permissions?: {
    write: boolean
    manage_settings: boolean
    manage_team: boolean
    view_all_leads: boolean
  }
}

export interface Team {
  id: number
  name: string
  description?: string | null
  color: string
  manager_id?: number | null
  manager?: UserLite | null
  members?: UserLite[]
  leads_count?: number
}

export interface LeadStatus {
  id: number
  name: string
  key: string
  category: StatusCategory
  color: string
  display_order: number
  is_active: boolean
  is_default: boolean
  is_terminal: boolean
  required_fields: string[] | null
  leads_count?: number
}

export interface LeadSource {
  id: number
  name: string
  key: string
  color: string
  is_active: boolean
  leads_count?: number
}

export interface Tag {
  id: number
  name: string
  color: string
  leads_count?: number
}

export interface Campaign {
  id: number
  name: string
  lead_source_id: number | null
  source?: Pick<LeadSource, 'id' | 'name' | 'color'> | null
  channel: string | null
  status: 'planned' | 'active' | 'paused' | 'completed'
  budget: string | null
  actual_cost: string | null
  starts_on: string | null
  ends_on: string | null
  description: string | null
  leads_count?: number
  converted_count?: number
  pipeline_value?: string | null
}

export interface PipelineStage {
  id: number
  pipeline_id?: number | null
  name: string
  probability: number
  display_order: number
  color: string
  is_won: boolean
  is_lost: boolean
  deals_count?: number
}

export interface CustomField {
  id: number
  entity: string
  key: string
  label: string
  type: 'text' | 'textarea' | 'number' | 'date' | 'select' | 'boolean'
  options: string[] | null
  is_required: boolean
  display_order: number
}

export interface Lead {
  id: number
  consent?: Record<string, ConsentEntry> | null
  erased_at?: string | null
  conversion_likelihood?: number | null
  first_name: string
  last_name: string | null
  full_name: string
  email: string | null
  phone: string | null
  company: string | null
  job_title: string | null
  website: string | null
  industry: string | null
  company_size: string | null
  city: string | null
  state: string | null
  country: string | null
  lead_status_id: number | null
  lead_source_id: number | null
  campaign_id: number | null
  owner_id: number | null
  team_id: number | null
  priority: Priority
  score: number
  rating: Rating
  budget: string | null
  expected_value: string | null
  timeline: string | null
  requirements: string | null
  qualification: Record<string, boolean> | null
  custom_fields: Record<string, unknown> | null
  next_follow_up_at: string | null
  last_contacted_at: string | null
  assigned_at: string | null
  qualified_at: string | null
  converted_at: string | null
  lost_reason: string | null
  created_at: string
  updated_at: string
  status?: LeadStatus | null
  source?: LeadSource | null
  campaign?: { id: number; name: string } | null
  team?: Team | null
  owner?: UserLite | null
  creator?: UserLite | null
  tags?: Tag[]
  converted_contact?: { id: number; first_name: string; last_name: string | null } | null
  converted_account?: { id: number; name: string } | null
  converted_deal?: { id: number; name: string; amount: string } | null
  notes_count?: number
  activities_count?: number
  tasks_count?: number
}

export interface Activity {
  id: number
  subject_type: string
  subject_id: number
  user_id: number | null
  user?: UserLite | null
  type: 'call' | 'email' | 'meeting' | 'sms' | 'whatsapp' | 'note' | 'task' | 'system'
  title: string
  description: string | null
  direction: 'inbound' | 'outbound' | null
  outcome: string | null
  duration_minutes: number | null
  occurred_at: string
  meta: Record<string, unknown> | null
  subject?: { type: string; id: number; name: string } | null
}

export interface Note {
  id: number
  body: string
  is_pinned: boolean
  user_id: number | null
  user?: UserLite | null
  created_at: string
  updated_at: string
}

export interface Task {
  id: number
  title: string
  description: string | null
  type: 'call' | 'email' | 'meeting' | 'follow_up' | 'todo'
  priority: Priority
  due_at: string | null
  reminder_at: string | null
  completed_at: string | null
  assigned_to: number | null
  assignee?: UserLite | null
  taskable_type: string | null
  taskable_id: number | null
  taskable?: { type: string; id: number; name: string } | null
  is_overdue: boolean
  created_at: string
}

export interface Account {
  id: number
  custom_fields?: Record<string, unknown> | null
  name: string
  domain: string | null
  industry: string | null
  company_size: string | null
  phone: string | null
  website: string | null
  city: string | null
  country: string | null
  annual_revenue: string | null
  owner_id: number | null
  owner?: UserLite | null
  description: string | null
  contacts_count?: number
  deals_count?: number
  open_pipeline?: string | null
  contacts?: Contact[]
  deals?: Deal[]
  created_at: string
}

export interface Contact {
  id: number
  custom_fields?: Record<string, unknown> | null
  first_name: string
  last_name: string | null
  email: string | null
  phone: string | null
  job_title: string | null
  account_id: number | null
  account?: { id: number; name: string } | null
  owner_id: number | null
  owner?: UserLite | null
  lead_id: number | null
  deals_count?: number
  deals?: Deal[]
  created_at: string
}

export interface Deal {
  id: number
  custom_fields?: Record<string, unknown> | null
  name: string
  account_id: number | null
  contact_id: number | null
  lead_id: number | null
  pipeline_stage_id: number | null
  pipeline_id?: number | null
  forecast_category?: ForecastCategory | null
  forecast_override?: boolean
  owner_id: number | null
  amount: string
  currency: string
  probability: number
  expected_close_date: string | null
  status: 'open' | 'won' | 'lost'
  closed_at: string | null
  lost_reason: string | null
  description: string | null
  account?: { id: number; name: string } | null
  contact?: { id: number; first_name: string; last_name: string | null } | null
  owner?: UserLite | null
  stage?: Pick<PipelineStage, 'id' | 'name' | 'color' | 'probability'> | null
  lead?: { id: number; first_name: string; last_name: string | null } | null
  created_at: string
}

export interface Condition {
  field: string
  operator: string
  value?: string | null
}

export interface AssignmentRule {
  id: number
  name: string
  priority: number
  is_active: boolean
  conditions: Condition[] | null
  strategy: 'round_robin' | 'least_loaded' | 'specific_user'
  user_ids: number[] | null
  team_id: number | null
  team?: { id: number; name: string } | null
}

export interface ScoringRule {
  id: number
  name: string
  field: string
  operator: string
  value: string | null
  points: number
  is_active: boolean
}

export interface AutomationAction {
  type: string
  params?: Record<string, string | number | null>
}

export interface AutomationRule {
  id: number
  name: string
  description: string | null
  trigger: string
  conditions: Condition[] | null
  actions: AutomationAction[]
  is_active: boolean
  run_count: number
  last_run_at: string | null
}

export interface Webhook {
  id: number
  name: string
  url: string
  events: string[]
  is_active: boolean
  last_triggered_at: string | null
  last_status: number | null
}

export interface ApiKey {
  id: number
  name: string
  prefix: string
  last_used_at: string | null
  created_at: string
}

export interface AuditLog {
  id: number
  user?: UserLite | null
  auditable_type: string | null
  auditable_id: number | null
  event: string
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip_address: string | null
  created_at: string
}

export interface AppNotification {
  id: string
  title: string
  body: string
  url: string | null
  kind: string
  browser?: boolean
  in_app?: boolean
  read_at: string | null
  created_at: string
}

export interface SavedView {
  id: number
  name: string
  entity: string
  filters: Record<string, string>
  is_shared: boolean
  user_id: number
  user?: UserLite
}

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export interface Meta {
  statuses: LeadStatus[]
  sources: LeadSource[]
  campaigns: Pick<Campaign, 'id' | 'name' | 'status' | 'lead_source_id'>[]
  tags: Tag[]
  teams: Pick<Team, 'id' | 'name' | 'color'>[]
  users: UserLite[]
  stages: PipelineStage[]
  pipelines: Pick<Pipeline, 'id' | 'name' | 'is_default'>[]
  products: Pick<Product, 'id' | 'name' | 'sku' | 'unit_price' | 'billing' | 'description'>[]
  custom_fields: CustomField[]
  email_templates: Pick<EmailTemplate, 'id' | 'name' | 'category' | 'subject' | 'body'>[]
  sequences: Pick<Sequence, 'id' | 'name' | 'description' | 'steps'>[]
  qualification_criteria: { key: string; label: string }[]
  features: { ai: boolean; messaging_driver: string; voice: string | null; email_provider: string; voice_providers: { id: number; provider: string; name: string }[] }
  layouts: Record<LayoutEntity, PageLayout>
  field_access?: Partial<Record<'lead' | 'deal', FieldAccess>>
  enums: {
    priorities: Priority[]
    ratings: Rating[]
    roles: Role[]
    status_categories: StatusCategory[]
    operators: string[]
    condition_fields: string[]
    automation_triggers: string[]
    automation_actions: string[]
    webhook_events: string[]
    merge_fields: string[]
    form_field_keys: string[]
    task_types: string[]
  }
}

export interface SearchResult {
  type: 'lead' | 'contact' | 'account' | 'deal'
  id: number
  title: string
  subtitle: string | null
  badge?: string | null
  color?: string | null
  url: string
}

export interface EmailTemplate {
  id: number
  name: string
  category: string
  subject: string
  body: string
  usage_count: number
  creator?: UserLite | null
  created_at: string
}

export interface SequenceStep {
  day_offset: number
  type: string
  title: string
  email_template_id?: number | null
}

export interface Sequence {
  id: number
  name: string
  description: string | null
  steps: SequenceStep[]
  is_active: boolean
  active_enrollments_count?: number
  completed_enrollments_count?: number
}

export interface Enrollment {
  id: number
  status: 'active' | 'completed' | 'stopped'
  sequence: Pick<Sequence, 'id' | 'name' | 'steps'>
  tasks_count: number
  completed_tasks_count: number
  created_at: string
  completed_at: string | null
}

export interface WebFormField {
  key: string
  label: string
  type: 'text' | 'email' | 'tel' | 'number' | 'textarea'
  required?: boolean
}

export interface WebForm {
  id: number
  name: string
  slug: string
  title: string | null
  description: string | null
  fields: WebFormField[]
  lead_source_id: number | null
  campaign_id: number | null
  tag_ids: number[] | null
  submit_label: string
  success_message: string
  redirect_url: string | null
  accent_color: string
  is_active: boolean
  submissions_count: number
  source?: { id: number; name: string; color: string } | null
}

export interface Insights {
  summary: string
  next_action: { title: string; type: string; reason: string }
  signals: { tone: 'positive' | 'warning' | 'negative'; text: string }[]
  qualification: { percent: number; missing: string[] }
  prediction?: ConversionPrediction | null
}

export interface AiBrief {
  summary: string
  next_action_title: string
  next_action_reason: string
  talking_points: string[]
  risk: string
  model: string
  generated_at: string
}

export type LayoutEntity = 'lead' | 'contact' | 'account' | 'deal'

export interface PageLayout {
  sections: { title: string; fields: string[] }[]
  hidden: string[]
  customized: boolean
}

export interface IntegrationField {
  key: string
  label: string
  required?: boolean
  secret?: boolean
  placeholder?: string
  options?: string[]
}

export interface IntegrationProvider {
  key: string
  category: string
  name: string
  description: string
  fields: IntegrationField[]
  connection: {
    id: number
    is_active: boolean
    status: 'connected' | 'error'
    last_error: string | null
    last_tested_at: string | null
    values: Record<string, string | null>
    secrets_set: Record<string, boolean>
    inbound_url: string | null
  redirect_url?: string | null
  } | null
}

export interface AiAgent {
  id: number
  mode: 'outbound' | 'inbound'
  name: string
  integration_id: number | null
  integration?: { id: number; provider: string; status: string } | null
  goal: string
  first_message: string
  voice: string
  language: string
  questions: { key: string; question: string }[] | null
  max_duration_seconds: number
  is_active: boolean
  calls_count?: number
  completed_calls_count?: number
  meetings_count?: number
}

export type CallStatus = 'queued' | 'ringing' | 'in_progress' | 'completed' | 'no_answer' | 'voicemail' | 'failed' | 'canceled'

export interface Call {
  id: number
  lead_id: number | null
  lead?: { id: number; first_name: string; last_name: string | null; company: string | null; phone: string | null } | null
  ai_agent_id: number | null
  agent?: { id: number; name: string; questions?: { key: string; question: string }[] | null } | null
  user?: { id: number; name: string } | null
  campaign_key: string | null
  direction: 'outbound' | 'inbound'
  provider: string
  to_number: string
  status: CallStatus
  started_at: string | null
  ended_at: string | null
  duration_seconds: number | null
  recording_url: string | null
  transcript?: { role: 'agent' | 'lead'; text: string }[] | null
  summary: string | null
  outcome: string | null
  sentiment: 'positive' | 'neutral' | 'negative' | null
  extracted: { confirmed?: string[]; follow_up_at?: string | null; next_step?: string | null; analyzer?: string } | null
  error: string | null
  created_at: string
}

export interface CallStats {
  total: number
  active: number
  connected: number
  connect_rate: number
  meetings: number
  interested: number
  avg_duration: number
  outcomes: Record<string, number>
}

export interface NotificationPrefs {
  kinds: { kind: string; label: string; in_app: boolean; email: boolean; browser: boolean }[]
  digest: boolean
  chat_alert_kinds: string[]
}

// ------------------------------------------------------------ reports & goals
export type ReportEntity = 'leads' | 'deals' | 'activities' | 'tasks' | 'calls'
export type ReportChartType = 'bar' | 'stacked' | 'line' | 'area' | 'pie' | 'table' | 'number'
export type ReportFormat = 'number' | 'money' | 'percent' | 'decimal' | 'duration' | 'hours'

export interface ReportSpec {
  entity: ReportEntity
  metric: string
  dimension?: string | null
  split?: string | null
  date_field?: string | null
  filters?: Record<string, (string | number | null)[]>
  chart?: ReportChartType
  range?: string
  from?: string | null
  to?: string | null
  granularity?: 'day' | 'week' | 'month' | null
  limit?: number
}

export interface ReportRow {
  key: string
  label: string
  color: string | null
  value: number
  values?: Record<string, number>
}

export interface ReportResult {
  spec: Required<Pick<ReportSpec, 'entity' | 'metric' | 'chart' | 'range'>> & ReportSpec
  entity_label: string
  metric_label: string
  dimension_label: string | null
  split_label: string | null
  dimension_type: 'date' | 'model' | 'enum' | 'text' | null
  format: ReportFormat
  granularity: 'day' | 'week' | 'month' | null
  range: { preset: string; label: string; from: string; to: string }
  total: number
  rows: ReportRow[]
  series: { key: string; label: string; color: string | null }[]
  report?: { id: number; name: string; description: string | null }
}

export interface ReportCatalog {
  entities: {
    key: ReportEntity
    label: string
    dimensions: { key: string; label: string; type: 'date' | 'model' | 'enum' | 'text'; filterable: boolean; values: { value: string; label: string }[] | null }[]
    metrics: { key: string; label: string; format: ReportFormat }[]
  }[]
  ranges: { key: string; label: string }[]
  charts: ReportChartType[]
  types: { key: string; label: string }[]
  goal_metrics: { key: string; label: string; format: ReportFormat }[]
}

export interface ReportKpi {
  label: string
  value: number
  previous: number
  delta: number | null
  delta_unit: '%' | 'pts'
  format: ReportFormat
  better: 'up' | 'down'
}

export interface TypeReport {
  type: string
  title: string
  range: ReportResult['range']
  kpis: ReportKpi[]
  widgets: { title: string; subtitle: string | null; span: 1 | 2; result: ReportResult }[]
}

export interface SavedReport {
  id: number
  user_id: number
  user?: { id: number; name: string } | null
  name: string
  description: string | null
  spec: ReportSpec
  is_shared: boolean
  pinned: boolean
  schedule: 'none' | 'weekly' | 'monthly'
  recipients: string[] | null
  post_to_chat: boolean
  last_sent_at: string | null
  created_at: string
}

export interface Goal {
  id: number
  metric: string
  metric_label: string
  format: ReportFormat
  period: 'month' | 'quarter'
  period_label: string
  user: { id: number; name: string; avatar_color: string | null } | null
  user_id: number | null
  target: number
  actual: number
  percent: number
  expected_percent: number
  status: 'achieved' | 'on_track' | 'behind'
  days_left: number
}

export interface DashboardTile {
  id: string
  kind: 'report' | 'spec' | 'kpis' | 'goals'
  span: 1 | 2
  title?: string | null
  report_id?: number
  spec?: ReportSpec
  type?: string
}

export interface CustomDashboard {
  id: number
  user_id: number
  user?: { id: number; name: string } | null
  name: string
  is_shared: boolean
  tiles: DashboardTile[]
}

export interface AskAnswer {
  question: string
  title: string
  interpreter: 'claude' | 'rules'
  result: ReportResult
}

// ------------------------------------------------------------ sales tools
export type ForecastCategory = 'pipeline' | 'best_case' | 'commit' | 'closed' | 'omitted'

export interface Pipeline {
  id: number
  name: string
  is_default: boolean
  display_order: number
  deals_count?: number
  stages_count?: number
}

export interface Product {
  id: number
  name: string
  sku: string | null
  description: string | null
  unit_price: number
  billing: 'one_time' | 'monthly' | 'yearly'
  is_active: boolean
}

export interface QuoteItem {
  id?: number
  product_id?: number | null
  name: string
  description?: string | null
  quantity: number
  unit_price: number
  discount_percent: number
  total?: number
}

export interface Quote {
  id: number
  deal_id: number
  number: string
  title: string
  status: 'draft' | 'sent' | 'accepted' | 'declined' | 'expired'
  currency: string
  discount_percent: number
  tax_percent: number
  subtotal: number
  total: number
  valid_until: string | null
  notes: string | null
  sent_at: string | null
  viewed_at: string | null
  responded_at: string | null
  signed_name: string | null
  decline_reason: string | null
  items: QuoteItem[]
  creator?: { id: number; name: string } | null
  public_url?: string
  created_at: string
}

export interface PublicQuote extends Pick<Quote, 'number' | 'title' | 'status' | 'currency' | 'discount_percent' | 'tax_percent' | 'subtotal' | 'total' | 'valid_until' | 'notes' | 'signed_name' | 'responded_at' | 'created_at'> {
  items: QuoteItem[]
  organization: { name: string; website: string | null; phone: string | null }
  prepared_by: { name: string; email: string } | null
  customer: string | null
}

export interface ForecastRow {
  closed: number
  commit: number
  best_case: number
  pipeline: number
  omitted: number
  projected: number
  best_projection: number
  weighted: number
  quota: number | null
  attainment: number | null
  deals: number
}

export interface Forecast {
  period: string
  period_label: string
  from: string
  to: string
  total: ForecastRow
  reps: (ForecastRow & { owner: UserLite | null })[]
  deals: (Pick<Deal, 'id' | 'name' | 'amount' | 'currency' | 'probability' | 'status' | 'forecast_category' | 'forecast_override' | 'expected_close_date' | 'closed_at' | 'owner_id' | 'owner'> & { stage: Pick<PipelineStage, 'id' | 'name' | 'color'> | null })[]
}

export interface ConversionPrediction {
  likelihood: number
  base: number
  factors: { label: string; effect: number }[]
  trained_on: number
}

// ------------------------------------------------------------ engagement
export interface InboxThread {
  lead: { id: number; first_name: string; last_name: string | null; company: string | null; email: string | null; phone: string | null; owner: UserLite | null }
  last: { type: 'sms' | 'whatsapp' | 'email'; title: string; description: string | null; direction: 'inbound' | 'outbound' | null; occurred_at: string } | null
  messages: number
  unread: number
  awaiting_reply: boolean
}

export interface InboxConversation {
  lead: { id: number; first_name: string; last_name: string | null; company: string | null; email: string | null; phone: string | null; owner_id: number | null; owner: UserLite | null; status: { id: number; name: string; color: string } | null; consent?: Record<string, { status: string; at?: string }> | null }
  messages: { id: number; type: 'sms' | 'whatsapp' | 'email'; title: string; description: string | null; direction: 'inbound' | 'outbound' | null; occurred_at: string; user: { id: number; name: string } | null }[]
}

export interface BookingPageSettings {
  id?: number
  slug: string
  title: string
  description: string | null
  duration_minutes: number
  buffer_minutes: number
  notice_hours: number
  days_ahead: number
  weekdays: number[]
  start_time: string
  end_time: string
  timezone: string
  is_active: boolean
  url?: string
}

export interface PublicBookingPage {
  title: string
  description: string | null
  duration_minutes: number
  timezone: string
  host: { name: string; job_title: string | null; avatar_color: string | null }
  organization: string
  slots: Record<string, string[]>
}

// ------------------------------------------------------------ security & privacy
export interface ConsentEntry { status: 'granted' | 'denied' | 'unknown'; at?: string; source?: string }

export type FieldLevel = 'edit' | 'read' | 'hidden'

export interface FieldPermissionSettings {
  roles: ('manager' | 'sales_rep' | 'viewer')[]
  levels: FieldLevel[]
  fields: Record<'lead' | 'deal', string[]>
  rules: Partial<Record<'lead' | 'deal', Record<string, Partial<Record<'manager' | 'sales_rep' | 'viewer', FieldLevel>>>>>
}

export interface FieldAccess { hidden: string[]; readonly: string[] }

export interface BroadcastVariant { key: 'A' | 'B'; subject: string; body: string }

export interface BroadcastInput {
  name: string
  campaign_id: number | null
  conditions: Condition[]
  variants: Omit<BroadcastVariant, 'key'>[]
  test_percent: number
  winner_metric: 'open' | 'click'
  winner_after_hours: number
}

export type BroadcastStatus = 'draft' | 'scheduled' | 'testing' | 'sending' | 'sent' | 'canceled'

export interface Broadcast extends Omit<BroadcastInput, 'variants'> {
  id: number
  variants: BroadcastVariant[]
  status: BroadcastStatus
  winner_key: 'A' | 'B' | null
  scheduled_at: string | null
  started_at: string | null
  winner_at: string | null
  sent_at: string | null
  created_at: string
  creator?: { id: number; name: string } | null
  campaign?: { id: number; name: string } | null
  summary?: { recipients: number; sent: number; open_rate: number; click_rate: number }
}

export interface BroadcastVariantStats { key: 'A' | 'B'; subject: string; sent: number; opened: number; clicked: number; open_rate: number; click_rate: number }

export interface BroadcastDetail extends Broadcast {
  stats: { recipients: number; sent: number; held: number; skipped: number; opened: number; clicked: number; open_rate: number; click_rate: number; variants: BroadcastVariantStats[] }
}

export interface BroadcastRecipientRow {
  id: number
  variant: 'A' | 'B' | null
  status: 'queued' | 'held' | 'sent' | 'skipped' | 'failed'
  reason: string | null
  sent_at: string | null
  opened_at: string | null
  clicked_at: string | null
  open_count: number
  click_count: number
  lead: { id: number; first_name: string; last_name: string | null; email: string | null; company: string | null } | null
}

export type AttributionModel = 'first' | 'last' | 'linear'
type ByModel = Record<AttributionModel, number>

export interface AttributionResult {
  by: 'campaign' | 'source' | 'channel'
  range: { preset: string; label: string; from: string; to: string }
  rows: { key: string; label: string; touches: number; leads: ByModel; conversions: ByModel; revenue: ByModel | null }[]
  totals: { leads: number; converted: number; revenue: number | null; touches: number }
}

export interface ConnectedAccount {
  id: number
  provider: 'google' | 'microsoft'
  email: string
  sync_mail: boolean
  sync_calendar: boolean
  last_synced_at: string | null
  last_error: string | null
}
