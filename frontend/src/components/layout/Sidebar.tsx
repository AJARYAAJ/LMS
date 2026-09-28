import { NavLink } from 'react-router-dom'
import clsx from 'clsx'
import {
  BarChart3, Building2, CheckSquare, ChevronsLeft, History, Kanban, LayoutDashboard, Megaphone, Settings, Sparkles, Target, Users, X,
} from 'lucide-react'
import { useAppDispatch, useAppSelector, usePermissions } from '@/app/hooks'
import { setMobileNav, toggleSidebar } from '@/features/ui/uiSlice'
import { useTaskSummaryQuery } from '@/services/api'

interface NavItem {
  to: string
  label: string
  icon: typeof LayoutDashboard
  badge?: number
}

export function Sidebar() {
  const dispatch = useAppDispatch()
  const collapsed = useAppSelector((s) => s.ui.sidebarCollapsed)
  const mobileOpen = useAppSelector((s) => s.ui.mobileNavOpen)
  const { admin, manager } = usePermissions()
  const { data: tasks } = useTaskSummaryQuery({ assignee: 'me' }, { pollingInterval: 120_000 })
  const org = useAppSelector((s) => s.auth.user?.organization)

  const sections: { title?: string; items: NavItem[] }[] = [
    {
      items: [
        { to: '/', label: 'Dashboard', icon: LayoutDashboard },
        { to: '/leads', label: 'Leads', icon: Target },
        { to: '/deals', label: 'Pipeline', icon: Kanban },
        { to: '/tasks', label: 'Tasks', icon: CheckSquare, badge: (tasks?.overdue ?? 0) + (tasks?.today ?? 0) },
      ],
    },
    {
      title: 'CRM',
      items: [
        { to: '/contacts', label: 'Contacts', icon: Users },
        { to: '/accounts', label: 'Accounts', icon: Building2 },
        { to: '/campaigns', label: 'Campaigns', icon: Megaphone },
        { to: '/reports', label: 'Reports', icon: BarChart3 },
      ],
    },
    {
      title: 'Admin',
      items: [
        ...(admin ? [{ to: '/settings', label: 'Settings', icon: Settings }] : []),
        ...(manager ? [{ to: '/audit-log', label: 'Audit log', icon: History }] : []),
      ],
    },
  ]

  const close = () => dispatch(setMobileNav(false))

  return (
    <>
      {mobileOpen && <div className="fixed inset-0 z-40 bg-slate-950/40 backdrop-blur-sm lg:hidden" onClick={close} />}
      <aside
        className={clsx(
          'fixed inset-y-0 left-0 z-50 flex flex-col border-r border-slate-200/80 bg-white transition-all duration-300 dark:border-slate-800 dark:bg-slate-900',
          collapsed ? 'lg:w-[72px]' : 'lg:w-64',
          mobileOpen ? 'w-64 translate-x-0' : 'w-64 -translate-x-full lg:translate-x-0',
        )}
      >
        <div className="flex h-16 items-center justify-between gap-2 px-4">
          <div className="flex min-w-0 items-center gap-2.5">
            <div className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30">
              <Sparkles className="size-5" />
            </div>
            {!collapsed && (
              <div className="min-w-0">
                <p className="text-sm font-bold tracking-tight text-slate-900 dark:text-white">LeadFlow</p>
                <p className="truncate text-[11px] text-slate-500">{org?.name ?? 'Lead Management'}</p>
              </div>
            )}
          </div>
          <button onClick={close} className="rounded-lg p-1 text-slate-400 lg:hidden" aria-label="Close menu">
            <X className="size-5" />
          </button>
        </div>

        <nav className="flex-1 space-y-6 overflow-y-auto px-3 py-4">
          {sections.filter((s) => s.items.length).map((section, i) => (
            <div key={i}>
              {section.title && !collapsed && <p className="mb-2 px-3 text-[10px] font-semibold tracking-widest text-slate-400 uppercase">{section.title}</p>}
              <ul className="space-y-0.5">
                {section.items.map((item) => (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      end={item.to === '/'}
                      onClick={close}
                      title={collapsed ? item.label : undefined}
                      className={({ isActive }) =>
                        clsx(
                          'group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                          isActive
                            ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300'
                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white',
                          collapsed && 'lg:justify-center lg:px-0',
                        )
                      }
                    >
                      {({ isActive }) => (
                        <>
                          {isActive && <span className="absolute top-1.5 bottom-1.5 left-0 w-1 rounded-r-full bg-brand-600" />}
                          <item.icon className="size-[18px] shrink-0" />
                          <span className={clsx(collapsed && 'lg:hidden')}>{item.label}</span>
                          {!!item.badge && (
                            <span className={clsx('ml-auto rounded-full bg-rose-500 px-1.5 py-0.5 text-[10px] font-semibold text-white', collapsed && 'lg:absolute lg:top-0.5 lg:right-1.5')}>
                              {item.badge}
                            </span>
                          )}
                        </>
                      )}
                    </NavLink>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </nav>

        <div className="hidden border-t border-slate-100 p-3 lg:block dark:border-slate-800">
          <button
            onClick={() => dispatch(toggleSidebar())}
            className="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-xs font-medium text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
          >
            <ChevronsLeft className={clsx('size-4 transition-transform', collapsed && 'rotate-180')} />
            {!collapsed && 'Collapse'}
          </button>
        </div>
      </aside>
    </>
  )
}
