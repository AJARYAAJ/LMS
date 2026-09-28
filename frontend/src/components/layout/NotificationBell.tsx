import { useNavigate } from 'react-router-dom'
import clsx from 'clsx'
import { AlarmClock, Bell, CheckCheck, UserPlus, Zap } from 'lucide-react'
import { Menu } from '@/components/ui'
import { ago } from '@/lib/format'
import { useNotificationsQuery, useReadAllNotificationsMutation, useReadNotificationMutation } from '@/services/api'

const kindIcon: Record<string, typeof Bell> = { assignment: UserPlus, automation: Zap, reminder: AlarmClock }

export function NotificationBell() {
  const navigate = useNavigate()
  const { data } = useNotificationsQuery(undefined, { pollingInterval: 30_000 })
  const [readOne] = useReadNotificationMutation()
  const [readAll] = useReadAllNotificationsMutation()
  const unread = data?.unread ?? 0

  return (
    <Menu
      width="w-[22rem]"
      trigger={({ toggle }) => (
        <button onClick={toggle} className="relative inline-flex size-9 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-900/[0.05] hover:text-slate-900 dark:hover:bg-white/[0.07] dark:hover:text-white" aria-label="Notifications">
          <Bell className="size-[18px]" />
          {unread > 0 && (
            <span className="absolute top-1 right-1 flex min-w-4 items-center justify-center rounded-full bg-gradient-to-r from-rose-500 to-fuchsia-500 px-1 text-[10px] font-bold text-white shadow-[0_0_10px_rgba(244,63,94,0.7)]">
              {unread > 9 ? '9+' : unread}
            </span>
          )}
        </button>
      )}
    >
      {(close) => (
        <div>
          <div className="flex items-center justify-between px-3 py-2">
            <p className="text-sm font-semibold text-slate-900 dark:text-white">Notifications</p>
            {unread > 0 && (
              <button onClick={() => readAll()} className="flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700">
                <CheckCheck className="size-3.5" /> Mark all read
              </button>
            )}
          </div>
          <div className="max-h-96 overflow-y-auto">
            {!data?.data.length && <p className="px-3 py-10 text-center text-sm text-slate-500">You're all caught up 🎉</p>}
            {data?.data.map((n) => {
              const Icon = kindIcon[n.kind] ?? Bell
              return (
                <button
                  key={n.id}
                  onClick={() => {
                    if (!n.read_at) readOne(n.id)
                    if (n.url) navigate(n.url)
                    close()
                  }}
                  className={clsx('flex w-full gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-brand-50/70 dark:hover:bg-white/[0.05]', !n.read_at && 'bg-brand-50/60 dark:bg-brand-500/[0.07]')}
                >
                  <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300">
                    <Icon className="size-4" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-2">
                      <span className="truncate text-sm font-medium text-slate-900 dark:text-white">{n.title}</span>
                      {!n.read_at && <span className="size-1.5 shrink-0 rounded-full bg-brand-500" />}
                    </span>
                    {n.body && <span className="line-clamp-2 text-xs text-slate-500">{n.body}</span>}
                    <span className="mt-0.5 block text-[11px] text-slate-400">{ago(n.created_at)}</span>
                  </span>
                </button>
              )
            })}
          </div>
        </div>
      )}
    </Menu>
  )
}
