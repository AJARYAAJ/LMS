import { useNavigate } from 'react-router-dom'
import { LogOut, Menu as MenuIcon, Monitor, Moon, Plus, Search, Sun, User as UserIcon } from 'lucide-react'
import { useAppDispatch, useAppSelector, usePermissions } from '@/app/hooks'
import { loggedOut } from '@/features/auth/authSlice'
import { setCommandOpen, setMobileNav, setTheme, type Theme } from '@/features/ui/uiSlice'
import { api, useLogoutMutation } from '@/services/api'
import { Avatar, Button, Menu, MenuItem } from '@/components/ui'
import { ROLE_LABELS } from '@/lib/constants'
import { NotificationBell } from './NotificationBell'

const themes: { value: Theme; icon: typeof Sun; label: string }[] = [
  { value: 'light', icon: Sun, label: 'Light' },
  { value: 'dark', icon: Moon, label: 'Dark' },
  { value: 'system', icon: Monitor, label: 'System' },
]

export function Topbar() {
  const dispatch = useAppDispatch()
  const navigate = useNavigate()
  const user = useAppSelector((s) => s.auth.user)
  const theme = useAppSelector((s) => s.ui.theme)
  const { write } = usePermissions()
  const [logout] = useLogoutMutation()
  const ThemeIcon = themes.find((t) => t.value === theme)?.icon ?? Monitor

  const signOut = async () => {
    await logout().catch(() => undefined)
    dispatch(loggedOut())
    dispatch(api.util.resetApiState())
    navigate('/login')
  }

  return (
    <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200/80 bg-white/80 px-4 backdrop-blur-xl sm:px-6 dark:border-slate-800 dark:bg-slate-900/80">
      <button onClick={() => dispatch(setMobileNav(true))} className="rounded-lg p-2 text-slate-500 lg:hidden" aria-label="Open menu">
        <MenuIcon className="size-5" />
      </button>

      <button
        onClick={() => dispatch(setCommandOpen(true))}
        className="flex h-9 w-full max-w-md items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm text-slate-400 transition hover:border-slate-300 hover:bg-white dark:border-slate-700 dark:bg-slate-800/60 dark:hover:bg-slate-800"
      >
        <Search className="size-4" />
        <span className="flex-1 text-left">Search anything…</span>
        <kbd className="hidden rounded border border-slate-200 bg-white px-1.5 text-[10px] font-medium text-slate-500 sm:inline dark:border-slate-600 dark:bg-slate-700 dark:text-slate-300">⌘K</kbd>
      </button>

      <div className="ml-auto flex items-center gap-1">
        {write && (
          <Button size="sm" icon={<Plus className="size-4" />} onClick={() => navigate('/leads?new=1')} className="hidden sm:inline-flex">
            New lead
          </Button>
        )}

        <Menu
          width="w-36"
          trigger={({ toggle }) => (
            <button onClick={toggle} className="inline-flex size-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-800 dark:hover:text-white" aria-label="Theme">
              <ThemeIcon className="size-[18px]" />
            </button>
          )}
        >
          {(close) =>
            themes.map((t) => (
              <MenuItem key={t.value} icon={<t.icon />} active={theme === t.value} onClick={() => { dispatch(setTheme(t.value)); close() }}>
                {t.label}
              </MenuItem>
            ))
          }
        </Menu>

        <NotificationBell />

        <Menu
          trigger={({ toggle }) => (
            <button onClick={toggle} className="ml-1 flex items-center gap-2 rounded-full p-0.5 pr-2 transition hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Account menu">
              <Avatar name={user?.name} color={user?.avatar_color} size="sm" />
              <span className="hidden text-left md:block">
                <span className="block text-xs font-semibold text-slate-800 dark:text-slate-100">{user?.name}</span>
                <span className="block text-[10px] text-slate-500">{ROLE_LABELS[user?.role ?? ''] ?? ''}</span>
              </span>
            </button>
          )}
        >
          {(close) => (
            <>
              <div className="border-b border-slate-100 px-3 py-2.5 dark:border-slate-800">
                <p className="truncate text-sm font-medium text-slate-900 dark:text-white">{user?.name}</p>
                <p className="truncate text-xs text-slate-500">{user?.email}</p>
              </div>
              <div className="py-1">
                <MenuItem icon={<UserIcon />} onClick={() => { navigate('/profile'); close() }}>Profile & preferences</MenuItem>
                <MenuItem icon={<LogOut />} danger onClick={signOut}>Sign out</MenuItem>
              </div>
            </>
          )}
        </Menu>
      </div>
    </header>
  )
}
