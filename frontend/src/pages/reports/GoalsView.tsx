import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import clsx from 'clsx'
import { CheckCircle2, Clock, Flag, Pencil, Plus, Target, Trash2, TrendingUp, TriangleAlert, Users } from 'lucide-react'
import { useAction, useAppSelector, usePermissions } from '@/app/hooks'
import { resources, useDeleteGoalMutation, useGoalsQuery, useReportCatalogQuery, useSaveGoalMutation, useSettings } from '@/services/api'
import { Avatar, Button, ConfirmDialog, EmptyState, Field, Input, Modal, PageLoader, Select } from '@/components/ui'
import { formatValue } from '@/components/reports/ReportChart'
import type { Goal } from '@/types'

const STATUS = {
  achieved: { label: 'Achieved', icon: CheckCircle2, className: 'text-emerald-700 bg-emerald-500/10 dark:text-emerald-300', bar: 'from-emerald-500 to-teal-400' },
  on_track: { label: 'On track', icon: TrendingUp, className: 'text-sky-700 bg-sky-500/10 dark:text-sky-300', bar: 'from-brand-500 to-fuchsia-500' },
  behind: { label: 'Behind pace', icon: TriangleAlert, className: 'text-amber-700 bg-amber-500/10 dark:text-amber-300', bar: 'from-amber-500 to-orange-400' },
} as const

export function GoalCard({ goal, onEdit, onDelete, compact }: { goal: Goal; onEdit?: () => void; onDelete?: () => void; compact?: boolean }) {
  const currency = useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
  const s = STATUS[goal.status]
  const width = Math.min(goal.percent, 100)
  return (
    <div className={clsx('group', compact ? 'py-3' : 'card p-5')}>
      <div className="flex items-start gap-3">
        {goal.user ? <Avatar name={goal.user.name} color={goal.user.avatar_color} size="sm" /> : <span className="flex size-8 items-center justify-center rounded-full bg-brand-500/10 text-brand-600"><Users className="size-4" /></span>}
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-slate-900 dark:text-white">{goal.metric_label}</p>
          <p className="text-xs text-slate-500">{goal.user?.name ?? 'Whole team'} · {goal.period_label}</p>
        </div>
        {(onEdit || onDelete) && (
          <div className="flex opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
            {onEdit && <button onClick={onEdit} className="rounded-lg p-1 text-slate-400 hover:text-brand-600" aria-label={`Edit goal ${goal.metric_label}`}><Pencil className="size-3.5" /></button>}
            {onDelete && <button onClick={onDelete} className="rounded-lg p-1 text-slate-400 hover:text-rose-600" aria-label={`Delete goal ${goal.metric_label}`}><Trash2 className="size-3.5" /></button>}
          </div>
        )}
      </div>
      <div className="mt-3">
        <div className="relative h-2.5 rounded-full bg-slate-900/[0.06] dark:bg-white/[0.07]" role="progressbar" aria-valuenow={Math.round(goal.percent)} aria-valuemin={0} aria-valuemax={100} aria-label={`${goal.metric_label} progress`}>
          <div className={clsx('h-full rounded-full bg-gradient-to-r transition-all duration-700', s.bar)} style={{ width: `${Math.max(width, 2)}%` }} />
          {goal.status !== 'achieved' && goal.expected_percent > 0 && goal.expected_percent < 100 && (
            <span className="absolute -top-1 h-4.5 w-0.5 rounded bg-slate-500/70" style={{ left: `${goal.expected_percent}%` }} title={`Expected by now: ${goal.expected_percent}%`} />
          )}
        </div>
        <div className="mt-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs">
          <span><span className="font-display text-base font-bold text-slate-900 dark:text-white">{formatValue(goal.actual, goal.format, currency, true)}</span><span className="text-slate-500"> of {formatValue(goal.target, goal.format, currency, true)} · {Math.round(goal.percent)}%</span></span>
          <span className="flex items-center gap-2">
            <span className={clsx('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold', s.className)}><s.icon className="size-3.5" aria-hidden />{s.label}</span>
            <span className="flex items-center gap-1 text-slate-500"><Clock className="size-3" />{goal.days_left}d left</span>
          </span>
        </div>
      </div>
    </div>
  )
}

function GoalModal({ goal, onClose }: { goal: Partial<Goal> | null; onClose: () => void }) {
  const run = useAction()
  const { data: catalog } = useReportCatalogQuery()
  const { data: users } = useSettings(resources.users)
  const [save, state] = useSaveGoalMutation()
  const [form, setForm] = useState({ user_id: '', metric: 'revenue_won', period: 'month', target: '' })

  useEffect(() => {
    if (goal) setForm({ user_id: goal.user_id ? String(goal.user_id) : '', metric: goal.metric ?? 'revenue_won', period: goal.period ?? 'month', target: goal.target ? String(goal.target) : '' })
  }, [goal])

  const submit = async () => {
    const body = { id: goal?.id, user_id: form.user_id ? Number(form.user_id) : null, metric: form.metric, period: form.period, target: Number(form.target) }
    if (await run(save(body), 'Goal saved')) onClose()
  }

  return (
    <Modal open={!!goal} onClose={onClose} title={goal?.id ? 'Edit goal' : 'New goal'} description="Progress is measured automatically from deals, leads, activities, tasks and AI calls."
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} disabled={!(Number(form.target) > 0)} loading={state.isLoading}>Save goal</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Measure" className="sm:col-span-2">
          <Select value={form.metric} onChange={(e) => setForm((f) => ({ ...f, metric: e.target.value }))}>{catalog?.goal_metrics.map((m) => <option key={m.key} value={m.key}>{m.label}</option>)}</Select>
        </Field>
        <Field label="For">
          <Select value={form.user_id} onChange={(e) => setForm((f) => ({ ...f, user_id: e.target.value }))}>
            <option value="">Whole team</option>
            {users?.filter((u) => u.role !== 'viewer').map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </Select>
        </Field>
        <Field label="Period">
          <Select value={form.period} onChange={(e) => setForm((f) => ({ ...f, period: e.target.value }))}><option value="month">Every month</option><option value="quarter">Every quarter</option></Select>
        </Field>
        <Field label="Target" required className="sm:col-span-2"><Input type="number" min={0} value={form.target} onChange={(e) => setForm((f) => ({ ...f, target: e.target.value }))} placeholder="e.g. 50000" /></Field>
      </div>
    </Modal>
  )
}

export function GoalsView() {
  const run = useAction()
  const { manager } = usePermissions()
  const { data, isLoading } = useGoalsQuery()
  const [remove] = useDeleteGoalMutation()
  const [editing, setEditing] = useState<Partial<Goal> | null>(null)
  const [deleting, setDeleting] = useState<Goal | null>(null)
  const counts = { achieved: 0, on_track: 0, behind: 0, ...Object.fromEntries(['achieved', 'on_track', 'behind'].map((k) => [k, data?.filter((g) => g.status === k).length ?? 0])) }

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2 text-xs">
          {(Object.keys(STATUS) as (keyof typeof STATUS)[]).map((k) => {
            const s = STATUS[k]
            return <span key={k} className={clsx('inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-semibold', s.className)}><s.icon className="size-3.5" aria-hidden />{counts[k]} {s.label.toLowerCase()}</span>
          })}
          <span className="inline-flex items-center gap-1 text-slate-500"><span className="h-3 w-0.5 rounded bg-slate-500/70" /> marker = where you should be by today</span>
        </div>
        {manager && <Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>New goal</Button>}
      </div>
      {isLoading ? <PageLoader /> : !data?.length ? (
        <div className="card"><EmptyState icon={<Target />} title="No goals yet" description={manager ? 'Set monthly or quarterly targets for the team or each person.' : 'Your manager hasn’t set any goals yet.'} /></div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {data.map((g) => <GoalCard key={g.id} goal={g} onEdit={manager ? () => setEditing(g) : undefined} onDelete={manager ? () => setDeleting(g) : undefined} />)}
        </div>
      )}
      {manager && <GoalModal goal={editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} title="Delete this goal?" message="Past progress isn’t stored, so this can’t be undone."
        onConfirm={async () => { if (deleting) await run(remove(deleting.id), 'Goal deleted'); setDeleting(null) }} />
    </div>
  )
}

/** Compact goals list for the dashboard: my goals and the team's. */
export function GoalsWidget() {
  const { data } = useGoalsQuery({ mine: true })
  if (!data?.length) return null
  return (
    <div className="card p-5">
      <div className="mb-1 flex items-center justify-between">
        <h3 className="flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><Flag className="size-4 text-brand-500" />Goals</h3>
        <Link to="/reports?tab=goals" className="text-xs font-medium text-brand-600 hover:underline">All goals →</Link>
      </div>
      <div className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
        {data.slice(0, 4).map((g) => <GoalCard key={g.id} goal={g} compact />)}
      </div>
    </div>
  )
}
