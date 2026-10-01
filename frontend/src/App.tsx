import { Suspense, useEffect } from 'react'
import { lazyPage } from '@/lib/lazyPage'
import { Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout, RequireRole } from '@/components/layout/AppLayout'
import { PageLoader, Toaster } from '@/components/ui'
import { LoginPage } from '@/pages/auth/LoginPage'
import { RegisterPage } from '@/pages/auth/RegisterPage'

// Pages are code-split so the first load stays small.
const DashboardPage = lazyPage(() => import('@/pages/DashboardPage'), 'DashboardPage')
const LeadsPage = lazyPage(() => import('@/pages/leads/LeadsPage'), 'LeadsPage')
const LeadDetailPage = lazyPage(() => import('@/pages/leads/LeadDetailPage'), 'LeadDetailPage')
const QueuePage = lazyPage(() => import('@/pages/leads/QueuePage'), 'QueuePage')
const TrashPage = lazyPage(() => import('@/pages/leads/TrashPage'), 'TrashPage')
const DealsPage = lazyPage(() => import('@/pages/deals/DealsPage'), 'DealsPage')
const DealDetailPage = lazyPage(() => import('@/pages/deals/DealDetailPage'), 'DealDetailPage')
const ContactDetailPage = lazyPage(() => import('@/pages/contacts/ContactsPage'), 'ContactDetailPage')
const ContactsPage = lazyPage(() => import('@/pages/contacts/ContactsPage'), 'ContactsPage')
const AccountDetailPage = lazyPage(() => import('@/pages/accounts/AccountsPage'), 'AccountDetailPage')
const AccountsPage = lazyPage(() => import('@/pages/accounts/AccountsPage'), 'AccountsPage')
const TasksPage = lazyPage(() => import('@/pages/tasks/TasksPage'), 'TasksPage')
const ReportsPage = lazyPage(() => import('@/pages/ReportsPage'), 'ReportsPage')
const CampaignsPage = lazyPage(() => import('@/pages/CampaignsPage'), 'CampaignsPage')
const CallsPage = lazyPage(() => import('@/pages/CallsPage'), 'CallsPage')
const PlaybooksPage = lazyPage(() => import('@/pages/PlaybooksPage'), 'PlaybooksPage')
const SettingsPage = lazyPage(() => import('@/pages/settings/SettingsPage'), 'SettingsPage')
const AuditLogPage = lazyPage(() => import('@/pages/AuditLogPage'), 'AuditLogPage')
const ProfilePage = lazyPage(() => import('@/pages/ProfilePage'), 'ProfilePage')
const PublicFormPage = lazyPage(() => import('@/pages/PublicFormPage'), 'PublicFormPage')
const NotificationsPage = lazyPage(() => import('@/pages/NotificationsPage'), 'NotificationsPage')
const ForecastPage = lazyPage(() => import('@/pages/ForecastPage'), 'ForecastPage')
const PublicQuotePage = lazyPage(() => import('@/pages/PublicQuotePage'), 'PublicQuotePage')
const NotFoundPage = lazyPage(() => import('@/pages/NotFoundPage'), 'NotFoundPage')

const PAGES = [NotificationsPage, DashboardPage, LeadsPage, LeadDetailPage, QueuePage, TrashPage, DealsPage, DealDetailPage, ContactDetailPage, ContactsPage, AccountDetailPage, AccountsPage, TasksPage, ReportsPage, CampaignsPage, CallsPage, PlaybooksPage, SettingsPage, AuditLogPage, ProfilePage, PublicFormPage, ForecastPage, PublicQuotePage, NotFoundPage]

/** Load every page chunk once the browser is idle so navigation never waits. */
function usePrefetchPages() {
  useEffect(() => {
    const load = () => PAGES.forEach((p) => p.preload())
    const w = window as Window & { requestIdleCallback?: (cb: () => void) => number }
    if (w.requestIdleCallback) w.requestIdleCallback(load)
    else window.setTimeout(load, 1200)
  }, [])
}

export default function App() {
  usePrefetchPages()
  return (
    <>
      <Suspense fallback={<PageLoader />}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/f/:slug" element={<PublicFormPage />} />
        <Route path="/q/:token" element={<PublicQuotePage />} />
        <Route element={<AppLayout />}>
          <Route index element={<DashboardPage />} />
          <Route path="leads" element={<LeadsPage />} />
          <Route path="leads/queue" element={<QueuePage />} />
          <Route path="leads/trash" element={<RequireRole allow="manager"><TrashPage /></RequireRole>} />
          <Route path="leads/:id" element={<LeadDetailPage />} />
          <Route path="deals" element={<DealsPage />} />
          <Route path="deals/:id" element={<DealDetailPage />} />
          <Route path="forecast" element={<ForecastPage />} />
          <Route path="contacts" element={<ContactsPage />} />
          <Route path="contacts/:id" element={<ContactDetailPage />} />
          <Route path="accounts" element={<AccountsPage />} />
          <Route path="accounts/:id" element={<AccountDetailPage />} />
          <Route path="tasks" element={<TasksPage />} />
          <Route path="reports" element={<ReportsPage />} />
          <Route path="campaigns" element={<CampaignsPage />} />
          <Route path="calls" element={<CallsPage />} />
          <Route path="playbooks" element={<PlaybooksPage />} />
          <Route path="settings" element={<Navigate to="/settings/organization" replace />} />
          <Route path="settings/:section" element={<RequireRole allow="admin"><SettingsPage /></RequireRole>} />
          <Route path="audit-log" element={<RequireRole allow="manager"><AuditLogPage /></RequireRole>} />
          <Route path="profile" element={<ProfilePage />} />
          <Route path="notifications" element={<NotificationsPage />} />
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Routes>
      </Suspense>
      <Toaster />
    </>
  )
}
