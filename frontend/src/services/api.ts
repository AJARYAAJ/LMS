import { createApi, fetchBaseQuery, type BaseQueryFn, type FetchArgs, type FetchBaseQueryError } from '@reduxjs/toolkit/query/react'
import type {
  Account, Activity, AiAgent, AiBrief, ApiKey, Call, CallStats, Condition, IntegrationProvider, LayoutEntity, NotificationPrefs, PageLayout, EmailTemplate, Enrollment, Insights, Sequence, WebForm, WebFormField, AppNotification, AssignmentRule, AuditLog, AutomationRule, Campaign, Contact,
  CustomField, Deal, Lead, LeadSource, LeadStatus, Meta, Note, Paginated, PipelineStage, SavedView,
  ScoringRule, SearchResult, Tag, Task, Team, User, Webhook, Goal, AskAnswer, CustomDashboard, Forecast, Pipeline, Product, PublicQuote, Quote, InboxThread, InboxConversation, BookingPageSettings, PublicBookingPage, ConsentEntry, FieldPermissionSettings, Broadcast, BroadcastDetail, BroadcastInput, BroadcastRecipientRow, AttributionResult, ConnectedAccount, ReportCatalog, ReportResult, ReportSpec, SavedReport, TypeReport,
} from '@/types'
import { loggedOut } from '@/features/auth/authSlice'

export const API_URL = import.meta.env.VITE_API_URL ?? '/api/v1'

type RootLike = { auth: { token: string | null } }

const rawBaseQuery = fetchBaseQuery({
  baseUrl: API_URL,
  prepareHeaders: (headers, { getState }) => {
    const token = (getState() as RootLike).auth.token
    if (token) headers.set('Authorization', `Bearer ${token}`)
    headers.set('Accept', 'application/json')
    return headers
  },
})

const baseQuery: BaseQueryFn<string | FetchArgs, unknown, FetchBaseQueryError> = async (args, api, extra) => {
  const result = await rawBaseQuery(args, api, extra)
  if (result.error?.status === 401) api.dispatch(loggedOut())
  return result
}

export type Query = Record<string, string | number | boolean | null | undefined>

const clean = (params?: Query) =>
  Object.fromEntries(Object.entries(params ?? {}).filter(([, v]) => v !== undefined && v !== null && v !== ''))

/** A settings resource with standard list/create/update/delete endpoints. */
type SettingsTag =
  | 'LeadStatus' | 'LeadSource' | 'PipelineStage' | 'Tag' | 'CustomField' | 'Team' | 'AssignmentRule'
  | 'ScoringRule' | 'AutomationRule' | 'Webhook' | 'Campaign' | 'User' | 'EmailTemplate' | 'Sequence' | 'WebForm' | 'AiAgent' | 'Pipeline' | 'Product'

export interface DashboardData {
  kpis: {
    total_leads: number
    new_this_month: number
    new_growth: number | null
    open: number
    qualified: number
    converted: number
    lost: number
    conversion_rate: number
    pipeline_value: number
    weighted_pipeline: number
    won_this_month: number
    avg_lead_age_days: number
    unassigned: number | null
  }
  my: { open_leads: number; follow_ups_today: number; overdue_follow_ups: number; overdue_tasks: number; tasks_today: number }
  by_status: (Pick<LeadStatus, 'id' | 'name' | 'color' | 'category'> & { count: number })[]
  by_source: (Pick<LeadSource, 'id' | 'name' | 'color'> & { count: number })[]
  by_rating: { rating: string; count: number }[]
  trend: { date: string; created: number; converted: number }[]
  heatmap: { date: string; count: number }[]
  upcoming_tasks: (Pick<Task, 'id' | 'title' | 'type' | 'priority' | 'due_at' | 'is_overdue' | 'taskable'>)[]
  hot_leads: Lead[]
}

export interface ReportData {
  range: { from: string; to: string }
  funnel: { stage: string; count: number }[]
  sources: { id: number; name: string; color: string; leads: number; qualified: number; converted: number; conversion_rate: number; value: number }[]
  reps: { id: number; name: string; avatar_color: string | null; leads: number; converted: number; conversion_rate: number; activities: number; won_value: number }[]
  campaigns: { id: number; name: string; status: string; leads: number; converted: number; conversion_rate: number; cost: number; cost_per_lead: number | null }[]
  aging: { bucket: string; count: number }[]
}

export interface BoardColumn {
  status: LeadStatus
  total: number
  value: number
  leads: Lead[]
}

export interface DealColumn {
  stage: PipelineStage
  total: number
  value: number
  weighted: number
  deals: Deal[]
}

export type SubjectType = 'leads' | 'deals' | 'contacts' | 'accounts'

export const api = createApi({
  reducerPath: 'api',
  baseQuery,
  tagTypes: [
    'Me', 'Meta', 'Lead', 'Leads', 'Dashboard', 'Reports', 'Activity', 'Note', 'Task', 'Deal', 'Contact',
    'Account', 'Notification', 'Organization', 'LeadStatus', 'LeadSource', 'PipelineStage', 'Tag',
    'CustomField', 'Team', 'AssignmentRule', 'ScoringRule', 'AutomationRule', 'Webhook', 'Campaign',
    'User', 'ApiKey', 'Audit', 'SavedView', 'Score', 'EmailTemplate', 'Sequence', 'WebForm', 'Enrollment', 'Insights', 'Trash', 'Queue', 'Layout', 'Integration', 'AiAgent', 'Call', 'NotificationPrefs', 'SavedReport', 'Goal', 'CustomDashboard', 'Quote', 'Pipeline', 'Product', 'Inbox', 'BookingPage', 'CalendarFeed', 'TwoFactor', 'FieldPermissions', 'Broadcast', 'Attribution', 'ConnectedAccount', 'Push',
  ],
  endpoints: (b) => ({
    // ---------------------------------------------------------------- auth
    login: b.mutation<{ token: string; user: User } | { two_factor_required: true; challenge: string }, { email: string; password: string }>({
      query: (body) => ({ url: 'auth/login', method: 'POST', body }),
    }),
    twoFactorChallenge: b.mutation<{ token: string; user: User }, { challenge: string; code?: string; recovery_code?: string }>({
      query: (body) => ({ url: 'auth/two-factor-challenge', method: 'POST', body }),
    }),
    ssoStart: b.mutation<{ url: string }, { email: string }>({
      query: (body) => ({ url: 'auth/sso', method: 'POST', body }),
      transformResponse: (r: { data: { url: string } }) => r.data,
    }),
    ssoExchange: b.mutation<{ token: string; user: User }, { code: string }>({
      query: (body) => ({ url: 'auth/sso/exchange', method: 'POST', body }),
    }),
    twoFactorStatus: b.query<{ enabled: boolean; confirmed_at: string | null; recovery_codes_left: number }, void>({
      query: () => 'auth/two-factor',
      transformResponse: (r: { data: never }) => r.data,
      providesTags: ['TwoFactor'],
    }),
    twoFactorSetup: b.mutation<{ secret: string; uri: string }, void>({
      query: () => ({ url: 'auth/two-factor', method: 'POST' }),
      transformResponse: (r: { data: never }) => r.data,
    }),
    twoFactorConfirm: b.mutation<{ recovery_codes: string[] }, { code: string }>({
      query: (body) => ({ url: 'auth/two-factor/confirm', method: 'POST', body }),
      transformResponse: (r: { data: never }) => r.data,
      invalidatesTags: ['TwoFactor', 'Me'],
    }),
    twoFactorRegenerate: b.mutation<{ recovery_codes: string[] }, { password: string }>({
      query: (body) => ({ url: 'auth/two-factor/recovery-codes', method: 'POST', body }),
      transformResponse: (r: { data: never }) => r.data,
      invalidatesTags: ['TwoFactor'],
    }),
    twoFactorDisable: b.mutation<{ message: string }, { password: string }>({
      query: (body) => ({ url: 'auth/two-factor', method: 'DELETE', body }),
      invalidatesTags: ['TwoFactor', 'Me'],
    }),
    setConsent: b.mutation<{ consent: Record<string, ConsentEntry> }, { id: number; channel: string; status: string }>({
      query: ({ id, ...body }) => ({ url: `leads/${id}/consent`, method: 'PUT', body }),
      transformResponse: (r: { data: never }) => r.data,
      invalidatesTags: ['Lead', 'Activity', 'Inbox'],
    }),
    eraseLead: b.mutation<{ message: string }, { id: number; confirm: string }>({
      query: ({ id, ...body }) => ({ url: `leads/${id}/erase`, method: 'POST', body }),
      invalidatesTags: ['Lead', 'Leads', 'Activity', 'Note', 'Call'],
    }),
    unsubscribe: b.mutation<{ organization: string; email: string | null }, { lead: string; signature: string }>({
      query: ({ lead, signature }) => ({ url: `public/unsubscribe/${lead}/${signature}`, method: 'POST' }),
      transformResponse: (r: { data: never }) => r.data,
    }),
    fieldPermissions: b.query<FieldPermissionSettings, void>({
      query: () => 'settings/field-permissions',
      transformResponse: (r: { data: FieldPermissionSettings }) => r.data,
      providesTags: ['FieldPermissions'],
    }),
    saveFieldPermissions: b.mutation<FieldPermissionSettings, { rules: FieldPermissionSettings['rules'] }>({
      query: (body) => ({ url: 'settings/field-permissions', method: 'PUT', body }),
      transformResponse: (r: { data: FieldPermissionSettings }) => r.data,
      invalidatesTags: ['FieldPermissions', 'Meta', 'Lead', 'Leads', 'Deal', 'Reports'],
    }),
    register: b.mutation<{ token: string; user: User }, Record<string, string>>({
      query: (body) => ({ url: 'auth/register', method: 'POST', body }),
    }),
    logout: b.mutation<void, void>({ query: () => ({ url: 'auth/logout', method: 'POST' }) }),
    me: b.query<User, void>({ query: () => 'auth/me', providesTags: ['Me'] }),
    updateProfile: b.mutation<User, Partial<User>>({
      query: (body) => ({ url: 'auth/profile', method: 'PATCH', body }),
      invalidatesTags: ['Me'],
    }),
    updatePassword: b.mutation<void, { current_password: string; password: string; password_confirmation: string }>({
      query: (body) => ({ url: 'auth/password', method: 'PUT', body }),
    }),

    // ------------------------------------------------------------ common
    meta: b.query<Meta, void>({
      query: () => 'meta',
      transformResponse: (r: { data: Meta }) => r.data,
      providesTags: ['Meta'],
    }),
    search: b.query<SearchResult[], string>({
      query: (q) => ({ url: 'search', params: { q } }),
      transformResponse: (r: { data: SearchResult[] }) => r.data,
    }),
    dashboard: b.query<DashboardData, void>({
      query: () => 'dashboard',
      transformResponse: (r: { data: DashboardData }) => r.data,
      providesTags: ['Dashboard'],
    }),
    reports: b.query<ReportData, { from?: string; to?: string }>({
      query: (params) => ({ url: 'reports/leads', params: clean(params) }),
      transformResponse: (r: { data: ReportData }) => r.data,
      providesTags: ['Reports'],
    }),
    reportCatalog: b.query<ReportCatalog, void>({
      query: () => 'reports/catalog',
      transformResponse: (r: { data: ReportCatalog }) => r.data,
      keepUnusedDataFor: 3600,
    }),
    // Reports are computed from everything else, so any change that refreshes the dashboard refreshes them too.
    runReport: b.query<ReportResult, ReportSpec>({
      query: (spec) => ({ url: 'reports/run', method: 'POST', body: { spec } }),
      transformResponse: (r: { data: ReportResult }) => r.data,
      providesTags: ['Reports', 'Dashboard'],
    }),
    typeReport: b.query<TypeReport, { type: string; range?: string; from?: string; to?: string }>({
      query: ({ type, ...params }) => ({ url: `reports/type/${type}`, params: clean(params) }),
      transformResponse: (r: { data: TypeReport }) => r.data,
      providesTags: ['Reports', 'Dashboard'],
    }),
    savedReports: b.query<SavedReport[], { pinned?: boolean } | void>({
      query: (p) => ({ url: 'saved-reports', params: p?.pinned ? { pinned: 1 } : undefined }),
      transformResponse: (r: { data: SavedReport[] }) => r.data,
      providesTags: ['SavedReport'],
    }),
    runSavedReport: b.query<ReportResult, { id: number; range?: string }>({
      query: ({ id, range }) => ({ url: `saved-reports/${id}/run`, params: clean({ range }) }),
      transformResponse: (r: { data: ReportResult }) => r.data,
      providesTags: (_r, _e, { id }) => [{ type: 'SavedReport', id }, 'Reports', 'Dashboard'],
    }),
    saveReport: b.mutation<SavedReport, Partial<SavedReport> & { id?: number }>({
      query: ({ id, ...body }) => ({ url: id ? `saved-reports/${id}` : 'saved-reports', method: id ? 'PATCH' : 'POST', body }),
      transformResponse: (r: { data: SavedReport }) => r.data,
      invalidatesTags: ['SavedReport'],
    }),
    deleteReport: b.mutation<void, number>({
      query: (id) => ({ url: `saved-reports/${id}`, method: 'DELETE' }),
      invalidatesTags: ['SavedReport'],
    }),
    sendReport: b.mutation<{ message: string }, number>({
      query: (id) => ({ url: `saved-reports/${id}/send`, method: 'POST' }),
      invalidatesTags: ['SavedReport'],
    }),
    askReport: b.mutation<AskAnswer, string>({
      query: (question) => ({ url: 'reports/ask', method: 'POST', body: { question } }),
      transformResponse: (r: { data: AskAnswer }) => r.data,
    }),
    dashboards: b.query<CustomDashboard[], void>({
      query: () => 'dashboards',
      transformResponse: (r: { data: CustomDashboard[] }) => r.data,
      providesTags: ['CustomDashboard'],
    }),
    saveDashboard: b.mutation<CustomDashboard, Partial<CustomDashboard> & { id?: number }>({
      query: ({ id, ...body }) => ({ url: id ? `dashboards/${id}` : 'dashboards', method: id ? 'PATCH' : 'POST', body }),
      transformResponse: (r: { data: CustomDashboard }) => r.data,
      invalidatesTags: ['CustomDashboard'],
    }),
    deleteDashboard: b.mutation<void, number>({
      query: (id) => ({ url: `dashboards/${id}`, method: 'DELETE' }),
      invalidatesTags: ['CustomDashboard'],
    }),
    goals: b.query<Goal[], { mine?: boolean } | void>({
      query: (p) => ({ url: 'goals', params: p?.mine ? { mine: 1 } : undefined }),
      transformResponse: (r: { data: Goal[] }) => r.data,
      providesTags: ['Goal', 'Dashboard'],
    }),
    saveGoal: b.mutation<Goal, { id?: number; user_id?: number | null; metric?: string; period?: string; target?: number }>({
      query: ({ id, ...body }) => ({ url: id ? `goals/${id}` : 'goals', method: id ? 'PATCH' : 'POST', body }),
      transformResponse: (r: { data: Goal }) => r.data,
      invalidatesTags: ['Goal'],
    }),
    deleteGoal: b.mutation<void, number>({
      query: (id) => ({ url: `goals/${id}`, method: 'DELETE' }),
      invalidatesTags: ['Goal'],
    }),
    activityFeed: b.query<Activity[], number | void>({
      query: (limit) => ({ url: 'activities/feed', params: { limit: limit ?? 15 } }),
      transformResponse: (r: { data: Activity[] }) => r.data,
      providesTags: ['Activity'],
    }),
    notifications: b.query<{ data: AppNotification[]; unread: number }, number | void>({
      query: (limit) => ({ url: 'notifications', params: { limit: limit ?? 30 } }),
      providesTags: ['Notification'],
    }),
    readNotification: b.mutation<void, string>({
      query: (id) => ({ url: `notifications/${id}/read`, method: 'POST' }),
      invalidatesTags: ['Notification'],
    }),
    readAllNotifications: b.mutation<void, void>({
      query: () => ({ url: 'notifications/read-all', method: 'POST' }),
      invalidatesTags: ['Notification'],
    }),
    auditLogs: b.query<Paginated<AuditLog>, Query>({
      query: (params) => ({ url: 'audit-logs', params: clean(params) }),
      providesTags: ['Audit'],
    }),
    savedViews: b.query<SavedView[], string>({
      query: (entity) => ({ url: 'saved-views', params: { entity } }),
      transformResponse: (r: { data: SavedView[] }) => r.data,
      providesTags: ['SavedView'],
    }),
    createSavedView: b.mutation<SavedView, Partial<SavedView>>({
      query: (body) => ({ url: 'saved-views', method: 'POST', body }),
      invalidatesTags: ['SavedView'],
    }),
    deleteSavedView: b.mutation<void, number>({
      query: (id) => ({ url: `saved-views/${id}`, method: 'DELETE' }),
      invalidatesTags: ['SavedView'],
    }),

    // ------------------------------------------------------------- leads
    leads: b.query<Paginated<Lead>, Query>({
      query: (params) => ({ url: 'leads', params: clean(params) }),
      providesTags: ['Leads'],
    }),
    leadBoard: b.query<BoardColumn[], Query>({
      query: (params) => ({ url: 'leads/board', params: clean(params) }),
      transformResponse: (r: { data: BoardColumn[] }) => r.data,
      providesTags: ['Leads'],
    }),
    lead: b.query<Lead, number>({
      query: (id) => `leads/${id}`,
      transformResponse: (r: { data: Lead }) => r.data,
      providesTags: (_r, _e, id) => [{ type: 'Lead', id }],
    }),
    createLead: b.mutation<Lead, Record<string, unknown>>({
      query: (body) => ({ url: 'leads', method: 'POST', body }),
      transformResponse: (r: { data: Lead }) => r.data,
      invalidatesTags: ['Leads', 'Dashboard', 'Notification', 'Task'],
    }),
    updateLead: b.mutation<Lead, { id: number } & Record<string, unknown>>({
      query: ({ id, ...body }) => ({ url: `leads/${id}`, method: 'PATCH', body }),
      transformResponse: (r: { data: Lead }) => r.data,
      invalidatesTags: (_r, _e, { id }) => [{ type: 'Lead', id }, 'Leads', 'Dashboard', 'Activity', 'Score', 'Task', 'Insights'],
    }),
    deleteLead: b.mutation<void, number>({
      query: (id) => ({ url: `leads/${id}`, method: 'DELETE' }),
      invalidatesTags: ['Leads', 'Dashboard'],
    }),
    changeLeadStatus: b.mutation<Lead, { id: number; lead_status_id: number; note?: string; lost_reason?: string }>({
      query: ({ id, ...body }) => ({ url: `leads/${id}/status`, method: 'POST', body }),
      transformResponse: (r: { data: Lead }) => r.data,
      invalidatesTags: (_r, _e, { id }) => [{ type: 'Lead', id }, 'Leads', 'Dashboard', 'Activity', 'Task', 'Score', 'Notification', 'Insights'],
    }),
    assignLead: b.mutation<Lead, { id: number; owner_id?: number | null; auto?: boolean; reason?: string }>({
      query: ({ id, ...body }) => ({ url: `leads/${id}/assign`, method: 'POST', body }),
      transformResponse: (r: { data: Lead }) => r.data,
      invalidatesTags: (_r, _e, { id }) => [{ type: 'Lead', id }, 'Leads', 'Activity', 'Insights'],
    }),
    convertLead: b.mutation<Lead, { id: number } & Record<string, unknown>>({
      query: ({ id, ...body }) => ({ url: `leads/${id}/convert`, method: 'POST', body }),
      transformResponse: (r: { data: Lead }) => r.data,
      invalidatesTags: (_r, _e, { id }) => [{ type: 'Lead', id }, 'Leads', 'Dashboard', 'Activity', 'Deal', 'Contact', 'Account', 'Insights'],
    }),
    leadScore: b.query<{ score: number; rating: string; events: { id: number; points: number; reason: string; scoring_rule_id: number | null; created_at: string }[] }, number>({
      query: (id) => `leads/${id}/score`,
      transformResponse: (r: { data: never }) => r.data,
      providesTags: (_r, _e, id) => [{ type: 'Score', id }, 'Score'],
    }),
    adjustScore: b.mutation<void, { id: number; points: number; reason: string }>({
      query: ({ id, ...body }) => ({ url: `leads/${id}/score`, method: 'POST', body }),
      invalidatesTags: (_r, _e, { id }) => [{ type: 'Lead', id }, 'Score', 'Leads', 'Insights'],
    }),
    leadHistory: b.query<{ id: number; note: string | null; created_at: string; from_status: LeadStatus | null; to_status: LeadStatus | null; user: { name: string } | null }[], number>({
      query: (id) => `leads/${id}/history`,
      transformResponse: (r: { data: never }) => r.data,
      providesTags: ['Activity'],
    }),
    bulkLeads: b.mutation<{ count: number; skipped?: number; message: string }, Record<string, unknown>>({
      query: (body) => ({ url: 'leads/bulk', method: 'POST', body }),
      invalidatesTags: ['Leads', 'Lead', 'Dashboard'],
    }),
    importLeads: b.mutation<{ created: number; skipped: number; failed: number; errors: { line: number; message: string }[] }, FormData>({
      query: (body) => ({ url: 'leads/import', method: 'POST', body }),
      transformResponse: (r: { data: never }) => r.data,
      invalidatesTags: ['Leads', 'Dashboard', 'Tag', 'Meta'],
    }),
    duplicates: b.query<Lead[], { email?: string; phone?: string; exclude_id?: number }>({
      query: (params) => ({ url: 'leads/duplicates', params: clean(params) }),
      transformResponse: (r: { data: Lead[] }) => r.data,
    }),

    // --------------------------------------------- lead workspace (parity)
    leadQueue: b.query<Lead[], void>({
      query: () => 'leads/queue',
      transformResponse: (r: { data: Lead[] }) => r.data,
      providesTags: ['Queue'],
    }),
    claimLead: b.mutation<Lead, number>({
      query: (id) => ({ url: `leads/${id}/claim`, method: 'POST' }),
      invalidatesTags: (_r, _e, id) => [{ type: 'Lead', id }, 'Queue', 'Leads', 'Dashboard', 'Insights'],
    }),
    mergeLead: b.mutation<Lead, { id: number; duplicate_id: number }>({
      query: ({ id, ...body }) => ({ url: `leads/${id}/merge`, method: 'POST', body }),
      invalidatesTags: ['Lead', 'Leads', 'Activity', 'Note', 'Task', 'Trash'],
    }),
    trash: b.query<Paginated<Lead & { deleted_at: string }>, number | void>({
      query: (page) => ({ url: 'leads/trash', params: { page: page ?? 1 } }),
      providesTags: ['Trash'],
    }),
    restoreLead: b.mutation<void, number>({
      query: (id) => ({ url: `leads/trash/${id}/restore`, method: 'POST' }),
      invalidatesTags: ['Trash', 'Leads', 'Dashboard'],
    }),
    updateQualification: b.mutation<{ progress: Insights['qualification']; score: number }, { id: number; answers: Record<string, boolean> }>({
      query: ({ id, answers }) => ({ url: `leads/${id}/qualification`, method: 'PUT', body: { answers } }),
      transformResponse: (r: { data: never }) => r.data,
      invalidatesTags: (_r, _e, { id }) => [{ type: 'Lead', id }, 'Insights', 'Score', 'Activity', 'Leads'],
    }),
    insights: b.query<Insights, number>({
      query: (id) => `leads/${id}/insights`,
      transformResponse: (r: { data: Insights }) => r.data,
      providesTags: ['Insights'],
    }),
    sendEmail: b.mutation<{ message: string }, { id: number; subject: string; body: string; email_template_id?: number | null }>({
      query: ({ id, ...body }) => ({ url: `leads/${id}/email`, method: 'POST', body }),
      invalidatesTags: ['Activity', 'Insights', 'Lead', 'EmailTemplate', 'Inbox'],
    }),
    previewEmail: b.mutation<{ subject: string; body: string }, { id: number; subject: string; body: string }>({
      query: ({ id, ...body }) => ({ url: `leads/${id}/email/preview`, method: 'POST', body }),
      transformResponse: (r: { data: never }) => r.data,
    }),
    enrollments: b.query<Enrollment[], number>({
      query: (id) => `leads/${id}/enrollments`,
      transformResponse: (r: { data: Enrollment[] }) => r.data,
      providesTags: ['Enrollment'],
    }),
    enroll: b.mutation<Enrollment, { id: number; sequence_id: number }>({
      query: ({ id, ...body }) => ({ url: `leads/${id}/enrollments`, method: 'POST', body }),
      invalidatesTags: ['Enrollment', 'Task', 'Activity', 'Lead', 'Insights', 'Sequence'],
    }),
    stopEnrollment: b.mutation<void, number>({
      query: (id) => ({ url: `enrollments/${id}`, method: 'DELETE' }),
      invalidatesTags: ['Enrollment', 'Task', 'Activity', 'Sequence'],
    }),
    aiBrief: b.mutation<AiBrief, { id: number; refresh?: boolean }>({
      query: ({ id, refresh }) => ({ url: `leads/${id}/ai-brief`, method: 'POST', params: refresh ? { refresh: 1 } : undefined }),
      transformResponse: (r: { data: AiBrief }) => r.data,
    }),
    sendMessage: b.mutation<{ message: string }, { id: number; channel: 'sms' | 'whatsapp'; body: string }>({
      query: ({ id, ...body }) => ({ url: `leads/${id}/message`, method: 'POST', body }),
      invalidatesTags: ['Activity', 'Lead', 'Insights', 'Inbox'],
    }),
    publicForm: b.query<Pick<WebForm, 'name' | 'slug' | 'title' | 'description' | 'submit_label' | 'success_message' | 'redirect_url' | 'accent_color'> & { fields: WebFormField[]; organization: string }, string>({
      query: (slug) => `forms/${slug}`,
      transformResponse: (r: { data: never }) => r.data,
    }),
    submitPublicForm: b.mutation<{ message: string; redirect_url: string | null }, { slug: string; body: Record<string, string> }>({
      query: ({ slug, body }) => ({ url: `forms/${slug}`, method: 'POST', body }),
    }),

    // ------------------------------------------------ timeline & notes
    activities: b.query<Paginated<Activity>, { type: SubjectType; id: number; filter?: string; per_page?: number }>({
      query: ({ type, id, filter, per_page }) => ({ url: `${type}/${id}/activities`, params: clean({ type: filter, per_page }) }),
      providesTags: ['Activity'],
    }),
    logActivity: b.mutation<Activity, { subject: SubjectType; id: number } & Record<string, unknown>>({
      query: ({ subject, id, ...body }) => ({ url: `${subject}/${id}/activities`, method: 'POST', body }),
      invalidatesTags: ['Activity', 'Lead', 'Dashboard', 'Insights'],
    }),
    deleteActivity: b.mutation<void, number>({
      query: (id) => ({ url: `activities/${id}`, method: 'DELETE' }),
      invalidatesTags: ['Activity', 'Insights'],
    }),
    notes: b.query<Note[], { type: SubjectType; id: number }>({
      query: ({ type, id }) => `${type}/${id}/notes`,
      transformResponse: (r: { data: Note[] }) => r.data,
      providesTags: ['Note'],
    }),
    addNote: b.mutation<Note, { type: SubjectType; id: number; body: string; is_pinned?: boolean }>({
      query: ({ type, id, ...body }) => ({ url: `${type}/${id}/notes`, method: 'POST', body }),
      invalidatesTags: ['Note', 'Activity', 'Lead', 'Insights'],
    }),
    updateNote: b.mutation<Note, { id: number; body?: string; is_pinned?: boolean }>({
      query: ({ id, ...body }) => ({ url: `notes/${id}`, method: 'PATCH', body }),
      invalidatesTags: ['Note'],
    }),
    deleteNote: b.mutation<void, number>({
      query: (id) => ({ url: `notes/${id}`, method: 'DELETE' }),
      invalidatesTags: ['Note', 'Lead'],
    }),

    // ------------------------------------------------------------- tasks
    tasks: b.query<Paginated<Task>, Query>({
      query: (params) => ({ url: 'tasks', params: clean(params) }),
      providesTags: ['Task'],
    }),
    taskSummary: b.query<{ overdue: number; today: number; upcoming: number; open: number; completed_this_week: number }, Query | void>({
      query: (params) => ({ url: 'tasks/summary', params: clean(params ?? {}) }),
      transformResponse: (r: { data: never }) => r.data,
      providesTags: ['Task'],
    }),
    createTask: b.mutation<Task, Record<string, unknown>>({
      query: (body) => ({ url: 'tasks', method: 'POST', body }),
      invalidatesTags: ['Task', 'Dashboard', 'Activity', 'Lead', 'Insights'],
    }),
    updateTask: b.mutation<Task, { id: number } & Record<string, unknown>>({
      query: ({ id, ...body }) => ({ url: `tasks/${id}`, method: 'PATCH', body }),
      invalidatesTags: ['Task', 'Dashboard'],
    }),
    toggleTask: b.mutation<Task, number>({
      query: (id) => ({ url: `tasks/${id}/toggle`, method: 'POST' }),
      invalidatesTags: ['Task', 'Dashboard', 'Activity', 'Lead', 'Insights'],
    }),
    deleteTask: b.mutation<void, number>({
      query: (id) => ({ url: `tasks/${id}`, method: 'DELETE' }),
      invalidatesTags: ['Task', 'Dashboard', 'Lead', 'Insights'],
    }),

    // --------------------------------------------------------------- CRM
    deals: b.query<Paginated<Deal>, Query>({ query: (params) => ({ url: 'deals', params: clean(params) }), providesTags: ['Deal'] }),
    dealBoard: b.query<DealColumn[], Query>({
      query: (params) => ({ url: 'deals/board', params: clean(params) }),
      transformResponse: (r: { data: DealColumn[] }) => r.data,
      providesTags: ['Deal'],
    }),
    inbox: b.query<{ data: InboxThread[]; unread_threads: number }, { filter?: string; channel?: string; search?: string }>({
      query: (params) => ({ url: 'inbox', params: clean(params) }),
      providesTags: [{ type: 'Inbox', id: 'LIST' }],
    }),
    inboxSummary: b.query<{ unread_threads: number }, void>({
      query: () => 'inbox/summary',
      transformResponse: (r: { data: { unread_threads: number } }) => r.data,
      providesTags: [{ type: 'Inbox', id: 'LIST' }],
    }),
    conversation: b.query<InboxConversation, number>({
      query: (leadId) => `inbox/${leadId}`,
      transformResponse: (r: { data: InboxConversation }) => r.data,
      providesTags: (_r, _e, id) => [{ type: 'Inbox', id }],
      // Opening a thread marks it read, so refresh the list counts afterwards.
      async onQueryStarted(_id, { dispatch, queryFulfilled }) {
        await queryFulfilled.catch(() => undefined)
        dispatch(api.util.invalidateTags([{ type: 'Inbox', id: 'LIST' }]))
      },
    }),
    pushConfig: b.query<{ vapid_public_key: string; fcm: boolean; devices: { id: number; kind: 'webpush' | 'fcm'; device: string | null; last_used_at: string | null; created_at: string }[] }, void>({
      query: () => 'push',
      transformResponse: (r: { data: never }) => r.data,
      providesTags: ['Push'],
    }),
    subscribePush: b.mutation<void, { endpoint?: string; keys?: { p256dh?: string; auth?: string }; fcm_token?: string; device?: string }>({
      query: (body) => ({ url: 'push/subscriptions', method: 'POST', body }),
      invalidatesTags: ['Push'],
    }),
    unsubscribePush: b.mutation<void, { endpoint?: string; fcm_token?: string; id?: number }>({
      query: (body) => ({ url: 'push/subscriptions', method: 'DELETE', body }),
      invalidatesTags: ['Push'],
    }),
    connectedAccounts: b.query<{ accounts: ConnectedAccount[]; providers: { key: 'google' | 'microsoft'; label: string; available: boolean }[] }, void>({
      query: () => 'connected-accounts',
      transformResponse: (r: { data: never }) => r.data,
      providesTags: ['ConnectedAccount'],
    }),
    connectAccount: b.mutation<{ url: string }, string>({
      query: (provider) => ({ url: `connected-accounts/${provider}/connect`, method: 'POST' }),
      transformResponse: (r: { data: { url: string } }) => r.data,
    }),
    updateConnectedAccount: b.mutation<ConnectedAccount, { id: number; sync_mail?: boolean; sync_calendar?: boolean }>({
      query: ({ id, ...body }) => ({ url: `connected-accounts/${id}`, method: 'PATCH', body }),
      invalidatesTags: ['ConnectedAccount'],
    }),
    syncAccount: b.mutation<{ data: ConnectedAccount; message: string }, number>({
      query: (id) => ({ url: `connected-accounts/${id}/sync`, method: 'POST' }),
      invalidatesTags: ['ConnectedAccount', 'Inbox', 'Activity'],
    }),
    disconnectAccount: b.mutation<void, number>({
      query: (id) => ({ url: `connected-accounts/${id}`, method: 'DELETE' }),
      invalidatesTags: ['ConnectedAccount'],
    }),
    bookingPage: b.query<BookingPageSettings | null, void>({
      query: () => 'booking-page',
      transformResponse: (r: { data: BookingPageSettings | null }) => r.data,
      providesTags: ['BookingPage'],
    }),
    saveBookingPage: b.mutation<BookingPageSettings, Omit<BookingPageSettings, 'id' | 'url'>>({
      query: (body) => ({ url: 'booking-page', method: 'PUT', body }),
      transformResponse: (r: { data: BookingPageSettings }) => r.data,
      invalidatesTags: ['BookingPage'],
    }),
    suggestBookingSlug: b.query<{ slug: string }, void>({
      query: () => 'booking-page/suggest',
      transformResponse: (r: { data: { slug: string } }) => r.data,
    }),
    publicBooking: b.query<PublicBookingPage, string>({
      query: (slug) => `public/book/${slug}`,
      transformResponse: (r: { data: PublicBookingPage }) => r.data,
      providesTags: ['BookingPage'],
    }),
    bookMeeting: b.mutation<{ start: string; local: string; timezone: string; host: string; title: string }, { slug: string; name: string; email: string; phone?: string; company?: string; notes?: string; start: string }>({
      query: ({ slug, ...body }) => ({ url: `public/book/${slug}`, method: 'POST', body }),
      transformResponse: (r: { data: never }) => r.data,
      invalidatesTags: ['BookingPage'],
    }),
    calendarFeed: b.query<{ url: string }, void>({
      query: () => 'auth/calendar-feed',
      transformResponse: (r: { data: { url: string } }) => r.data,
      providesTags: ['CalendarFeed'],
    }),
    resetCalendarFeed: b.mutation<{ url: string }, void>({
      query: () => ({ url: 'auth/calendar-feed', method: 'POST' }),
      transformResponse: (r: { data: { url: string } }) => r.data,
      invalidatesTags: ['CalendarFeed'],
    }),
    logCallNotes: b.mutation<Call, { leadId: number; notes?: string; audio?: File; duration_minutes?: number }>({
      query: ({ leadId, notes, audio, duration_minutes }) => {
        if (audio) {
          const form = new FormData()
          form.append('audio', audio)
          if (duration_minutes) form.append('duration_minutes', String(duration_minutes))
          return { url: `leads/${leadId}/call-notes`, method: 'POST', body: form }
        }
        return { url: `leads/${leadId}/call-notes`, method: 'POST', body: { notes, duration_minutes } }
      },
      transformResponse: (r: { data: Call }) => r.data,
      invalidatesTags: ['Call', 'Activity', 'Lead', 'Insights', 'Task', 'Score'],
    }),
    forecast: b.query<Forecast, { period?: string; pipeline_id?: number | string }>({
      query: (params) => ({ url: 'deals/forecast', params: clean(params) }),
      transformResponse: (r: { data: Forecast }) => r.data,
      providesTags: ['Deal', 'Goal'],
    }),
    quotes: b.query<Quote[], number>({
      query: (dealId) => `deals/${dealId}/quotes`,
      transformResponse: (r: { data: Quote[] }) => r.data,
      providesTags: ['Quote'],
    }),
    quote: b.query<Quote, number>({
      query: (id) => `quotes/${id}`,
      transformResponse: (r: { data: Quote }) => r.data,
      providesTags: ['Quote'],
    }),
    saveQuote: b.mutation<Quote, { id?: number; dealId: number; body: Record<string, unknown> }>({
      query: ({ id, dealId, body }) => ({ url: id ? `quotes/${id}` : `deals/${dealId}/quotes`, method: id ? 'PUT' : 'POST', body }),
      transformResponse: (r: { data: Quote }) => r.data,
      invalidatesTags: ['Quote', 'Activity'],
    }),
    sendQuote: b.mutation<{ message: string }, { id: number; to?: string }>({
      query: ({ id, ...body }) => ({ url: `quotes/${id}/send`, method: 'POST', body }),
      invalidatesTags: ['Quote', 'Activity'],
    }),
    duplicateQuote: b.mutation<Quote, number>({
      query: (id) => ({ url: `quotes/${id}/duplicate`, method: 'POST' }),
      invalidatesTags: ['Quote'],
    }),
    deleteQuote: b.mutation<void, number>({
      query: (id) => ({ url: `quotes/${id}`, method: 'DELETE' }),
      invalidatesTags: ['Quote'],
    }),
    publicQuote: b.query<PublicQuote, string>({
      query: (token) => `public/quotes/${token}`,
      transformResponse: (r: { data: PublicQuote }) => r.data,
    }),
    respondQuote: b.mutation<PublicQuote, { token: string; accept: boolean; name?: string; agree?: boolean; reason?: string }>({
      query: ({ token, ...body }) => ({ url: `public/quotes/${token}/respond`, method: 'POST', body }),
      transformResponse: (r: { data: PublicQuote }) => r.data,
    }),
    deal: b.query<Deal, number>({ query: (id) => `deals/${id}`, transformResponse: (r: { data: Deal }) => r.data, providesTags: ['Deal'] }),
    saveDeal: b.mutation<Deal, Partial<Deal>>({
      query: ({ id, ...body }) => ({ url: id ? `deals/${id}` : 'deals', method: id ? 'PATCH' : 'POST', body }),
      invalidatesTags: ['Deal', 'Dashboard', 'Account', 'Contact'],
    }),
    moveDeal: b.mutation<Deal, { id: number; pipeline_stage_id: number; lost_reason?: string }>({
      query: ({ id, ...body }) => ({ url: `deals/${id}/move`, method: 'POST', body }),
      invalidatesTags: ['Deal', 'Dashboard', 'Activity'],
    }),
    deleteDeal: b.mutation<void, number>({ query: (id) => ({ url: `deals/${id}`, method: 'DELETE' }), invalidatesTags: ['Deal', 'Dashboard'] }),

    contacts: b.query<Paginated<Contact>, Query>({ query: (params) => ({ url: 'contacts', params: clean(params) }), providesTags: ['Contact'] }),
    contact: b.query<Contact, number>({ query: (id) => `contacts/${id}`, transformResponse: (r: { data: Contact }) => r.data, providesTags: ['Contact'] }),
    saveContact: b.mutation<Contact, Partial<Contact>>({
      query: ({ id, ...body }) => ({ url: id ? `contacts/${id}` : 'contacts', method: id ? 'PATCH' : 'POST', body }),
      invalidatesTags: ['Contact', 'Account'],
    }),
    deleteContact: b.mutation<void, number>({ query: (id) => ({ url: `contacts/${id}`, method: 'DELETE' }), invalidatesTags: ['Contact'] }),

    accounts: b.query<Paginated<Account>, Query>({ query: (params) => ({ url: 'accounts', params: clean(params) }), providesTags: ['Account'] }),
    account: b.query<Account, number>({ query: (id) => `accounts/${id}`, transformResponse: (r: { data: Account }) => r.data, providesTags: ['Account'] }),
    saveAccount: b.mutation<Account, Partial<Account>>({
      query: ({ id, ...body }) => ({ url: id ? `accounts/${id}` : 'accounts', method: id ? 'PATCH' : 'POST', body }),
      invalidatesTags: ['Account'],
    }),
    deleteAccount: b.mutation<void, number>({ query: (id) => ({ url: `accounts/${id}`, method: 'DELETE' }), invalidatesTags: ['Account'] }),

    // ---------------------------------------------------------- settings
    organization: b.query<import('@/types').Organization, void>({
      query: () => 'settings/organization',
      transformResponse: (r: { data: never }) => r.data,
      providesTags: ['Organization'],
    }),
    updateOrganization: b.mutation<void, Record<string, unknown>>({
      query: (body) => ({ url: 'settings/organization', method: 'PATCH', body }),
      invalidatesTags: ['Organization', 'Me', 'Reports'],
    }),
    settingsList: b.query<unknown[], { resource: string; tag: SettingsTag }>({
      query: ({ resource }) => `settings/${resource}`,
      transformResponse: (r: { data: unknown[] }) => r.data,
      providesTags: (_r, _e, { tag }) => [tag],
    }),
    saveSetting: b.mutation<{ data: unknown; secret?: string }, { resource: string; tag: SettingsTag; id?: number; body: Record<string, unknown> }>({
      query: ({ resource, id, body }) => ({ url: `settings/${resource}${id ? `/${id}` : ''}`, method: id ? 'PATCH' : 'POST', body }),
      invalidatesTags: (_r, _e, { tag }) => [tag, 'Meta', 'Leads', 'Layout'],
    }),
    deleteSetting: b.mutation<void, { resource: string; tag: SettingsTag; id: number }>({
      query: ({ resource, id }) => ({ url: `settings/${resource}/${id}`, method: 'DELETE' }),
      invalidatesTags: (_r, _e, { tag }) => [tag, 'Meta', 'Layout'],
    }),
    reorderStatuses: b.mutation<void, number[]>({
      query: (ids) => ({ url: 'settings/lead-statuses/reorder', method: 'POST', body: { ids } }),
      invalidatesTags: ['LeadStatus', 'Meta', 'Leads'],
    }),
    recalculateScores: b.mutation<{ message: string }, void>({
      query: () => ({ url: 'settings/scoring-rules/recalculate', method: 'POST' }),
      invalidatesTags: ['Leads', 'Lead', 'Score', 'Dashboard'],
    }),
    layouts: b.query<Record<LayoutEntity, PageLayout & { catalog: string[]; required: string[] }>, void>({
      query: () => 'settings/layouts',
      transformResponse: (r: { data: never }) => r.data,
      providesTags: ['Layout'],
    }),
    saveLayout: b.mutation<PageLayout, { entity: LayoutEntity; sections: PageLayout['sections']; hidden: string[] }>({
      query: ({ entity, ...body }) => ({ url: `settings/layouts/${entity}`, method: 'PUT', body }),
      invalidatesTags: ['Layout', 'Meta'],
    }),
    resetLayout: b.mutation<PageLayout, LayoutEntity>({
      query: (entity) => ({ url: `settings/layouts/${entity}`, method: 'DELETE' }),
      invalidatesTags: ['Layout', 'Meta'],
    }),
    integrations: b.query<{ categories: Record<string, string>; providers: IntegrationProvider[] }, void>({
      query: () => 'settings/integrations',
      transformResponse: (r: { data: never }) => r.data,
      providesTags: ['Integration'],
    }),
    saveIntegration: b.mutation<void, { provider: string; config: Record<string, string>; is_active?: boolean }>({
      query: (body) => ({ url: 'settings/integrations', method: 'POST', body }),
      invalidatesTags: ['Integration', 'Meta', 'AiAgent'],
    }),
    toggleIntegration: b.mutation<void, { id: number; is_active: boolean }>({
      query: ({ id, ...body }) => ({ url: `settings/integrations/${id}`, method: 'PATCH', body }),
      invalidatesTags: ['Integration', 'Meta'],
    }),
    deleteIntegration: b.mutation<void, number>({
      query: (id) => ({ url: `settings/integrations/${id}`, method: 'DELETE' }),
      invalidatesTags: ['Integration', 'Meta', 'AiAgent'],
    }),
    testIntegration: b.mutation<{ message: string }, number>({
      query: (id) => ({ url: `settings/integrations/${id}/test`, method: 'POST' }),
      invalidatesTags: ['Integration'],
    }),
    calls: b.query<Paginated<Call>, Query>({
      query: (params) => ({ url: 'calls', params: clean(params) }),
      providesTags: ['Call'],
    }),
    call: b.query<Call, number>({
      query: (id) => `calls/${id}`,
      transformResponse: (r: { data: Call }) => r.data,
      providesTags: (_r, _e, id) => [{ type: 'Call', id }],
    }),
    callStats: b.query<CallStats, number | void>({
      query: (days) => ({ url: 'calls/stats', params: { days: days ?? 30 } }),
      transformResponse: (r: { data: CallStats }) => r.data,
      providesTags: ['Call'],
    }),
    callLead: b.mutation<Call, { leadId: number; ai_agent_id: number }>({
      query: ({ leadId, ...body }) => ({ url: `leads/${leadId}/calls`, method: 'POST', body }),
      transformResponse: (r: { data: Call }) => r.data,
      invalidatesTags: ['Call'],
    }),
    launchCampaign: b.mutation<{ campaign_key: string; queued: number; skipped: number }, { agentId: number; conditions?: Condition[]; lead_ids?: number[]; limit?: number }>({
      query: ({ agentId, ...body }) => ({ url: `ai-agents/${agentId}/campaign`, method: 'POST', body }),
      transformResponse: (r: { data: never }) => r.data,
      invalidatesTags: ['Call'],
    }),
    simulateInboundCall: b.mutation<Call, { agentId: number; phone?: string }>({
      query: ({ agentId, ...body }) => ({ url: `ai-agents/${agentId}/simulate-inbound`, method: 'POST', body }),
      transformResponse: (r: { data: Call }) => r.data,
      invalidatesTags: ['Call', 'Lead', 'Attribution'],
    }),

    // ------------------------------------------------- email campaigns
    broadcasts: b.query<Broadcast[], void>({
      query: () => 'broadcasts',
      transformResponse: (r: { data: Broadcast[] }) => r.data,
      providesTags: ['Broadcast'],
    }),
    broadcast: b.query<BroadcastDetail, number>({
      query: (id) => `broadcasts/${id}`,
      transformResponse: (r: { data: BroadcastDetail }) => r.data,
      providesTags: (_r, _e, id) => [{ type: 'Broadcast', id }],
    }),
    broadcastRecipients: b.query<Paginated<BroadcastRecipientRow>, { id: number; filter?: string; page?: number }>({
      query: ({ id, ...params }) => ({ url: `broadcasts/${id}/recipients`, params: clean(params) }),
      providesTags: (_r, _e, { id }) => [{ type: 'Broadcast', id }],
    }),
    broadcastAudience: b.mutation<{ count: number; sample: { id: number; name: string; email: string }[] }, { conditions: Condition[] }>({
      query: (body) => ({ url: 'broadcasts/audience', method: 'POST', body }),
      transformResponse: (r: { data: never }) => r.data,
    }),
    saveBroadcast: b.mutation<Broadcast, BroadcastInput & { id?: number }>({
      query: ({ id, ...body }) => ({ url: id ? `broadcasts/${id}` : 'broadcasts', method: id ? 'PUT' : 'POST', body }),
      transformResponse: (r: { data: Broadcast }) => r.data,
      invalidatesTags: ['Broadcast'],
    }),
    deleteBroadcast: b.mutation<void, number>({
      query: (id) => ({ url: `broadcasts/${id}`, method: 'DELETE' }),
      invalidatesTags: ['Broadcast'],
    }),
    launchBroadcast: b.mutation<Broadcast, { id: number; scheduled_at?: string }>({
      query: ({ id, ...body }) => ({ url: `broadcasts/${id}/launch`, method: 'POST', body }),
      transformResponse: (r: { data: Broadcast }) => r.data,
      invalidatesTags: ['Broadcast', 'Activity', 'Inbox'],
    }),
    broadcastAction: b.mutation<Broadcast, { id: number; action: 'cancel' | 'pick-winner' }>({
      query: ({ id, action }) => ({ url: `broadcasts/${id}/${action}`, method: 'POST' }),
      transformResponse: (r: { data: Broadcast }) => r.data,
      invalidatesTags: ['Broadcast'],
    }),
    attribution: b.query<AttributionResult, { by: string; range: string; from?: string; to?: string }>({
      query: (params) => ({ url: 'reports/attribution', params: clean(params) }),
      transformResponse: (r: { data: AttributionResult }) => r.data,
      providesTags: ['Attribution'],
    }),
    cancelCall: b.mutation<void, number>({
      query: (id) => ({ url: `calls/${id}/cancel`, method: 'POST' }),
      invalidatesTags: ['Call'],
    }),
    notificationPrefs: b.query<NotificationPrefs, void>({
      query: () => 'auth/notification-preferences',
      transformResponse: (r: { data: NotificationPrefs }) => r.data,
      providesTags: ['NotificationPrefs'],
    }),
    updateNotificationPrefs: b.mutation<NotificationPrefs, Record<string, unknown>>({
      query: (body) => ({ url: 'auth/notification-preferences', method: 'PUT', body }),
      transformResponse: (r: { data: NotificationPrefs }) => r.data,
      invalidatesTags: ['NotificationPrefs'],
    }),
    testNotification: b.mutation<{ message: string }, void>({
      query: () => ({ url: 'notifications/test', method: 'POST' }),
      invalidatesTags: ['Notification'],
    }),
    automationExecutions: b.query<{ id: number; status: string; message: string | null; created_at: string; lead: { id: number; first_name: string; last_name: string | null } | null }[], number>({
      query: (id) => `settings/automation-rules/${id}/executions`,
      transformResponse: (r: { data: never }) => r.data,
      providesTags: ['AutomationRule'],
    }),
    testWebhook: b.mutation<{ message: string }, number>({
      query: (id) => ({ url: `settings/webhooks/${id}/test`, method: 'POST' }),
      invalidatesTags: ['Webhook'],
    }),
    apiKeys: b.query<ApiKey[], void>({ query: () => 'settings/api-keys', transformResponse: (r: { data: ApiKey[] }) => r.data, providesTags: ['ApiKey'] }),
    createApiKey: b.mutation<{ data: ApiKey; key: string }, { name: string }>({
      query: (body) => ({ url: 'settings/api-keys', method: 'POST', body }),
      invalidatesTags: ['ApiKey'],
    }),
    revokeApiKey: b.mutation<void, number>({ query: (id) => ({ url: `settings/api-keys/${id}`, method: 'DELETE' }), invalidatesTags: ['ApiKey'] }),
  }),
})

export const {
  useLoginMutation, useSsoStartMutation, useSsoExchangeMutation, useRegisterMutation, useLogoutMutation, useMeQuery, useUpdateProfileMutation, useUpdatePasswordMutation,
  useMetaQuery, useSearchQuery, useDashboardQuery, useReportsQuery, useActivityFeedQuery, useNotificationsQuery,
  useReadNotificationMutation, useReadAllNotificationsMutation, useAuditLogsQuery, useSavedViewsQuery,
  useCreateSavedViewMutation, useDeleteSavedViewMutation,
  useLeadsQuery, useLeadBoardQuery, useLeadQuery, useCreateLeadMutation, useUpdateLeadMutation, useDeleteLeadMutation,
  useChangeLeadStatusMutation, useAssignLeadMutation, useConvertLeadMutation, useLeadScoreQuery, useAdjustScoreMutation,
  useLeadHistoryQuery, useBulkLeadsMutation, useImportLeadsMutation, useLazyDuplicatesQuery,
  useActivitiesQuery, useLogActivityMutation, useDeleteActivityMutation, useNotesQuery, useAddNoteMutation,
  useUpdateNoteMutation, useDeleteNoteMutation,
  useTasksQuery, useTaskSummaryQuery, useCreateTaskMutation, useUpdateTaskMutation, useToggleTaskMutation, useDeleteTaskMutation,
  useDealsQuery, useDealBoardQuery, useDealQuery, useSaveDealMutation, useMoveDealMutation, useDeleteDealMutation,
  useContactsQuery, useContactQuery, useSaveContactMutation, useDeleteContactMutation,
  useAccountsQuery, useAccountQuery, useSaveAccountMutation, useDeleteAccountMutation,
  useOrganizationQuery, useUpdateOrganizationMutation, useSettingsListQuery, useSaveSettingMutation, useDeleteSettingMutation,
  useReorderStatusesMutation, useRecalculateScoresMutation, useAutomationExecutionsQuery, useTestWebhookMutation,
  useApiKeysQuery, useCreateApiKeyMutation, useRevokeApiKeyMutation,
  useLeadQueueQuery, useClaimLeadMutation, useMergeLeadMutation, useTrashQuery, useRestoreLeadMutation,
  useUpdateQualificationMutation, useInsightsQuery, useSendEmailMutation, usePreviewEmailMutation,
  useIntegrationsQuery, useSaveIntegrationMutation, useToggleIntegrationMutation, useDeleteIntegrationMutation, useTestIntegrationMutation,
  useCallsQuery, useCallQuery, useCallStatsQuery, useCallLeadMutation, useLaunchCampaignMutation, useCancelCallMutation, useSimulateInboundCallMutation,
  useBroadcastsQuery, useBroadcastQuery, useBroadcastRecipientsQuery, useBroadcastAudienceMutation, useSaveBroadcastMutation,
  useDeleteBroadcastMutation, useLaunchBroadcastMutation, useBroadcastActionMutation, useAttributionQuery,
  usePushConfigQuery, useSubscribePushMutation, useUnsubscribePushMutation, useConnectedAccountsQuery, useConnectAccountMutation, useUpdateConnectedAccountMutation, useSyncAccountMutation, useDisconnectAccountMutation,
  useNotificationPrefsQuery, useUpdateNotificationPrefsMutation, useTestNotificationMutation,
  useReportCatalogQuery, useRunReportQuery, useTypeReportQuery, useSavedReportsQuery, useRunSavedReportQuery,
  useSaveReportMutation, useDeleteReportMutation, useSendReportMutation, useGoalsQuery, useSaveGoalMutation, useDeleteGoalMutation,
  useForecastQuery, useQuotesQuery, useQuoteQuery, useSaveQuoteMutation, useSendQuoteMutation, useDuplicateQuoteMutation, useDeleteQuoteMutation,
  usePublicQuoteQuery, useRespondQuoteMutation,
  useInboxQuery, useInboxSummaryQuery, useConversationQuery, useBookingPageQuery, useSaveBookingPageMutation, useLazySuggestBookingSlugQuery,
  usePublicBookingQuery, useBookMeetingMutation, useCalendarFeedQuery, useResetCalendarFeedMutation, useLogCallNotesMutation,
  useTwoFactorChallengeMutation, useTwoFactorStatusQuery, useTwoFactorSetupMutation, useTwoFactorConfirmMutation, useTwoFactorRegenerateMutation,
  useTwoFactorDisableMutation, useSetConsentMutation, useEraseLeadMutation, useUnsubscribeMutation, useFieldPermissionsQuery, useSaveFieldPermissionsMutation,
  useAskReportMutation, useDashboardsQuery, useSaveDashboardMutation, useDeleteDashboardMutation,
  useLayoutsQuery, useSaveLayoutMutation, useResetLayoutMutation, useAiBriefMutation, useSendMessageMutation, useEnrollmentsQuery, useEnrollMutation, useStopEnrollmentMutation, usePublicFormQuery, useSubmitPublicFormMutation,
} = api

// Typed helpers for settings resources.
export type SettingsResource<T> = { resource: string; tag: SettingsTag; _t?: T }
export const resources = {
  statuses: { resource: 'lead-statuses', tag: 'LeadStatus' } as SettingsResource<LeadStatus>,
  sources: { resource: 'lead-sources', tag: 'LeadSource' } as SettingsResource<LeadSource>,
  stages: { resource: 'pipeline-stages', tag: 'PipelineStage' } as SettingsResource<PipelineStage>,
  tags: { resource: 'tags', tag: 'Tag' } as SettingsResource<Tag>,
  customFields: { resource: 'custom-fields', tag: 'CustomField' } as SettingsResource<CustomField>,
  teams: { resource: 'teams', tag: 'Team' } as SettingsResource<Team>,
  users: { resource: 'users', tag: 'User' } as SettingsResource<User>,
  campaigns: { resource: 'campaigns', tag: 'Campaign' } as SettingsResource<Campaign>,
  assignmentRules: { resource: 'assignment-rules', tag: 'AssignmentRule' } as SettingsResource<AssignmentRule>,
  scoringRules: { resource: 'scoring-rules', tag: 'ScoringRule' } as SettingsResource<ScoringRule>,
  automationRules: { resource: 'automation-rules', tag: 'AutomationRule' } as SettingsResource<AutomationRule>,
  webhooks: { resource: 'webhooks', tag: 'Webhook' } as SettingsResource<Webhook>,
  emailTemplates: { resource: 'email-templates', tag: 'EmailTemplate' } as SettingsResource<EmailTemplate>,
  sequences: { resource: 'sequences', tag: 'Sequence' } as SettingsResource<Sequence>,
  webForms: { resource: 'web-forms', tag: 'WebForm' } as SettingsResource<WebForm>,
  aiAgents: { resource: 'ai-agents', tag: 'AiAgent' } as SettingsResource<AiAgent>,
  pipelines: { resource: 'pipelines', tag: 'Pipeline' } as SettingsResource<Pipeline>,
  products: { resource: 'products', tag: 'Product' } as SettingsResource<Product>,
}

/** Typed wrapper around the generic settings list query. */
export function useSettings<T>(r: SettingsResource<T>, skip = false) {
  const q = useSettingsListQuery({ resource: r.resource, tag: r.tag }, { skip })
  return { ...q, data: q.data as T[] | undefined }
}

/** Extract a human readable message from an RTK Query error. */
export function errorMessage(error: unknown, fallback = 'Something went wrong'): string {
  const e = error as { data?: { message?: string; errors?: Record<string, string[]> } } | undefined
  const first = e?.data?.errors && Object.values(e.data.errors)[0]?.[0]
  return first || e?.data?.message || fallback
}

export function fieldErrors(error: unknown): Record<string, string> {
  const e = error as { data?: { errors?: Record<string, string[]> } } | undefined
  return Object.fromEntries(Object.entries(e?.data?.errors ?? {}).map(([k, v]) => [k, v[0]]))
}
