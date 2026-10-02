import { useNavigate } from 'react-router-dom'
import clsx from 'clsx'
import { Bell, CheckCheck } from 'lucide-react'
import { kindIcon } from '@/components/layout/NotificationBell'
import { useNotificationsQuery, useReadAllNotificationsMutation, useReadNotificationMutation } from '@/services/api'
import { Button, EmptyState, PageHeader, PageLoader, Segmented } from '@/components/ui'
import { dateTime, ago } from '@/lib/format'
import { useState } from 'react'


export function NotificationsPage() {
  const navigate = useNavigate()
  const [filter, setFilter] = useState<'all' | 'unread'>('all')
  const { data, isLoading } = useNotificationsQuery(200)
  const [readOne] = useReadNotificationMutation()
  const [readAll, readAllState] = useReadAllNotificationsMutation()
  const items = (data?.data ?? []).filter((n) => filter === 'all' || !n.read_at)

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader icon={<Bell />} title="Notifications" description="Assignments, AI call results, lead replies, workflow alerts and task reminders."
        actions={<>
          <Segmented value={filter} onChange={setFilter} options={[{ value: 'all', label: 'All' }, { value: 'unread', label: `Unread · ${data?.unread ?? 0}` }]} />
          <Button size="sm" variant="secondary" icon={<CheckCheck className="size-4" />} disabled={!data?.unread} loading={readAllState.isLoading} onClick={() => readAll()}>Mark all read</Button>
        </>} />
      {isLoading ? <PageLoader /> : (
        <div className="card p-2">
          <ul className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
            {items.map((n) => {
              const Icon = kindIcon[n.kind] ?? Bell
              return (
                <li key={n.id}>
                  <button onClick={() => { if (!n.read_at) readOne(n.id); if (n.url) navigate(n.url) }}
                    className={clsx('flex w-full gap-4 rounded-2xl px-4 py-3.5 text-left transition hover:bg-brand-50/60 dark:hover:bg-white/[0.04]', !n.read_at && 'bg-brand-50/50 dark:bg-brand-500/[0.06]')}>
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-100 to-fuchsia-100 text-brand-600 dark:from-brand-500/20 dark:to-fuchsia-500/10 dark:text-brand-200"><Icon className="size-5" /></span>
                    <span className="min-w-0 flex-1">
                      <span className="flex items-center gap-2"><span className="font-medium text-slate-900 dark:text-white">{n.title}</span>{!n.read_at && <span className="size-2 rounded-full bg-fuchsia-500" />}</span>
                      {n.body && <span className="block text-sm text-slate-600 dark:text-slate-400">{n.body}</span>}
                    </span>
                    <span className="text-xs whitespace-nowrap text-slate-400" title={dateTime(n.created_at)}>{ago(n.created_at)}</span>
                  </button>
                </li>
              )
            })}
          </ul>
          {!items.length && <EmptyState icon={<Bell />} title={filter === 'unread' ? 'Nothing unread' : 'No notifications yet'} />}
        </div>
      )}
    </div>
  )
}
