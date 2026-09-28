import { useEffect, useState } from 'react'
import clsx from 'clsx'
import { ArrowDown, Check, Copy, ExternalLink, History, KeyRound, Pencil, Play, Plus, RefreshCw, Send, Trash2, Webhook as WebhookIcon, Zap } from 'lucide-react'
import { useAction, useToast } from '@/app/hooks'
import {
  API_URL, resources, useApiKeysQuery, useAutomationExecutionsQuery, useCreateApiKeyMutation, useDeleteSettingMutation, useMetaQuery,
  useRecalculateScoresMutation, useRevokeApiKeyMutation, useSaveSettingMutation, useSettings, useTestWebhookMutation,
} from '@/services/api'
import { Avatar, Badge, Button, Card, ColorPicker, ConfirmDialog, EmptyState, Field, Input, Modal, Select, Textarea, Toggle } from '@/components/ui'
import { OPERATOR_LABELS } from '@/lib/constants'
import { ago, humanize } from '@/lib/format'
import type { AssignmentRule, AutomationAction, AutomationRule, Condition, ScoringRule, WebForm, WebFormField } from '@/types'
import { ConditionBuilder, SectionHeader } from './shared'

function describe(conditions: Condition[] | null) {
  if (!conditions?.length) return 'every lead'
  return conditions.map((c) => `${humanize(c.field)} ${OPERATOR_LABELS[c.operator] ?? c.operator}${c.value ? ` “${c.value}”` : ''}`).join(' and ')
}

// ---------------------------------------------------------------- Assignment
export function AssignmentRules() {
  const run = useAction()
  const { data: meta } = useMetaQuery()
  const { data } = useSettings(resources.assignmentRules)
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [editing, setEditing] = useState<Partial<AssignmentRule> | null>(null)
  const [form, setForm] = useState<{ name: string; strategy: string; team_id: string; user_ids: number[]; conditions: Condition[]; priority: number; is_active: boolean }>({ name: '', strategy: 'round_robin', team_id: '', user_ids: [], conditions: [], priority: 10, is_active: true })

  useEffect(() => { if (editing) setForm({ name: editing.name ?? '', strategy: editing.strategy ?? 'round_robin', team_id: String(editing.team_id ?? ''), user_ids: editing.user_ids ?? [], conditions: editing.conditions ?? [], priority: editing.priority ?? 10, is_active: editing.is_active ?? true }) }, [editing])

  const strategies = [
    { value: 'round_robin', label: 'Round robin', text: 'Rotate evenly through the pool.' },
    { value: 'least_loaded', label: 'Least loaded', text: 'Owner with the fewest open leads.' },
    { value: 'specific_user', label: 'Specific person', text: 'Always the first selected user.' },
  ]

  return (
    <>
      <SectionHeader title="Assignment rules" description="New leads without an owner are routed by the first matching rule (lowest priority number first). No match → unassigned queue, where reps can claim them."
        action={<Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>New rule</Button>} />
      <div className="space-y-3">
        {data?.map((r, i) => (
          <div key={r.id} className={clsx('card flex items-center gap-4 p-5', !r.is_active && 'opacity-60')}>
            <span className="flex size-10 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-fuchsia-500 font-display font-bold text-white">{i + 1}</span>
            <div className="min-w-0 flex-1">
              <p className="font-semibold text-slate-900 dark:text-white">{r.name}</p>
              <p className="text-sm text-slate-500">When <span className="text-slate-700 dark:text-slate-300">{describe(r.conditions)}</span> → {humanize(r.strategy)} across {r.team ? `team ${r.team.name}` : `${r.user_ids?.length ?? 0} people`}</p>
            </div>
            <Badge color={r.is_active ? '#10b981' : '#94a3b8'}>{r.is_active ? 'On' : 'Off'}</Badge>
            <button onClick={() => setEditing(r)} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label="Edit"><Pencil className="size-4" /></button>
            <button onClick={() => run(remove({ ...resources.assignmentRules, id: r.id }), 'Rule deleted')} className="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label="Delete"><Trash2 className="size-4" /></button>
          </div>
        ))}
        {!data?.length && <div className="card"><EmptyState icon={<Zap />} title="No assignment rules" description="Every new lead lands in the unassigned queue." /></div>}
      </div>
      <Modal open={!!editing} onClose={() => setEditing(null)} size="xl" title={editing?.id ? 'Edit assignment rule' : 'New assignment rule'}
        footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button disabled={!form.name} loading={saveState.isLoading}
          onClick={async () => { if (await run(save({ ...resources.assignmentRules, id: editing?.id, body: { ...form, team_id: form.team_id ? Number(form.team_id) : null, user_ids: form.user_ids.length ? form.user_ids : null } }), 'Rule saved')) setEditing(null) }}>Save rule</Button></>}>
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-[1fr_120px]">
            <Field label="Rule name" required><Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="e.g. India enterprise leads" /></Field>
            <Field label="Priority"><Input type="number" min={0} value={form.priority} onChange={(e) => setForm((f) => ({ ...f, priority: Number(e.target.value) }))} /></Field>
          </div>
          <Field label="When a new lead matches"><ConditionBuilder value={form.conditions} onChange={(c) => setForm((f) => ({ ...f, conditions: c }))} /></Field>
          <ArrowDown className="mx-auto size-5 text-brand-400" />
          <Field label="Assign using">
            <div className="grid gap-2 sm:grid-cols-3">{strategies.map((s) => (
              <button key={s.value} type="button" onClick={() => setForm((f) => ({ ...f, strategy: s.value }))}
                className={clsx('rounded-2xl border p-3 text-left transition', form.strategy === s.value ? 'border-brand-400 bg-brand-50 shadow-[0_8px_24px_-12px_rgba(139,92,246,0.6)] dark:bg-brand-500/10' : 'border-slate-200 dark:border-white/10')}>
                <p className="text-sm font-semibold">{s.label}</p><p className="text-xs text-slate-500">{s.text}</p>
              </button>
            ))}</div>
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Team pool" hint="Used when no people are selected."><Select value={form.team_id} onChange={(e) => setForm((f) => ({ ...f, team_id: e.target.value }))} placeholder="—">{meta?.teams.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</Select></Field>
            <Field label="…or specific people">
              <div className="flex flex-wrap gap-1.5">{meta?.users.filter((u) => u.role !== 'viewer').map((u) => {
                const on = form.user_ids.includes(u.id)
                return <button key={u.id} type="button" onClick={() => setForm((f) => ({ ...f, user_ids: on ? f.user_ids.filter((x) => x !== u.id) : [...f.user_ids, u.id] }))}
                  className={clsx('chip transition', on && 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}><Avatar name={u.name} color={u.avatar_color} size="xs" />{u.name}</button>
              })}</div>
            </Field>
          </div>
          <Toggle checked={form.is_active} onChange={(v) => setForm((f) => ({ ...f, is_active: v }))} label="Rule active" />
        </div>
      </Modal>
    </>
  )
}

// ---------------------------------------------------------------- Scoring
export function ScoringRules() {
  const run = useAction()
  const { data: meta } = useMetaQuery()
  const { data } = useSettings(resources.scoringRules)
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [recalc, recalcState] = useRecalculateScoresMutation()
  const [editing, setEditing] = useState<Partial<ScoringRule> | null>(null)
  const [cond, setCond] = useState<Condition[]>([])
  const [name, setName] = useState('')
  const [points, setPoints] = useState(10)
  const [active, setActive] = useState(true)

  useEffect(() => {
    if (!editing) return
    setName(editing.name ?? ''); setPoints(editing.points ?? 10); setActive(editing.is_active ?? true)
    setCond([{ field: editing.field ?? meta?.enums.condition_fields[0] ?? 'email', operator: editing.operator ?? 'is_not_empty', value: editing.value ?? '' }])
  }, [editing, meta])

  const total = data?.filter((r) => r.is_active && r.points > 0).reduce((s, r) => s + r.points, 0) ?? 0

  return (
    <>
      <SectionHeader title="Lead scoring" description="Each matching rule adds (or removes) points, capped 0–100. 0–30 cold · 31–60 warm · 61–80 hot · 81+ very high intent. Every point is stored as an explainable score event."
        action={<div className="flex gap-2"><Button size="sm" variant="secondary" icon={<RefreshCw className="size-4" />} loading={recalcState.isLoading} onClick={() => run(recalc(), 'Scores recalculated')}>Re-score leads</Button><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>New rule</Button></div>} />
      <Card padded={false}>
        <ul className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
          {data?.map((r) => (
            <li key={r.id} className={clsx('group flex items-center gap-4 px-5 py-3.5', !r.is_active && 'opacity-50')}>
              <span className={clsx('font-display w-14 text-lg font-bold tabular-nums', r.points >= 0 ? 'text-emerald-600' : 'text-rose-600')}>{r.points > 0 ? '+' : ''}{r.points}</span>
              <div className="min-w-0 flex-1">
                <p className="font-medium text-slate-900 dark:text-white">{r.name}</p>
                <p className="text-xs text-slate-500">{humanize(r.field)} {OPERATOR_LABELS[r.operator] ?? r.operator} {r.value && `“${r.value}”`}</p>
              </div>
              <button onClick={() => setEditing(r)} className="rounded-lg p-1.5 text-slate-400 opacity-0 group-hover:opacity-100 hover:text-brand-600" aria-label="Edit"><Pencil className="size-4" /></button>
              <button onClick={() => run(remove({ ...resources.scoringRules, id: r.id }), 'Rule deleted')} className="rounded-lg p-1.5 text-slate-400 opacity-0 group-hover:opacity-100 hover:text-rose-600" aria-label="Delete"><Trash2 className="size-4" /></button>
            </li>
          ))}
        </ul>
        <p className="border-t border-slate-200/60 px-5 py-3 text-xs text-slate-500 dark:border-white/[0.06]">Maximum achievable from positive rules: <span className="font-semibold">{total}</span> points</p>
      </Card>
      <Modal open={!!editing} onClose={() => setEditing(null)} size="lg" title={editing?.id ? 'Edit scoring rule' : 'New scoring rule'}
        footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button disabled={!name || !cond[0]} loading={saveState.isLoading}
          onClick={async () => { if (await run(save({ ...resources.scoringRules, id: editing?.id, body: { name, points, is_active: active, field: cond[0].field, operator: cond[0].operator, value: cond[0].value || null } }), 'Rule saved')) setEditing(null) }}>Save</Button></>}>
        <div className="space-y-4">
          <Field label="Name" required><Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Requested demo" /></Field>
          <Field label="Condition"><ConditionBuilder value={cond.slice(0, 1)} onChange={(c) => setCond(c.slice(-1))} emptyLabel="Add one condition." /></Field>
          <Field label={`Points: ${points > 0 ? '+' : ''}${points}`}><input type="range" min={-50} max={50} value={points} onChange={(e) => setPoints(Number(e.target.value))} className="w-full accent-brand-600" /></Field>
          <Toggle checked={active} onChange={setActive} label="Active" />
        </div>
      </Modal>
    </>
  )
}

// ---------------------------------------------------------------- Automations
const ACTION_HELP: Record<string, string> = {
  create_task: 'Create a task', notify_owner: 'Notify the owner', notify_user: 'Notify a person', update_field: 'Update a field',
  add_tag: 'Add a tag', change_status: 'Change status', assign_user: 'Assign to a person', add_note: 'Add a note',
}

function ActionEditor({ action, onChange }: { action: AutomationAction; onChange: (a: AutomationAction) => void }) {
  const { data: meta } = useMetaQuery()
  const p = action.params ?? {}
  const set = (k: string, v: string | number | null) => onChange({ ...action, params: { ...p, [k]: v } })
  const input = (k: string, label: string, placeholder = '') => <Field label={label}><Input value={String(p[k] ?? '')} onChange={(e) => set(k, e.target.value)} placeholder={placeholder} /></Field>

  switch (action.type) {
    case 'create_task':
      return <div className="grid gap-3 sm:grid-cols-3">{input('title', 'Task title', 'Call {name}')}<Field label="Type"><Select value={String(p.task_type ?? 'follow_up')} onChange={(e) => set('task_type', e.target.value)}>{meta?.enums.task_types.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</Select></Field><Field label="Due in (hours)"><Input type="number" value={String(p.due_in_hours ?? 24)} onChange={(e) => set('due_in_hours', Number(e.target.value))} /></Field></div>
    case 'notify_owner':
      return <div className="grid gap-3 sm:grid-cols-2">{input('title', 'Title')}{input('message', 'Message', '{name} from {company}…')}</div>
    case 'notify_user':
    case 'assign_user':
      return <div className="grid gap-3 sm:grid-cols-2"><Field label="Person"><Select value={String(p.user_id ?? '')} onChange={(e) => set('user_id', Number(e.target.value))} placeholder="Choose…">{meta?.users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</Select></Field>{action.type === 'notify_user' && input('message', 'Message')}</div>
    case 'update_field':
      return <div className="grid gap-3 sm:grid-cols-2"><Field label="Field"><Select value={String(p.field ?? 'priority')} onChange={(e) => set('field', e.target.value)}>{['priority', 'rating', 'timeline', 'industry', 'lost_reason'].map((f) => <option key={f} value={f}>{humanize(f)}</option>)}</Select></Field>{input('value', 'Value', 'high')}</div>
    case 'add_tag':
      return <Field label="Tag"><Select value={String(p.tag ?? '')} onChange={(e) => set('tag', e.target.value)} placeholder="Choose…">{meta?.tags.map((t) => <option key={t.id} value={t.name}>{t.name}</option>)}</Select></Field>
    case 'change_status':
      return <Field label="Status"><Select value={String(p.status_key ?? '')} onChange={(e) => set('status_key', e.target.value)} placeholder="Choose…">{meta?.statuses.map((s) => <option key={s.id} value={s.key}>{s.name}</option>)}</Select></Field>
    case 'add_note':
      return <Field label="Note"><Textarea rows={2} value={String(p.body ?? '')} onChange={(e) => set('body', e.target.value)} /></Field>
  }
  return null
}

export function Automations() {
  const run = useAction()
  const { data: meta } = useMetaQuery()
  const { data } = useSettings(resources.automationRules)
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [editing, setEditing] = useState<Partial<AutomationRule> | null>(null)
  const [logFor, setLogFor] = useState<AutomationRule | null>(null)
  const [form, setForm] = useState<{ name: string; description: string; trigger: string; conditions: Condition[]; actions: AutomationAction[]; is_active: boolean }>({ name: '', description: '', trigger: 'lead.created', conditions: [], actions: [], is_active: true })
  const { data: executions } = useAutomationExecutionsQuery(logFor?.id ?? 0, { skip: !logFor })

  useEffect(() => { if (editing) setForm({ name: editing.name ?? '', description: editing.description ?? '', trigger: editing.trigger ?? 'lead.created', conditions: editing.conditions ?? [], actions: editing.actions ?? [{ type: 'create_task', params: { title: 'Follow up with {name}', due_in_hours: 24 } }], is_active: editing.is_active ?? true }) }, [editing])

  return (
    <>
      <SectionHeader title="Workflows" description="WHEN something happens → IF conditions match → THEN run actions. Placeholders like {name}, {company} or {status_key} are filled from the lead."
        action={<Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>New workflow</Button>} />
      <div className="grid gap-4 lg:grid-cols-2">
        {data?.map((r) => (
          <div key={r.id} className={clsx('card p-5', !r.is_active && 'opacity-60')}>
            <div className="flex items-start gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-400 to-pink-500 text-white shadow-lg shadow-pink-500/30"><Zap className="size-5" /></span>
              <div className="min-w-0 flex-1">
                <p className="font-semibold text-slate-900 dark:text-white">{r.name}</p>
                <p className="text-xs text-slate-500">{r.description}</p>
              </div>
              <Badge color={r.is_active ? '#10b981' : '#94a3b8'}>{r.is_active ? 'Live' : 'Off'}</Badge>
            </div>
            <div className="mt-4 space-y-1.5 text-sm">
              <p><span className="mr-2 text-[10px] font-bold tracking-wider text-brand-600 uppercase">When</span>{humanize(r.trigger)}</p>
              <p><span className="mr-2 text-[10px] font-bold tracking-wider text-fuchsia-600 uppercase">If</span>{describe(r.conditions)}</p>
              <p><span className="mr-2 text-[10px] font-bold tracking-wider text-cyan-600 uppercase">Then</span>{r.actions.map((a) => ACTION_HELP[a.type] ?? a.type).join(' → ')}</p>
            </div>
            <div className="mt-4 flex items-center justify-between text-xs text-slate-500">
              <span>Ran {r.run_count}× {r.last_run_at && `· last ${ago(r.last_run_at)}`}</span>
              <span className="flex gap-1">
                <button onClick={() => setLogFor(r)} className="rounded-lg p-1.5 hover:text-brand-600" aria-label="Execution log"><History className="size-4" /></button>
                <button onClick={() => setEditing(r)} className="rounded-lg p-1.5 hover:text-brand-600" aria-label="Edit"><Pencil className="size-4" /></button>
                <button onClick={() => run(remove({ ...resources.automationRules, id: r.id }), 'Workflow deleted')} className="rounded-lg p-1.5 hover:text-rose-600" aria-label="Delete"><Trash2 className="size-4" /></button>
              </span>
            </div>
          </div>
        ))}
      </div>

      <Modal open={!!editing} onClose={() => setEditing(null)} size="xl" title={editing?.id ? 'Edit workflow' : 'New workflow'}
        footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button disabled={!form.name || !form.actions.length} loading={saveState.isLoading}
          onClick={async () => { if (await run(save({ ...resources.automationRules, id: editing?.id, body: form }), 'Workflow saved')) setEditing(null) }}>Save workflow</Button></>}>
        <div className="space-y-5">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Name" required><Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} /></Field>
            <Field label="Description"><Input value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} /></Field>
          </div>
          <div className="rounded-3xl border border-brand-200/70 bg-brand-50/40 p-4 dark:border-brand-500/20 dark:bg-brand-500/5">
            <p className="mb-2 text-[11px] font-bold tracking-wider text-brand-600 uppercase">When</p>
            <Select value={form.trigger} onChange={(e) => setForm((f) => ({ ...f, trigger: e.target.value }))}>{meta?.enums.automation_triggers.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</Select>
          </div>
          <div className="rounded-3xl border border-fuchsia-200/70 bg-fuchsia-50/40 p-4 dark:border-fuchsia-500/20 dark:bg-fuchsia-500/5">
            <p className="mb-2 text-[11px] font-bold tracking-wider text-fuchsia-600 uppercase">If</p>
            <ConditionBuilder value={form.conditions} onChange={(c) => setForm((f) => ({ ...f, conditions: c }))} />
          </div>
          <div className="rounded-3xl border border-cyan-200/70 bg-cyan-50/40 p-4 dark:border-cyan-500/20 dark:bg-cyan-500/5">
            <p className="mb-2 text-[11px] font-bold tracking-wider text-cyan-700 uppercase">Then</p>
            <div className="space-y-3">
              {form.actions.map((a, i) => (
                <div key={i} className="rounded-2xl border border-slate-200/80 bg-white/80 p-3 dark:border-white/10 dark:bg-white/[0.03]">
                  <div className="mb-3 flex items-center gap-2">
                    <span className="font-display text-sm font-bold text-cyan-600">{i + 1}</span>
                    <Select className="flex-1" value={a.type} onChange={(e) => setForm((f) => ({ ...f, actions: f.actions.map((x, j) => (j === i ? { type: e.target.value, params: {} } : x)) }))}>
                      {meta?.enums.automation_actions.map((t) => <option key={t} value={t}>{ACTION_HELP[t] ?? t}</option>)}
                    </Select>
                    <button onClick={() => setForm((f) => ({ ...f, actions: f.actions.filter((_, j) => j !== i) }))} className="rounded-lg p-2 text-slate-400 hover:text-rose-600" aria-label="Remove action"><Trash2 className="size-4" /></button>
                  </div>
                  <ActionEditor action={a} onChange={(na) => setForm((f) => ({ ...f, actions: f.actions.map((x, j) => (j === i ? na : x)) }))} />
                </div>
              ))}
              <Button size="xs" variant="subtle" icon={<Plus className="size-3.5" />} onClick={() => setForm((f) => ({ ...f, actions: [...f.actions, { type: 'notify_owner', params: {} }] }))}>Add action</Button>
            </div>
          </div>
          <Toggle checked={form.is_active} onChange={(v) => setForm((f) => ({ ...f, is_active: v }))} label="Workflow live" />
        </div>
      </Modal>

      <Modal open={!!logFor} onClose={() => setLogFor(null)} size="lg" title={`Execution log — ${logFor?.name}`}>
        <ul className="space-y-2">
          {executions?.map((e) => (
            <li key={e.id} className="flex items-start gap-3 rounded-2xl border border-slate-200/70 p-3 text-sm dark:border-white/10">
              <Badge color={e.status === 'success' ? '#10b981' : '#ef4444'}>{e.status}</Badge>
              <div className="min-w-0 flex-1"><p className="font-medium">{e.lead ? `${e.lead.first_name} ${e.lead.last_name ?? ''}` : '—'}</p><p className="text-xs text-slate-500">{e.message}</p></div>
              <span className="text-xs text-slate-400">{ago(e.created_at)}</span>
            </li>
          ))}
          {!executions?.length && <EmptyState title="No runs yet" />}
        </ul>
      </Modal>
    </>
  )
}

// ---------------------------------------------------------------- Web forms
const DEFAULT_FIELDS: WebFormField[] = [
  { key: 'name', label: 'Full name', type: 'text', required: true },
  { key: 'email', label: 'Work email', type: 'email', required: true },
  { key: 'company', label: 'Company', type: 'text' },
]

export function WebForms() {
  const run = useAction()
  const toast = useToast()
  const { data: meta } = useMetaQuery()
  const { data } = useSettings(resources.webForms)
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [editing, setEditing] = useState<Partial<WebForm> | null>(null)
  const [embed, setEmbed] = useState<WebForm | null>(null)
  const [form, setForm] = useState<Partial<WebForm>>({})

  useEffect(() => { if (editing) setForm({ name: '', title: '', description: '', fields: DEFAULT_FIELDS, submit_label: 'Submit', success_message: 'Thanks! We will be in touch shortly.', accent_color: '#7c3aed', is_active: true, ...editing }) }, [editing])

  const origin = window.location.origin
  const patchField = (i: number, p: Partial<WebFormField>) => setForm((f) => ({ ...f, fields: f.fields?.map((x, j) => (j === i ? { ...x, ...p } : x)) }))
  const snippet = (w: WebForm) => `<iframe src="${origin}/f/${w.slug}" style="width:100%;min-height:640px;border:0" loading="lazy" title="${w.title ?? w.name}"></iframe>`

  return (
    <>
      <SectionHeader title="Web forms" description="Hosted web-to-lead forms. Share the link or embed on any site; submissions become leads with source, campaign and tags applied — then scoring, routing and workflows run."
        action={<Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>New form</Button>} />
      <div className="grid gap-4 md:grid-cols-2">
        {data?.map((w) => (
          <div key={w.id} className="card overflow-hidden p-0">
            <div className="h-2" style={{ background: `linear-gradient(90deg, ${w.accent_color}, #d946ef, #06b6d4)` }} />
            <div className="p-5">
              <div className="flex items-start justify-between gap-3">
                <div><p className="font-semibold text-slate-900 dark:text-white">{w.name}</p><p className="text-xs text-slate-500">{w.fields.length} fields · {w.source?.name ?? 'No source'}</p></div>
                <Badge color={w.is_active ? '#10b981' : '#94a3b8'}>{w.is_active ? 'Live' : 'Off'}</Badge>
              </div>
              <p className="font-display mt-4 text-3xl font-bold text-slate-900 dark:text-white">{w.submissions_count}<span className="ml-1 text-sm font-normal text-slate-500">submissions</span></p>
              <div className="mt-4 flex flex-wrap gap-2">
                <a href={`/f/${w.slug}`} target="_blank" rel="noreferrer"><Button size="xs" variant="secondary" icon={<ExternalLink className="size-3.5" />}>Open</Button></a>
                <Button size="xs" variant="secondary" icon={<Copy className="size-3.5" />} onClick={() => setEmbed(w)}>Embed</Button>
                <Button size="xs" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(w)}>Edit</Button>
                <Button size="xs" variant="ghost" icon={<Trash2 className="size-3.5" />} onClick={() => run(remove({ ...resources.webForms, id: w.id }), 'Form deleted')}>Delete</Button>
              </div>
            </div>
          </div>
        ))}
      </div>

      <Modal open={!!editing} onClose={() => setEditing(null)} size="xl" title={editing?.id ? 'Edit form' : 'New web form'}
        footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button disabled={!form.name} loading={saveState.isLoading}
          onClick={async () => { if (await run(save({ ...resources.webForms, id: editing?.id, body: form as Record<string, unknown> }), 'Form saved')) setEditing(null) }}>Save form</Button></>}>
        <div className="grid gap-6 lg:grid-cols-2">
          <div className="space-y-4">
            <Field label="Internal name" required><Input value={form.name ?? ''} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} /></Field>
            <Field label="Heading"><Input value={form.title ?? ''} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} /></Field>
            <Field label="Intro text"><Input value={form.description ?? ''} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} /></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Source"><Select value={String(form.lead_source_id ?? '')} onChange={(e) => setForm((f) => ({ ...f, lead_source_id: e.target.value ? Number(e.target.value) : null }))} placeholder="—">{meta?.sources.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select></Field>
              <Field label="Campaign"><Select value={String(form.campaign_id ?? '')} onChange={(e) => setForm((f) => ({ ...f, campaign_id: e.target.value ? Number(e.target.value) : null }))} placeholder="—">{meta?.campaigns.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</Select></Field>
            </div>
            <Field label="Fields">
              <div className="space-y-2">
                {form.fields?.map((fl, i) => (
                  <div key={i} className="flex items-center gap-2">
                    <Select className="w-32" value={fl.key} onChange={(e) => patchField(i, { key: e.target.value })}>{meta?.enums.form_field_keys.map((k) => <option key={k} value={k}>{k}</option>)}</Select>
                    <Input value={fl.label} onChange={(e) => patchField(i, { label: e.target.value })} />
                    <Select className="w-28" value={fl.type} onChange={(e) => patchField(i, { type: e.target.value as WebFormField['type'] })}>{['text', 'email', 'tel', 'number', 'textarea'].map((t) => <option key={t}>{t}</option>)}</Select>
                    <button type="button" title="Required" onClick={() => patchField(i, { required: !fl.required })} className={clsx('rounded-lg px-2 py-1 text-xs font-bold', fl.required ? 'bg-rose-100 text-rose-600 dark:bg-rose-500/20' : 'text-slate-400')}>*</button>
                    <button type="button" onClick={() => setForm((f) => ({ ...f, fields: f.fields?.filter((_, j) => j !== i) }))} className="p-1 text-slate-400 hover:text-rose-600" aria-label="Remove field"><Trash2 className="size-4" /></button>
                  </div>
                ))}
                <Button size="xs" variant="subtle" icon={<Plus className="size-3.5" />} onClick={() => setForm((f) => ({ ...f, fields: [...(f.fields ?? []), { key: 'phone', label: 'Phone', type: 'tel' }] }))}>Add field</Button>
              </div>
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Button label"><Input value={form.submit_label ?? ''} onChange={(e) => setForm((f) => ({ ...f, submit_label: e.target.value }))} /></Field>
              <Field label="Accent"><ColorPicker value={form.accent_color ?? '#7c3aed'} onChange={(c) => setForm((f) => ({ ...f, accent_color: c }))} /></Field>
            </div>
            <Field label="Success message"><Input value={form.success_message ?? ''} onChange={(e) => setForm((f) => ({ ...f, success_message: e.target.value }))} /></Field>
            <Toggle checked={!!form.is_active} onChange={(v) => setForm((f) => ({ ...f, is_active: v }))} label="Accept submissions" />
          </div>
          {/* live preview */}
          <div className="rounded-3xl bg-gradient-to-br from-slate-100 to-slate-200/60 p-5 dark:from-white/[0.03] dark:to-white/[0.01]">
            <p className="label">Live preview</p>
            <div className="rounded-2xl bg-white p-6 shadow-xl dark:bg-ink-900">
              <h3 className="text-xl font-bold text-slate-900 dark:text-white">{form.title || form.name || 'Your form'}</h3>
              {form.description && <p className="mt-1 text-sm text-slate-500">{form.description}</p>}
              <div className="mt-5 space-y-3">
                {form.fields?.map((fl, i) => (
                  <div key={i}><p className="mb-1 text-xs font-medium text-slate-600 dark:text-slate-300">{fl.label}{fl.required && ' *'}</p><div className={clsx('rounded-lg border border-slate-200 dark:border-white/10', fl.type === 'textarea' ? 'h-16' : 'h-9')} /></div>
                ))}
              </div>
              <div className="mt-5 flex h-10 items-center justify-center rounded-xl text-sm font-semibold text-white" style={{ background: form.accent_color }}>{form.submit_label || 'Submit'}</div>
            </div>
          </div>
        </div>
      </Modal>

      <Modal open={!!embed} onClose={() => setEmbed(null)} size="lg" title="Share & embed">
        {embed && (
          <div className="space-y-4">
            <Field label="Hosted link"><div className="flex gap-2"><Input readOnly value={`${origin}/f/${embed.slug}`} /><Button variant="secondary" onClick={() => { navigator.clipboard?.writeText(`${origin}/f/${embed.slug}`); toast('success', 'Link copied') }}><Copy className="size-4" /></Button></div></Field>
            <Field label="Embed code"><Textarea readOnly rows={3} className="font-mono text-xs" value={snippet(embed)} /></Field>
            <Button variant="secondary" icon={<Copy className="size-4" />} onClick={() => { navigator.clipboard?.writeText(snippet(embed)); toast('success', 'Embed code copied') }}>Copy embed code</Button>
            <Field label="Or post directly from your own form (JSON)"><Textarea readOnly rows={4} className="font-mono text-xs" value={`POST ${origin}${API_URL}/forms/${embed.slug}\nContent-Type: application/json\n\n{"name":"Jane Cooper","email":"jane@acme.com"}`} /></Field>
          </div>
        )}
      </Modal>
    </>
  )
}

// ---------------------------------------------------------------- API & webhooks
export function Integrations() {
  const run = useAction()
  const toast = useToast()
  const { data: meta } = useMetaQuery()
  const { data: keys } = useApiKeysQuery()
  const { data: hooks } = useSettings(resources.webhooks)
  const [createKey, createKeyState] = useCreateApiKeyMutation()
  const [revoke] = useRevokeApiKeyMutation()
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [testHook] = useTestWebhookMutation()
  const [keyName, setKeyName] = useState('')
  const [revealed, setRevealed] = useState<{ label: string; value: string } | null>(null)
  const [hookForm, setHookForm] = useState<{ open: boolean; name: string; url: string; events: string[] }>({ open: false, name: '', url: '', events: ['lead.created'] })
  const [revoking, setRevoking] = useState<number | null>(null)
  const origin = window.location.origin

  const curl = `curl -X POST ${origin}${API_URL}/capture/leads \\\n  -H "X-Api-Key: <your key>" -H "Content-Type: application/json" \\\n  -d '{"name":"Jane Cooper","email":"jane@acme.com","company":"Acme","utm_source":"google","utm_campaign":"Spring"}'`

  return (
    <>
      <SectionHeader title="API & webhooks" description="Push leads in from any system with an API key, and get signed webhook callbacks when things change." />
      <div className="space-y-6">
        <Card title={<span className="flex items-center gap-2"><KeyRound className="size-4 text-brand-500" /> API keys</span>}>
          <div className="flex gap-2">
            <Input placeholder="Key name, e.g. Website backend" value={keyName} onChange={(e) => setKeyName(e.target.value)} />
            <Button disabled={!keyName} loading={createKeyState.isLoading} onClick={async () => { const r = await run(createKey({ name: keyName })); if (r) { setRevealed({ label: 'API key', value: r.key }); setKeyName('') } }}>Create</Button>
          </div>
          <ul className="mt-4 divide-y divide-slate-200/60 dark:divide-white/[0.06]">
            {keys?.map((k) => (
              <li key={k.id} className="flex items-center gap-3 py-3 text-sm">
                <span className="font-mono text-xs text-slate-500">{k.prefix}…</span><span className="flex-1 font-medium">{k.name}</span>
                <span className="text-xs text-slate-400">{k.last_used_at ? `used ${ago(k.last_used_at)}` : 'never used'}</span>
                <Button size="xs" variant="ghost" onClick={() => setRevoking(k.id)}>Revoke</Button>
              </li>
            ))}
          </ul>
          <p className="label mt-4">Capture endpoint</p>
          <pre className="overflow-x-auto rounded-2xl bg-ink-950 p-4 text-xs text-emerald-300">{curl}</pre>
        </Card>

        <Card title={<span className="flex items-center gap-2"><WebhookIcon className="size-4 text-fuchsia-500" /> Webhooks</span>} action={<Button size="xs" icon={<Plus className="size-3.5" />} onClick={() => setHookForm({ open: true, name: '', url: '', events: ['lead.created'] })}>Add webhook</Button>}>
          <ul className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
            {hooks?.map((h) => (
              <li key={h.id} className="flex flex-wrap items-center gap-3 py-3 text-sm">
                <span className={clsx('size-2 rounded-full', h.last_status && h.last_status < 300 ? 'bg-emerald-500' : h.last_status ? 'bg-rose-500' : 'bg-slate-300')} />
                <div className="min-w-0 flex-1"><p className="font-medium">{h.name}</p><p className="truncate font-mono text-xs text-slate-500">{h.url}</p></div>
                <div className="flex flex-wrap gap-1">{h.events.map((e) => <Badge key={e}>{e}</Badge>)}</div>
                <span className="text-xs text-slate-400">{h.last_triggered_at ? `${h.last_status} · ${ago(h.last_triggered_at)}` : 'not yet sent'}</span>
                <Button size="xs" variant="secondary" icon={<Play className="size-3" />} onClick={() => run(testHook(h.id), 'Test event queued')}>Test</Button>
                <button onClick={() => run(remove({ ...resources.webhooks, id: h.id }), 'Webhook deleted')} className="p-1 text-slate-400 hover:text-rose-600" aria-label="Delete"><Trash2 className="size-4" /></button>
              </li>
            ))}
          </ul>
          {!hooks?.length && <EmptyState icon={<Send />} title="No webhooks" description="Payloads are signed with HMAC-SHA256 in the X-LMS-Signature header." />}
        </Card>
      </div>

      <Modal open={hookForm.open} onClose={() => setHookForm((f) => ({ ...f, open: false }))} title="Add webhook"
        footer={<><Button variant="secondary" onClick={() => setHookForm((f) => ({ ...f, open: false }))}>Cancel</Button><Button disabled={!hookForm.name || !hookForm.url || !hookForm.events.length} loading={saveState.isLoading}
          onClick={async () => { const r = await run(save({ ...resources.webhooks, body: { name: hookForm.name, url: hookForm.url, events: hookForm.events } })); if (r) { setHookForm((f) => ({ ...f, open: false })); if (r.secret) setRevealed({ label: 'Signing secret', value: r.secret }) } }}>Create</Button></>}>
        <div className="space-y-4">
          <Field label="Name" required><Input value={hookForm.name} onChange={(e) => setHookForm((f) => ({ ...f, name: e.target.value }))} /></Field>
          <Field label="URL" required><Input value={hookForm.url} onChange={(e) => setHookForm((f) => ({ ...f, url: e.target.value }))} placeholder="https://example.com/hooks/lms" /></Field>
          <Field label="Events">
            <div className="flex flex-wrap gap-1.5">{[...(meta?.enums.webhook_events ?? []), '*'].map((e) => {
              const on = hookForm.events.includes(e)
              return <button key={e} type="button" onClick={() => setHookForm((f) => ({ ...f, events: on ? f.events.filter((x) => x !== e) : [...f.events, e] }))} className={clsx('chip', on && 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>{on && <Check className="size-3" />}{e === '*' ? 'All events' : e}</button>
            })}</div>
          </Field>
        </div>
      </Modal>

      <Modal open={!!revealed} onClose={() => setRevealed(null)} size="sm" title={`Your ${revealed?.label}`} description="Copy it now — it won't be shown again.">
        <div className="flex gap-2"><Input readOnly value={revealed?.value ?? ''} className="font-mono text-xs" /><Button variant="secondary" onClick={() => { navigator.clipboard?.writeText(revealed?.value ?? ''); toast('success', 'Copied') }}><Copy className="size-4" /></Button></div>
      </Modal>
      <ConfirmDialog open={revoking !== null} onClose={() => setRevoking(null)} title="Revoke API key?" message="Integrations using it will stop working immediately." confirmLabel="Revoke"
        onConfirm={async () => { if (revoking) await run(revoke(revoking), 'Key revoked'); setRevoking(null) }} />
    </>
  )
}
