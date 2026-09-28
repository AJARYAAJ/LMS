import { useEffect, useMemo, useState } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from 'react-router-dom'
import clsx from 'clsx'
import {
  ArrowRight, BarChart3, Building2, CheckSquare, CornerDownLeft, Kanban, LayoutDashboard, Plus, Search, Settings, Target, User, Users,
} from 'lucide-react'
import { useAppDispatch, useAppSelector, usePermissions } from '@/app/hooks'
import { setCommandOpen } from '@/features/ui/uiSlice'
import { useSearchQuery } from '@/services/api'
import { Badge, Spinner } from '@/components/ui'

interface Command {
  id: string
  label: string
  hint?: string
  icon: typeof Search
  run: () => void
  badge?: string | null
  color?: string | null
}

const typeIcon = { lead: Target, contact: User, account: Building2, deal: Kanban }

export function CommandPalette() {
  const open = useAppSelector((s) => s.ui.commandOpen)
  const dispatch = useAppDispatch()
  const navigate = useNavigate()
  const { admin } = usePermissions()
  const [query, setQuery] = useState('')
  const [debounced, setDebounced] = useState('')
  const [active, setActive] = useState(0)

  const close = () => {
    dispatch(setCommandOpen(false))
    setQuery('')
  }

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        dispatch(setCommandOpen(!open))
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [dispatch, open])

  useEffect(() => {
    const t = setTimeout(() => setDebounced(query.trim()), 200)
    return () => clearTimeout(t)
  }, [query])

  const { data: results, isFetching } = useSearchQuery(debounced, { skip: debounced.length < 2 || !open })

  const commands = useMemo<Command[]>(() => {
    const go = (to: string) => () => {
      navigate(to)
      close()
    }
    const nav: Command[] = [
      { id: 'new-lead', label: 'Create new lead', icon: Plus, run: go('/leads?new=1'), hint: 'Action' },
      { id: 'new-task', label: 'Create new task', icon: Plus, run: go('/tasks?new=1'), hint: 'Action' },
      { id: 'dash', label: 'Go to Dashboard', icon: LayoutDashboard, run: go('/') },
      { id: 'leads', label: 'Go to Leads', icon: Target, run: go('/leads') },
      { id: 'deals', label: 'Go to Pipeline', icon: Kanban, run: go('/deals') },
      { id: 'tasks', label: 'Go to Tasks', icon: CheckSquare, run: go('/tasks') },
      { id: 'contacts', label: 'Go to Contacts', icon: Users, run: go('/contacts') },
      { id: 'accounts', label: 'Go to Accounts', icon: Building2, run: go('/accounts') },
      { id: 'reports', label: 'Go to Reports', icon: BarChart3, run: go('/reports') },
      ...(admin ? [{ id: 'settings', label: 'Open Settings', icon: Settings, run: go('/settings') }] : []),
      { id: 'profile', label: 'My profile', icon: User, run: go('/profile') },
    ]
    const q = query.trim().toLowerCase()
    const filtered = q ? nav.filter((c) => c.label.toLowerCase().includes(q)) : nav
    const found: Command[] = (debounced.length >= 2 ? results ?? [] : []).map((r) => ({
      id: `${r.type}-${r.id}`,
      label: r.title,
      hint: r.subtitle ?? r.type,
      icon: typeIcon[r.type],
      badge: r.badge,
      color: r.color,
      run: go(r.url),
    }))
    return [...found, ...filtered]
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, debounced, results, admin])

  useEffect(() => setActive(0), [commands.length])

  if (!open) return null

  return createPortal(
    <div className="fixed inset-0 z-[55] flex items-start justify-center p-4 pt-[12vh]">
      <div className="absolute inset-0 animate-fade-in bg-slate-950/50 backdrop-blur-sm" onClick={close} />
      <div className="relative w-full max-w-xl animate-slide-up overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
        <div className="flex items-center gap-3 border-b border-slate-100 px-4 dark:border-slate-800">
          <Search className="size-5 text-slate-400" />
          <input
            autoFocus
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Escape') close()
              if (e.key === 'ArrowDown') {
                e.preventDefault()
                setActive((a) => Math.min(a + 1, commands.length - 1))
              }
              if (e.key === 'ArrowUp') {
                e.preventDefault()
                setActive((a) => Math.max(a - 1, 0))
              }
              if (e.key === 'Enter') commands[active]?.run()
            }}
            placeholder="Search leads, contacts, deals… or type a command"
            className="h-14 flex-1 bg-transparent text-sm text-slate-900 placeholder-slate-400 outline-none dark:text-white"
          />
          {isFetching && <Spinner className="size-4" />}
          <kbd className="rounded border border-slate-200 px-1.5 py-0.5 text-[10px] text-slate-400 dark:border-slate-700">ESC</kbd>
        </div>
        <ul className="max-h-[50vh] overflow-y-auto p-2">
          {commands.length === 0 && <li className="px-3 py-8 text-center text-sm text-slate-500">No results for “{query}”</li>}
          {commands.map((c, i) => (
            <li key={c.id}>
              <button
                onMouseEnter={() => setActive(i)}
                onClick={c.run}
                className={clsx('flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm', i === active ? 'bg-brand-50 text-brand-900 dark:bg-brand-500/10 dark:text-white' : 'text-slate-700 dark:text-slate-300')}
              >
                <c.icon className={clsx('size-4 shrink-0', i === active ? 'text-brand-600' : 'text-slate-400')} />
                <span className="min-w-0 flex-1 truncate font-medium">{c.label}</span>
                {c.badge && <Badge color={c.color}>{c.badge}</Badge>}
                {c.hint && <span className="max-w-40 truncate text-xs text-slate-400 capitalize">{c.hint}</span>}
                {i === active ? <CornerDownLeft className="size-3.5 text-slate-400" /> : <ArrowRight className="size-3.5 text-transparent" />}
              </button>
            </li>
          ))}
        </ul>
        <div className="flex items-center gap-4 border-t border-slate-100 px-4 py-2 text-[11px] text-slate-400 dark:border-slate-800">
          <span>↑↓ navigate</span>
          <span>↵ open</span>
          <span className="ml-auto">⌘K / Ctrl+K anywhere</span>
        </div>
      </div>
    </div>,
    document.body,
  )
}
