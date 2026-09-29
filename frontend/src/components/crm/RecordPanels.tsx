import { useState } from 'react'
import clsx from 'clsx'
import { CheckCircle2, Circle, Pin, PinOff, Plus, Trash2 } from 'lucide-react'
import { useAction, useCurrentUser, usePermissions } from '@/app/hooks'
import {
  useActivitiesQuery, useAddNoteMutation, useDeleteActivityMutation, useDeleteNoteMutation, useNotesQuery, useTasksQuery,
  useToggleTaskMutation, useUpdateNoteMutation, type SubjectType,
} from '@/services/api'
import { Avatar, Badge, Button, Card, EmptyState, Tabs, Textarea } from '@/components/ui'
import { PriorityBadge } from './Badges'
import { Timeline } from './Timeline'
import { TaskFormModal } from './TaskFormModal'
import { ago, dateTime, friendlyDue, humanize } from '@/lib/format'

const SINGULAR: Record<SubjectType, 'lead' | 'deal' | 'contact' | 'account'> = { leads: 'lead', deals: 'deal', contacts: 'contact', accounts: 'account' }

/** Timeline · Notes · Tasks for any CRM record (deal, contact, account). */
export function RecordPanels({ type, id, name }: { type: SubjectType; id: number; name: string }) {
  const [tab, setTab] = useState<'timeline' | 'notes' | 'tasks'>('timeline')
  const { data: notes } = useNotesQuery({ type, id })
  const { data: tasks } = useTasksQuery({ taskable_type: SINGULAR[type], taskable_id: id, view: 'open', per_page: 1 })

  return (
    <div className="space-y-4">
      <Tabs value={tab} onChange={setTab} className="w-fit" tabs={[
        { value: 'timeline', label: 'Timeline' },
        { value: 'notes', label: 'Notes', count: notes?.length ?? 0 },
        { value: 'tasks', label: 'Open tasks', count: tasks?.total ?? 0 },
      ]} />
      <div className="animate-fade-in">
        {tab === 'timeline' && <TimelinePanel type={type} id={id} />}
        {tab === 'notes' && <NotesPanel type={type} id={id} />}
        {tab === 'tasks' && <TasksPanel type={type} id={id} name={name} />}
      </div>
    </div>
  )
}

function TimelinePanel({ type, id }: { type: SubjectType; id: number }) {
  const { write } = usePermissions()
  const { data } = useActivitiesQuery({ type, id, per_page: 50 })
  const [remove] = useDeleteActivityMutation()
  return <Card>{data?.data.length ? <Timeline items={data.data} onDelete={write ? (a) => remove(a.id) : undefined} /> : <EmptyState title="No activity yet" />}</Card>
}

export function NotesPanel({ type, id }: { type: SubjectType; id: number }) {
  const run = useAction()
  const me = useCurrentUser()
  const { write, manager } = usePermissions()
  const { data } = useNotesQuery({ type, id })
  const [add, { isLoading }] = useAddNoteMutation()
  const [update] = useUpdateNoteMutation()
  const [remove] = useDeleteNoteMutation()
  const [body, setBody] = useState('')
  const save = async () => { if (body.trim() && (await run(add({ type, id, body }), 'Note added'))) setBody('') }

  return (
    <div className="space-y-4">
      {write && (
        <div className="card p-4">
          <Textarea rows={3} placeholder="Write a note… (Ctrl/⌘ + Enter to save)" value={body} onChange={(e) => setBody(e.target.value)}
            onKeyDown={(e) => { if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') save() }} />
          <div className="mt-2 flex justify-end"><Button size="sm" disabled={!body.trim()} loading={isLoading} onClick={save}>Add note</Button></div>
        </div>
      )}
      {data?.map((n) => (
        <article key={n.id} className={clsx('card p-5', n.is_pinned && 'gradient-border')}>
          <div className="flex items-center gap-2">
            <Avatar name={n.user?.name} color={n.user?.avatar_color} size="sm" />
            <span className="text-sm font-medium text-slate-900 dark:text-white">{n.user?.name ?? 'System'}</span>
            <span className="text-xs text-slate-400" title={dateTime(n.created_at)}>{ago(n.created_at)}</span>
            {n.is_pinned && <Badge color="#d946ef"><Pin className="size-3" /> Pinned</Badge>}
            {write && (
              <div className="ml-auto flex gap-1">
                <button onClick={() => update({ id: n.id, is_pinned: !n.is_pinned })} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label={n.is_pinned ? 'Unpin' : 'Pin'}>{n.is_pinned ? <PinOff className="size-4" /> : <Pin className="size-4" />}</button>
                {(n.user_id === me?.id || manager) && <button onClick={() => remove(n.id)} className="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label="Delete note"><Trash2 className="size-4" /></button>}
              </div>
            )}
          </div>
          <p className="mt-3 text-sm whitespace-pre-line text-slate-700 dark:text-slate-300">{n.body}</p>
        </article>
      ))}
      {!data?.length && <div className="card"><EmptyState title="No notes yet" description="Capture context your team should know." /></div>}
    </div>
  )
}

export function TasksPanel({ type, id, name }: { type: SubjectType; id: number; name: string }) {
  const { write } = usePermissions()
  const { data } = useTasksQuery({ taskable_type: SINGULAR[type], taskable_id: id, view: 'all', per_page: 100 })
  const [toggle] = useToggleTaskMutation()
  const [open, setOpen] = useState(false)

  return (
    <div className="card p-2">
      {write && <div className="flex justify-end p-3"><Button size="sm" variant="subtle" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>New task</Button></div>}
      <ul className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
        {data?.data.map((t) => (
          <li key={t.id} className="flex items-center gap-3 px-4 py-3">
            <button onClick={() => toggle(t.id)} disabled={!write} aria-label={t.completed_at ? 'Reopen task' : 'Complete task'}>
              {t.completed_at ? <CheckCircle2 className="size-5 text-emerald-500" /> : <Circle className="size-5 text-slate-300 hover:text-brand-500" />}
            </button>
            <div className="min-w-0 flex-1">
              <p className={clsx('truncate text-sm font-medium', t.completed_at ? 'text-slate-400 line-through' : 'text-slate-900 dark:text-white')}>{t.title}</p>
              <p className={clsx('text-xs', t.is_overdue ? 'font-medium text-rose-600' : 'text-slate-500')}>{humanize(t.type)} · {friendlyDue(t.due_at)}{t.assignee && ` · ${t.assignee.name}`}</p>
            </div>
            <PriorityBadge priority={t.priority} />
          </li>
        ))}
      </ul>
      {!data?.data.length && <EmptyState title="No tasks" description="Plan the next step so nothing slips." />}
      <TaskFormModal open={open} onClose={() => setOpen(false)} subject={{ type: SINGULAR[type], id, name }} />
    </div>
  )
}
