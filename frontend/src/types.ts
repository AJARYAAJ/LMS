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
  custom_fields: CustomField[]
  email_templates: Pick<EmailTemplate, 'id' | 'name' | 'category' | 'subject' | 'body'>[]
  sequences: Pick<Sequence, 'id' | 'name' | 'description' | 'steps'>[]
  qualification_criteria: { key: string; label: string }[]
  features: { ai: boolean; messaging_driver: string }
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
