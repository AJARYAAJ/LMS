import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout, RequireRole } from '@/components/layout/AppLayout'
import { PageLoader, Toaster } from '@/components/ui'
import { LoginPage } from '@/pages/auth/LoginPage'
import { RegisterPage } from '@/pages/auth/RegisterPage'

// Pages are code-split so the first load stays small.
const DashboardPage = lazy(() => import('@/pages/DashboardPage').then((m) => ({ default: m.DashboardPage })))
const LeadsPage = lazy(() => import('@/pages/leads/LeadsPage').then((m) => ({ default: m.LeadsPage })))
const LeadDetailPage = lazy(() => import('@/pages/leads/LeadDetailPage').then((m) => ({ default: m.LeadDetailPage })))
const QueuePage = lazy(() => import('@/pages/leads/QueuePage').then((m) => ({ default: m.QueuePage })))
const TrashPage = lazy(() => import('@/pages/leads/TrashPage').then((m) => ({ default: m.TrashPage })))
const DealsPage = lazy(() => import('@/pages/deals/DealsPage').then((m) => ({ default: m.DealsPage })))
const DealDetailPage = lazy(() => import('@/pages/deals/DealDetailPage').then((m) => ({ default: m.DealDetailPage })))
const ContactDetailPage = lazy(() => import('@/pages/contacts/ContactsPage').then((m) => ({ default: m.ContactDetailPage })))
const ContactsPage = lazy(() => import('@/pages/contacts/ContactsPage').then((m) => ({ default: m.ContactsPage })))
const AccountDetailPage = lazy(() => import('@/pages/accounts/AccountsPage').then((m) => ({ default: m.AccountDetailPage })))
const AccountsPage = lazy(() => import('@/pages/accounts/AccountsPage').then((m) => ({ default: m.AccountsPage })))
const TasksPage = lazy(() => import('@/pages/tasks/TasksPage').then((m) => ({ default: m.TasksPage })))
const ReportsPage = lazy(() => import('@/pages/ReportsPage').then((m) => ({ default: m.ReportsPage })))
const CampaignsPage = lazy(() => import('@/pages/CampaignsPage').then((m) => ({ default: m.CampaignsPage })))
const PlaybooksPage = lazy(() => import('@/pages/PlaybooksPage').then((m) => ({ default: m.PlaybooksPage })))
const SettingsPage = lazy(() => import('@/pages/settings/SettingsPage').then((m) => ({ default: m.SettingsPage })))
const AuditLogPage = lazy(() => import('@/pages/AuditLogPage').then((m) => ({ default: m.AuditLogPage })))
const ProfilePage = lazy(() => import('@/pages/ProfilePage').then((m) => ({ default: m.ProfilePage })))
const PublicFormPage = lazy(() => import('@/pages/PublicFormPage').then((m) => ({ default: m.PublicFormPage })))
const NotFoundPage = lazy(() => import('@/pages/NotFoundPage').then((m) => ({ default: m.NotFoundPage })))

export default function App() {
  return (
    <>
      <Suspense fallback={<PageLoader />}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/f/:slug" element={<PublicFormPage />} />
        <Route element={<AppLayout />}>
          <Route index element={<DashboardPage />} />
          <Route path="leads" element={<LeadsPage />} />
          <Route path="leads/queue" element={<QueuePage />} />
          <Route path="leads/trash" element={<RequireRole allow="manager"><TrashPage /></RequireRole>} />
          <Route path="leads/:id" element={<LeadDetailPage />} />
          <Route path="deals" element={<DealsPage />} />
          <Route path="deals/:id" element={<DealDetailPage />} />
          <Route path="contacts" element={<ContactsPage />} />
          <Route path="contacts/:id" element={<ContactDetailPage />} />
          <Route path="accounts" element={<AccountsPage />} />
          <Route path="accounts/:id" element={<AccountDetailPage />} />
          <Route path="tasks" element={<TasksPage />} />
          <Route path="reports" element={<ReportsPage />} />
          <Route path="campaigns" element={<CampaignsPage />} />
          <Route path="playbooks" element={<PlaybooksPage />} />
          <Route path="settings" element={<Navigate to="/settings/organization" replace />} />
          <Route path="settings/:section" element={<RequireRole allow="admin"><SettingsPage /></RequireRole>} />
          <Route path="audit-log" element={<RequireRole allow="manager"><AuditLogPage /></RequireRole>} />
          <Route path="profile" element={<ProfilePage />} />
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Routes>
      </Suspense>
      <Toaster />
    </>
  )
}
