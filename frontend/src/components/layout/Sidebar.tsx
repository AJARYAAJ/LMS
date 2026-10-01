import { NavLink } from 'react-router-dom'
import clsx from 'clsx'
import {
  BarChart3, BookOpenCheck, Building2, CheckSquare, ChevronsLeft, History, Kanban, LayoutDashboard, Megaphone, PhoneCall, TrendingUp, Inbox as InboxIcon, Settings, Sparkles, Target, Users, X,
} from 'lucide-react'
import { useAppDispatch, useAppSelector, usePermissions } from '@/app/hooks'
import { setMobileNav, toggleSidebar } from '@/features/ui/uiSlice'
import { useInboxSummaryQuery, useTaskSummaryQuery } from '@/services/api'

interface NavItem {
  to: string
  label: string
  icon: typeof LayoutDashboard
  badge?: number
}

/**
 * Floating glass "dock". Collapsed it is an icon rail with hover labels;
 * expanded it shows grouped labels.
 */
export function Sidebar() {
  const dispatch = useAppDispatch()
  const collapsed = useAppSelector((s) => s.ui.sidebarCollapsed)
  const mobileOpen = useAppSelector((s) => s.ui.mobileNavOpen)
  const { admin, manager } = usePermissions()
  const { data: tasks } = useTaskSummaryQuery({ assignee: 'me' }, { pollingInterval: 120_000 })
  const { data: inbox } = useInboxSummaryQuery(undefined, { pollingInterval: 60_000 })
  const org = useAppSelector((s) => s.auth.user?.organization)

  const sections: { title?: string; items: NavItem[] }[] = [
    {
      title: 'Work',
      items: [
        { to: '/', label: 'Pulse', icon: LayoutDashboard },
        { to: '/leads', label: 'Leads', icon: Target },
        { to: '/inbox', label: 'Inbox', icon: InboxIcon, badge: inbox?.unread_threads },
        { to: '/deals', label: 'Pipeline', icon: Kanban },
        { to: '/forecast', label: 'Forecast', icon: TrendingUp },
        { to: '/calls', label: 'AI calls', icon: PhoneCall },
        { to: '/tasks', label: 'Tasks & calendar', icon: CheckSquare, badge: (tasks?.overdue ?? 0) + (tasks?.today ?? 0) },
      ],
    },
    {
      title: 'Relationships',
      items: [
        { to: '/contacts', label: 'Contacts', icon: Users },
        { to: '/accounts', label: 'Accounts', icon: Building2 },
        { to: '/campaigns', label: 'Campaigns', icon: Megaphone },
        { to: '/playbooks', label: 'Playbooks', icon: BookOpenCheck },
        { to: '/reports', label: 'Insights', icon: BarChart3 },
      ],
    },
    {
      title: 'Control',
      items: [
        ...(admin ? [{ to: '/settings', label: 'Settings', icon: Settings }] : []),
        ...(manager ? [{ to: '/audit-log', label: 'Audit log', icon: History }] : []),
      ],
    },
  ]

  const close = () => dispatch(setMobileNav(false))

  return (
    <>
      {mobileOpen && <div className="fixed inset-0 z-40 bg-ink-950/40 backdrop-blur-sm lg:hidden" onClick={close} />}
      <aside
        className={clsx(
          'glass glass-blur fixed top-3 bottom-3 left-3 z-50 flex flex-col rounded-[28px] transition-all duration-500 ease-[cubic-bezier(0.16,1,0.3,1)]',
          collapsed ? 'lg:w-[76px]' : 'lg:w-[248px]',
          mobileOpen ? 'w-[248px] translate-x-0' : 'w-[248px] -translate-x-[120%] lg:translate-x-0',
        )}
      >
        <div className={clsx('flex h-[72px] items-center gap-2 px-4', collapsed && 'lg:justify-center lg:px-0')}>
          <div className="relative flex size-11 shrink-0 items-center justify-center">
            <span className="absolute inset-0 animate-spin-slow rounded-2xl bg-[conic-gradient(from_0deg,#8b5cf6,#d946ef,#06b6d4,#8b5cf6)] p-0.5" />
            <span className="relative m-0.5 flex size-10 items-center justify-center rounded-[14px] bg-ink-900 text-white">
              <Sparkles className="size-5" />
            </span>
          </div>
          <div className={clsx('min-w-0', collapsed && 'lg:hidden')}>
            <p className="font-display text-[15px] font-bold tracking-tight text-slate-900 dark:text-white">LeadFlow</p>
            <p className="truncate text-[11px] text-slate-500">{org?.name ?? 'Aurora CRM'}</p>
          </div>
          <button onClick={close} className="ml-auto rounded-lg p-1 text-slate-400 lg:hidden" aria-label="Close menu">
            <X className="size-5" />
          </button>
        </div>

        <nav className="flex-1 space-y-5 overflow-x-visible overflow-y-auto px-3 py-2">
          {sections.filter((s) => s.items.length).map((section) => (
            <div key={section.title}>
              <p className={clsx('mb-1.5 px-3 text-[10px] font-semibold tracking-[0.14em] text-slate-400 uppercase', collapsed && 'lg:hidden')}>{section.title}</p>
              {collapsed && <div className="mx-auto mb-2 hidden h-px w-6 bg-slate-300/60 lg:block dark:bg-white/10" />}
              <ul className="space-y-1">
                {section.items.map((item) => (
                  <li key={item.to} className="group/nav relative">
                    <NavLink
                      to={item.to}
                      end={item.to === '/'}
                      onClick={close}
                      className={({ isActive }) =>
                        clsx(
                          'relative flex items-center gap-3 rounded-2xl px-3 py-2.5 text-[13.5px] font-medium transition-all duration-300',
                          isActive
                            ? 'bg-[linear-gradient(135deg,rgba(139,92,246,0.16),rgba(217,70,239,0.12))] text-brand-700 shadow-[inset_0_0_0_1px_rgba(139,92,246,0.25)] dark:text-white'
                            : 'text-slate-600 hover:bg-slate-900/[0.04] hover:text-slate-900 dark:text-slate-400 dark:hover:bg-white/[0.05] dark:hover:text-white',
                          collapsed && 'lg:justify-center lg:px-0',
                        )
                      }
                    >
                      {({ isActive }) => (
                        <>
                          <span className={clsx('relative flex size-5 items-center justify-center', isActive && 'drop-shadow-[0_0_8px_rgba(168,85,247,0.8)]')}>
                            <item.icon className="size-[19px]" strokeWidth={isActive ? 2.2 : 1.8} />
                          </span>
                          <span className={clsx(collapsed && 'lg:hidden')}>{item.label}</span>
                          {!!item.badge && (
                            <span className={clsx('ml-auto rounded-full bg-gradient-to-r from-rose-500 to-fuchsia-500 px-1.5 py-0.5 text-[10px] font-bold text-white shadow-[0_0_12px_rgba(244,63,94,0.6)]', collapsed && 'lg:absolute lg:top-1 lg:right-2')}>
                              {item.badge}
                            </span>
                          )}
                        </>
                      )}
                    </NavLink>
                    {collapsed && (
                      <span className="glass-solid pointer-events-none absolute top-1/2 left-full z-50 ml-3 hidden -translate-y-1/2 translate-x-1 rounded-xl px-3 py-1.5 text-xs font-medium whitespace-nowrap text-slate-800 opacity-0 shadow-lg transition-all duration-200 group-hover/nav:translate-x-0 group-hover/nav:opacity-100 lg:block dark:text-white">
                        {item.label}
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </nav>

        <div className="hidden p-3 lg:block">
          <button
            onClick={() => dispatch(toggleSidebar())}
            className="flex w-full items-center justify-center gap-2 rounded-2xl px-3 py-2.5 text-xs font-medium text-slate-500 transition hover:bg-slate-900/[0.04] dark:hover:bg-white/[0.05]"
            aria-label={collapsed ? 'Expand navigation' : 'Collapse navigation'}
          >
            <ChevronsLeft className={clsx('size-4 transition-transform duration-500', collapsed && 'rotate-180')} />
            {!collapsed && 'Collapse'}
          </button>
        </div>
      </aside>
    </>
  )
}
