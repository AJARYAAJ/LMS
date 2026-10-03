import { useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import clsx from 'clsx'
import {
  addMonths, eachDayOfInterval, endOfMonth, endOfWeek, format, isSameDay, isSameMonth, isToday, parseISO, startOfMonth, startOfWeek,
} from 'date-fns'
import { AlarmClock, CalendarDays, CheckCircle2, ChevronLeft, ChevronRight, Circle, List, Pencil, Plus, Repeat, Search, Trash2 } from 'lucide-react'
import { useAction, usePermissions } from '@/app/hooks'
import { useDeleteTaskMutation, useTaskSummaryQuery, useTasksQuery, useToggleTaskMutation } from '@/services/api'
import { Avatar, Button, EmptyState, Input, PageHeader, Pagination, Segmented, Select, Skeleton } from '@/components/ui'
import { PriorityBadge } from '@/components/crm/Badges'
import { TaskFormModal } from '@/components/crm/TaskFormModal'
import { friendlyDue, humanize } from '@/lib/format'
import type { Task } from '@/types'

type View = 'open' | 'overdue' | 'today' | 'upcoming' | 'completed'

const typeColor: Record<string, string> = { call: '#10b981', email: '#0ea5e9', meeting: '#8b5cf6', follow_up: '#f59e0b', todo: '#64748b' }

export function TasksPage() {
  const [params, setParams] = useSearchParams()
  const { write, manager } = usePermissions()
  const run = useAction()
  const view = (params.get('view') as View) ?? 'open'
  const mode = params.get('mode') === 'calendar' ? 'calendar' : 'list'
  const [assignee, setAssignee] = useState(manager ? '' : 'me')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<Task | null>(null)
  const [showForm, setShowForm] = useState(params.get('new') === '1')
  const { data: summary } = useTaskSummaryQuery({ assignee })
  const { data, isLoading } = useTasksQuery({ view, assignee, search, page }, { skip: mode !== 'list' })
  const [toggle] = useToggleTaskMutation()
  const [remove] = useDeleteTaskMutation()

  const setView = (v: View) => { const n = new URLSearchParams(params); n.set('view', v); setParams(n, { replace: true }); setPage(1) }
  const setMode = (m: string) => { const n = new URLSearchParams(params); if (m === 'calendar') n.set('mode', 'calendar'); else n.delete('mode'); setParams(n, { replace: true }) }

  const tiles: { value: View; label: string; count?: number; tone: string }[] = [
    { value: 'overdue', label: 'Overdue', count: summary?.overdue, tone: 'from-rose-500 to-pink-500' },
    { value: 'today', label: 'Today', count: summary?.today, tone: 'from-amber-400 to-orange-500' },
    { value: 'upcoming', label: 'Upcoming', count: summary?.upcoming, tone: 'from-brand-500 to-fuchsia-500' },
    { value: 'open', label: 'All open', count: summary?.open, tone: 'from-cyan-400 to-sky-500' },
    { value: 'completed', label: 'Done this week', count: summary?.completed_this_week, tone: 'from-emerald-400 to-teal-500' },
  ]

  return (
    <div>
      <PageHeader icon={<AlarmClock />} title="Tasks & calendar" description="Follow-ups, calls and meetings — never let a lead go cold."
        actions={<>
          <Segmented value={mode} onChange={setMode} options={[{ value: 'list', label: 'List', icon: <List /> }, { value: 'calendar', label: 'Calendar', icon: <CalendarDays /> }]} />
          {write && <Button size="sm" icon={<Plus className="size-4" />} onClick={() => setShowForm(true)}>New task</Button>}
        </>}
      />

      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-5">
        {tiles.map((t) => (
          <button key={t.value} onClick={() => { setView(t.value); setMode('list') }}
            className={clsx('card p-4 text-left transition-all duration-300 hover:-translate-y-0.5', view === t.value && mode === 'list' && 'gradient-border')}>
            <span className={clsx('inline-block h-1.5 w-8 rounded-full bg-gradient-to-r', t.tone)} />
            <p className="font-display mt-3 text-3xl font-bold text-slate-900 dark:text-white">{t.count ?? '–'}</p>
            <p className="text-xs font-medium text-slate-500">{t.label}</p>
          </button>
        ))}
      </div>

      <div className="card mb-5 flex flex-wrap gap-2 p-3">
        {mode === 'list' && <div className="min-w-56 flex-1"><Input icon={<Search className="size-4" />} placeholder="Search tasks…" value={search} onChange={(e) => setSearch(e.target.value)} /></div>}
        <Select className="w-auto" value={assignee} onChange={(e) => setAssignee(e.target.value)}>
          {manager && <option value="">Everyone</option>}
          <option value="me">My tasks</option>
        </Select>
      </div>

      {mode === 'calendar' ? <TaskCalendar assignee={assignee} onEdit={setEditing} /> : (
        <div className="card overflow-hidden p-2">
          {isLoading && <div className="space-y-2 p-3">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-14" />)}</div>}
          <ul className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
            {data?.data.map((t) => (
              <li key={t.id} className="group flex items-center gap-4 px-4 py-3.5 transition hover:bg-brand-50/30 dark:hover:bg-white/[0.02]">
                <button onClick={() => run(toggle(t.id), t.completed_at ? 'Task reopened' : '✓ Task completed')} disabled={!write} aria-label="Toggle">
                  {t.completed_at ? <CheckCircle2 className="size-6 text-emerald-500" /> : <Circle className="size-6 text-slate-300 transition hover:scale-110 hover:text-brand-500" />}
                </button>
                <span className="h-9 w-1 rounded-full" style={{ backgroundColor: typeColor[t.type] ?? '#64748b' }} />
                <div className="min-w-0 flex-1">
                  <p className={clsx('truncate font-medium', t.completed_at ? 'text-slate-400 line-through' : 'text-slate-900 dark:text-white')}>{t.title}</p>
                  <p className="flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                    <span className={clsx(t.is_overdue && 'font-semibold text-rose-600')}>{friendlyDue(t.due_at)}</span>
                    <span>· {humanize(t.type)}</span>
                    {t.taskable && <>· <Link to={`/${t.taskable.type}s/${t.taskable.id}`} className="text-brand-600 hover:underline">{t.taskable.name}</Link></>}
                    {t.description?.startsWith('Step') && <span className="inline-flex items-center gap-1 text-cyan-600"><Repeat className="size-3" />sequence</span>}
                  </p>
                </div>
                <PriorityBadge priority={t.priority} />
                {t.assignee && <Avatar name={t.assignee.name} color={t.assignee.avatar_color} size="sm" />}
                {write && (
                  <div className="flex gap-1 opacity-0 transition group-hover:opacity-100">
                    <button onClick={() => setEditing(t)} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label="Edit"><Pencil className="size-4" /></button>
                    <button onClick={() => run(remove(t.id), 'Task deleted')} className="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label="Delete"><Trash2 className="size-4" /></button>
                  </div>
                )}
              </li>
            ))}
          </ul>
          {data && !data.data.length && <EmptyState icon={<CheckCircle2 />} title={view === 'overdue' ? 'Nothing overdue 🎉' : 'No tasks here'} description="Enjoy the calm — or plan your next move." />}
          {data && <Pagination meta={data} onPage={setPage} />}
        </div>
      )}

      <TaskFormModal open={showForm || !!editing} task={editing} onClose={() => { setShowForm(false); setEditing(null) }} />
    </div>
  )
}

function TaskCalendar({ assignee, onEdit }: { assignee: string; onEdit: (t: Task) => void }) {
  const [month, setMonth] = useState(() => startOfMonth(new Date()))
  const from = startOfWeek(startOfMonth(month), { weekStartsOn: 1 })
  const to = endOfWeek(endOfMonth(month), { weekStartsOn: 1 })
  const { data } = useTasksQuery({ view: 'all', assignee, from: from.toISOString(), to: to.toISOString(), per_page: 100 })
  const days = useMemo(() => eachDayOfInterval({ start: from, end: to }), [from.getTime(), to.getTime()]) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div className="card overflow-hidden">
      <div className="flex items-center justify-between px-5 py-4">
        <h2 className="text-xl font-bold text-slate-900 dark:text-white">{format(month, 'MMMM yyyy')}</h2>
        <div className="flex gap-1">
          <Button size="sm" variant="ghost" onClick={() => setMonth((m) => addMonths(m, -1))} aria-label="Previous month"><ChevronLeft className="size-4" /></Button>
          <Button size="sm" variant="secondary" onClick={() => setMonth(startOfMonth(new Date()))}>Today</Button>
          <Button size="sm" variant="ghost" onClick={() => setMonth((m) => addMonths(m, 1))} aria-label="Next month"><ChevronRight className="size-4" /></Button>
        </div>
      </div>
      <div className="grid grid-cols-7 border-t border-slate-200/60 text-center text-[11px] font-semibold tracking-wider text-slate-400 uppercase dark:border-white/[0.06]">
        {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => <div key={d} className="py-2">{d}</div>)}
      </div>
      <div className="grid grid-cols-7">
        {days.map((day) => {
          const items = data?.data.filter((t) => t.due_at && isSameDay(parseISO(t.due_at), day)) ?? []
          return (
            <div key={day.toISOString()} className={clsx('min-h-28 border-t border-r border-slate-200/50 p-1.5 dark:border-white/[0.05]', !isSameMonth(day, month) && 'bg-slate-900/[0.02] opacity-50 dark:bg-white/[0.01]')}>
              <span className={clsx('mb-1 inline-flex size-6 items-center justify-center rounded-full text-xs font-medium', isToday(day) ? 'bg-gradient-to-br from-brand-500 to-fuchsia-500 text-white shadow-lg shadow-fuchsia-500/40' : 'text-slate-500')}>{format(day, 'd')}</span>
              <div className="space-y-1">
                {items.slice(0, 3).map((t) => (
                  <button key={t.id} onClick={() => onEdit(t)} title={t.title}
                    className={clsx('block w-full truncate rounded-lg px-1.5 py-0.5 text-left text-[11px] font-medium transition hover:brightness-110', t.completed_at && 'line-through opacity-50')}
                    style={{ backgroundColor: `${typeColor[t.type] ?? '#64748b'}22`, color: typeColor[t.type] ?? '#64748b' }}>
                    {t.due_at && format(parseISO(t.due_at), 'HH:mm')} {t.title}
                  </button>
                ))}
                {items.length > 3 && <p className="px-1.5 text-[10px] text-slate-400">+{items.length - 3} more</p>}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
