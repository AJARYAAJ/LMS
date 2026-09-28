import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import clsx from 'clsx'
import {
  Building, Cable, Calculator, ClipboardCheck, FileInput, GitBranch, GripVertical, Layers, ListChecks, Pencil, Plus, Recycle, Route, Settings,
  Shield, Tag as TagIcon, Trash2, Users, Workflow, Zap,
} from 'lucide-react'
import { useAction, useCurrentUser } from '@/app/hooks'
import {
  resources, useDeleteSettingMutation, useOrganizationQuery, useReorderStatusesMutation, useSaveSettingMutation, useSettings,
  useUpdateOrganizationMutation, type SettingsResource,
} from '@/services/api'
import { Avatar, Badge, Button, Card, Checkbox, ColorPicker, ConfirmDialog, EmptyState, Field, Input, Modal, PageHeader, PageLoader, Select, Toggle } from '@/components/ui'
import { ROLE_LABELS } from '@/lib/constants'
import { ago, humanize } from '@/lib/format'
import type { CustomField, LeadSource, LeadStatus, PipelineStage, Tag, Team, User } from '@/types'
import { SectionHeader } from '@/components/crm/ConditionBuilder'
import { AssignmentRules, Automations, Integrations, ScoringRules, WebForms } from './RuleSections'

const sections = [
  { key: 'organization', label: 'Organization', icon: Building, group: 'Workspace' },
  { key: 'users', label: 'Users & roles', icon: Users, group: 'Workspace' },
  { key: 'teams', label: 'Teams', icon: Shield, group: 'Workspace' },
  { key: 'statuses', label: 'Lifecycle & blueprint', icon: GitBranch, group: 'Process' },
  { key: 'qualification', label: 'Qualification', icon: ClipboardCheck, group: 'Process' },
  { key: 'stages', label: 'Deal pipeline', icon: Layers, group: 'Process' },
  { key: 'sources', label: 'Lead sources', icon: Route, group: 'Process' },
  { key: 'tags', label: 'Tags', icon: TagIcon, group: 'Process' },
  { key: 'fields', label: 'Custom fields', icon: ListChecks, group: 'Process' },
  { key: 'assignment', label: 'Assignment rules', icon: Zap, group: 'Automation' },
  { key: 'scoring', label: 'Lead scoring', icon: Calculator, group: 'Automation' },
  { key: 'automations', label: 'Workflows', icon: Workflow, group: 'Automation' },
  { key: 'forms', label: 'Web forms', icon: FileInput, group: 'Capture' },
  { key: 'integrations', label: 'API & webhooks', icon: Cable, group: 'Capture' },
]

export function SettingsPage() {
  const active = useParams().section ?? 'organization'
  const groups = [...new Set(sections.map((s) => s.group))]

  return (
    <div>
      <PageHeader icon={<Settings />} title="Settings" description="Shape LeadFlow around how your team sells." />
      <div className="grid gap-6 lg:grid-cols-[240px_1fr]">
        <nav className="card h-fit space-y-4 p-3 lg:sticky lg:top-24">
          {groups.map((g) => (
            <div key={g}>
              <p className="mb-1 px-3 text-[10px] font-semibold tracking-[0.14em] text-slate-400 uppercase">{g}</p>
              {sections.filter((s) => s.group === g).map((s) => (
                <Link key={s.key} to={`/settings/${s.key}`}
                  className={clsx('flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium transition', active === s.key ? 'bg-[linear-gradient(135deg,rgba(139,92,246,0.16),rgba(217,70,239,0.1))] text-brand-700 dark:text-white' : 'text-slate-600 hover:bg-slate-900/[0.04] dark:text-slate-400 dark:hover:bg-white/[0.05]')}>
                  <s.icon className="size-4" />{s.label}
                </Link>
              ))}
            </div>
          ))}
          <Link to="/leads/trash" className="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-900/[0.04] dark:text-slate-400 dark:hover:bg-white/[0.05]"><Recycle className="size-4" />Recycle bin</Link>
        </nav>
        <div key={active} className="min-w-0 animate-slide-up">
          {active === 'organization' && <OrganizationSection />}
          {active === 'users' && <UsersSection />}
          {active === 'teams' && <TeamsSection />}
          {active === 'statuses' && <StatusesSection />}
          {active === 'qualification' && <QualificationSection />}
          {active === 'stages' && <SimpleList resource={resources.stages} title="Deal pipeline" description="Stages and win probabilities used for weighted forecasting." fields={['name', 'probability', 'color', 'is_won', 'is_lost']} />}
          {active === 'sources' && <SimpleList resource={resources.sources} title="Lead sources" description="Where leads come from. Used for routing, scoring and ROI reports." fields={['name', 'color', 'is_active']} />}
          {active === 'tags' && <SimpleList resource={resources.tags} title="Tags" description="Flexible labels for segmentation and automation conditions." fields={['name', 'color']} />}
          {active === 'fields' && <CustomFieldsSection />}
          {active === 'assignment' && <AssignmentRules />}
          {active === 'scoring' && <ScoringRules />}
          {active === 'automations' && <Automations />}
          {active === 'forms' && <WebForms />}
          {active === 'integrations' && <Integrations />}
        </div>
      </div>
    </div>
  )
}

function OrganizationSection() {
  const run = useAction()
  const { data: org } = useOrganizationQuery()
  const [update, { isLoading }] = useUpdateOrganizationMutation()
  const [form, setForm] = useState<Record<string, string>>({})
  useEffect(() => { if (org) setForm({ name: org.name, industry: org.industry ?? '', website: org.website ?? '', phone: org.phone ?? '', timezone: org.timezone, currency: org.currency }) }, [org])
  if (!org) return <PageLoader />
  const set = (k: string) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))

  return (
    <Card>
      <SectionHeader title="Organization" description="Company profile, currency and time zone." />
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Name"><Input value={form.name ?? ''} onChange={set('name')} /></Field>
        <Field label="Industry"><Input value={form.industry ?? ''} onChange={set('industry')} /></Field>
        <Field label="Website"><Input value={form.website ?? ''} onChange={set('website')} placeholder="https://" /></Field>
        <Field label="Phone"><Input value={form.phone ?? ''} onChange={set('phone')} /></Field>
        <Field label="Currency"><Select value={form.currency ?? 'USD'} onChange={set('currency')}>{['USD', 'EUR', 'GBP', 'INR', 'AUD', 'CAD', 'SGD', 'AED', 'JPY'].map((c) => <option key={c}>{c}</option>)}</Select></Field>
        <Field label="Time zone"><Select value={form.timezone ?? 'UTC'} onChange={set('timezone')}>{Intl.supportedValuesOf?.('timeZone').map((t) => <option key={t}>{t}</option>) ?? <option>UTC</option>}</Select></Field>
      </div>
      <div className="mt-6 flex justify-end"><Button loading={isLoading} onClick={() => run(update({ ...form, website: form.website || null }), 'Organization updated')}>Save changes</Button></div>
    </Card>
  )
}

function QualificationSection() {
  const run = useAction()
  const { data: org } = useOrganizationQuery()
  const [update, { isLoading }] = useUpdateOrganizationMutation()
  const [items, setItems] = useState<{ key: string; label: string }[]>([])
  useEffect(() => {
    const s = (org as unknown as { settings?: { qualification_criteria?: { key: string; label: string }[] } })?.settings
    setItems(s?.qualification_criteria ?? [])
  }, [org])

  return (
    <Card>
      <SectionHeader title="Qualification checklist" description="The criteria reps confirm before qualifying a lead (BANT by default). Progress appears on every lead and can be used in rules as “qualification percent”." />
      <div className="space-y-2">
        {items.map((it, i) => (
          <div key={i} className="flex items-center gap-2">
            <GripVertical className="size-4 text-slate-300" />
            <Input value={it.label} onChange={(e) => setItems((x) => x.map((y, j) => (j === i ? { ...y, label: e.target.value } : y)))} />
            <button onClick={() => setItems((x) => x.filter((_, j) => j !== i))} className="rounded-lg p-2 text-slate-400 hover:text-rose-600" aria-label="Remove"><Trash2 className="size-4" /></button>
          </div>
        ))}
      </div>
      <div className="mt-4 flex justify-between">
        <Button size="sm" variant="subtle" icon={<Plus className="size-4" />} onClick={() => setItems((x) => [...x, { key: `criterion_${Date.now().toString(36)}`, label: '' }])}>Add criterion</Button>
        <Button loading={isLoading} onClick={() => run(update({ settings: { ...((org as unknown as { settings?: object })?.settings ?? {}), qualification_criteria: items.filter((i) => i.label.trim()) } }), 'Checklist saved')}>Save checklist</Button>
      </div>
    </Card>
  )
}

function UsersSection() {
  const run = useAction()
  const me = useCurrentUser()
  const { data: users } = useSettings(resources.users)
  const { data: teams } = useSettings(resources.teams)
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [editing, setEditing] = useState<Partial<User> | null>(null)
  const [deleting, setDeleting] = useState<User | null>(null)
  const [form, setForm] = useState<{ name: string; email: string; password: string; role: string; job_title: string; team_ids: number[]; is_active: boolean }>({ name: '', email: '', password: '', role: 'sales_rep', job_title: '', team_ids: [], is_active: true })

  useEffect(() => { if (editing) setForm({ name: editing.name ?? '', email: editing.email ?? '', password: '', role: editing.role ?? 'sales_rep', job_title: editing.job_title ?? '', team_ids: editing.teams?.map((t) => t.id) ?? [], is_active: editing.is_active ?? true }) }, [editing])

  const submit = async () => {
    const body: Record<string, unknown> = { ...form }
    if (editing?.id && !form.password) delete body.password
    if (await run(save({ ...resources.users, id: editing?.id, body }), editing?.id ? 'User updated' : 'User invited')) setEditing(null)
  }

  return (
    <>
      <SectionHeader title="Users & roles" description="Admins configure everything · Managers see their teams and reassign · Sales reps work their own leads · Read-only can browse."
        action={<Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>Add user</Button>} />
      <div className="grid gap-4 md:grid-cols-2">
        {users?.map((u) => (
          <div key={u.id} className={clsx('card flex items-center gap-4 p-4', !u.is_active && 'opacity-60')}>
            <Avatar name={u.name} color={u.avatar_color} size="lg" />
            <div className="min-w-0 flex-1">
              <p className="truncate font-semibold text-slate-900 dark:text-white">{u.name} {u.id === me?.id && <span className="text-xs text-slate-400">(you)</span>}</p>
              <p className="truncate text-sm text-slate-500">{u.email}</p>
              <div className="mt-1.5 flex flex-wrap gap-1.5">
                <Badge color={u.role === 'admin' ? '#8b5cf6' : u.role === 'manager' ? '#0ea5e9' : u.role === 'viewer' ? '#64748b' : '#10b981'}>{ROLE_LABELS[u.role]}</Badge>
                {u.teams?.map((t) => <Badge key={t.id} color={t.color}>{t.name}</Badge>)}
                {!u.is_active && <Badge>Deactivated</Badge>}
              </div>
            </div>
            <div className="text-right text-xs text-slate-500">
              <p>{u.open_leads_count ?? 0} open leads</p>
              <p>{u.last_login_at ? `Active ${ago(u.last_login_at)}` : 'Never signed in'}</p>
              <div className="mt-2 flex justify-end gap-1">
                <button onClick={() => setEditing(u)} className="rounded-lg p-1.5 hover:text-brand-600" aria-label="Edit"><Pencil className="size-4" /></button>
                {u.id !== me?.id && <button onClick={() => setDeleting(u)} className="rounded-lg p-1.5 hover:text-rose-600" aria-label="Delete"><Trash2 className="size-4" /></button>}
              </div>
            </div>
          </div>
        ))}
      </div>
      <Modal open={!!editing} onClose={() => setEditing(null)} title={editing?.id ? 'Edit user' : 'Add user'} footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button onClick={submit} loading={saveState.isLoading}>Save</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Name" required><Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} /></Field>
          <Field label="Email" required><Input type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} /></Field>
          <Field label={editing?.id ? 'New password (optional)' : 'Temporary password'} required={!editing?.id}><Input type="password" value={form.password} onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))} /></Field>
          <Field label="Role"><Select value={form.role} onChange={(e) => setForm((f) => ({ ...f, role: e.target.value }))}>{Object.entries(ROLE_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
          <Field label="Job title" className="sm:col-span-2"><Input value={form.job_title} onChange={(e) => setForm((f) => ({ ...f, job_title: e.target.value }))} /></Field>
          <Field label="Teams" className="sm:col-span-2">
            <div className="flex flex-wrap gap-2">{teams?.map((t) => (
              <label key={t.id} className="chip cursor-pointer"><Checkbox checked={form.team_ids.includes(t.id)} onChange={(v) => setForm((f) => ({ ...f, team_ids: v ? [...f.team_ids, t.id] : f.team_ids.filter((x) => x !== t.id) }))} />{t.name}</label>
            ))}</div>
          </Field>
          {editing?.id && <div className="sm:col-span-2"><Toggle checked={form.is_active} onChange={(v) => setForm((f) => ({ ...f, is_active: v }))} label="Active" description="Deactivated users cannot sign in; their sessions are revoked." /></div>}
        </div>
      </Modal>
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} title={`Remove ${deleting?.name}?`} message="Their open leads and tasks return to the unassigned queue."
        onConfirm={async () => { if (deleting) await run(remove({ ...resources.users, id: deleting.id }), 'User removed'); setDeleting(null) }} />
    </>
  )
}

function TeamsSection() {
  const run = useAction()
  const { data: teams } = useSettings(resources.teams)
  const { data: users } = useSettings(resources.users)
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [editing, setEditing] = useState<Partial<Team> | null>(null)
  const [form, setForm] = useState<{ name: string; description: string; color: string; manager_id: string; member_ids: number[] }>({ name: '', description: '', color: '#8b5cf6', manager_id: '', member_ids: [] })
  useEffect(() => { if (editing) setForm({ name: editing.name ?? '', description: editing.description ?? '', color: editing.color ?? '#8b5cf6', manager_id: String(editing.manager_id ?? ''), member_ids: editing.members?.map((m) => m.id) ?? [] }) }, [editing])

  return (
    <>
      <SectionHeader title="Teams" description="Group reps for routing, visibility and reporting. Managers see their teams' leads." action={<Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>New team</Button>} />
      <div className="grid gap-4 md:grid-cols-2">
        {teams?.map((t) => (
          <div key={t.id} className="card p-5">
            <div className="flex items-center gap-3">
              <span className="size-3 rounded-full" style={{ backgroundColor: t.color, boxShadow: `0 0 12px ${t.color}` }} />
              <h3 className="flex-1 font-bold text-slate-900 dark:text-white">{t.name}</h3>
              <button onClick={() => setEditing(t)} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label="Edit"><Pencil className="size-4" /></button>
              <button onClick={() => run(remove({ ...resources.teams, id: t.id }), 'Team deleted')} className="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label="Delete"><Trash2 className="size-4" /></button>
            </div>
            <p className="mt-1 text-sm text-slate-500">{t.description}</p>
            <div className="mt-4 flex -space-x-2">{t.members?.map((m) => <Avatar key={m.id} name={m.name} color={m.avatar_color} size="sm" />)}</div>
            <p className="mt-3 text-xs text-slate-500">Manager: {t.manager?.name ?? '—'} · {t.leads_count ?? 0} leads</p>
          </div>
        ))}
      </div>
      {!teams?.length && <div className="card"><EmptyState title="No teams yet" /></div>}
      <Modal open={!!editing} onClose={() => setEditing(null)} title={editing?.id ? 'Edit team' : 'New team'}
        footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button disabled={!form.name} loading={saveState.isLoading}
          onClick={async () => { if (await run(save({ ...resources.teams, id: editing?.id, body: { ...form, manager_id: form.manager_id ? Number(form.manager_id) : null } }), 'Team saved')) setEditing(null) }}>Save</Button></>}>
        <div className="space-y-4">
          <Field label="Name" required><Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} /></Field>
          <Field label="Description"><Input value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} /></Field>
          <Field label="Colour"><ColorPicker value={form.color} onChange={(c) => setForm((f) => ({ ...f, color: c }))} /></Field>
          <Field label="Manager"><Select value={form.manager_id} onChange={(e) => setForm((f) => ({ ...f, manager_id: e.target.value }))} placeholder="—">{users?.filter((u) => u.role !== 'viewer').map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</Select></Field>
          <Field label="Members">
            <div className="grid max-h-56 gap-1.5 overflow-y-auto sm:grid-cols-2">{users?.filter((u) => u.role !== 'viewer').map((u) => (
              <label key={u.id} className="flex cursor-pointer items-center gap-2 rounded-xl px-2 py-1.5 text-sm hover:bg-brand-50 dark:hover:bg-white/5">
                <Checkbox checked={form.member_ids.includes(u.id)} onChange={(v) => setForm((f) => ({ ...f, member_ids: v ? [...f.member_ids, u.id] : f.member_ids.filter((x) => x !== u.id) }))} />
                <Avatar name={u.name} color={u.avatar_color} size="xs" />{u.name}
              </label>
            ))}</div>
          </Field>
        </div>
      </Modal>
    </>
  )
}

const BLUEPRINT_FIELDS = ['email', 'phone', 'company', 'job_title', 'budget', 'expected_value', 'timeline', 'requirements', 'owner_id', 'next_follow_up_at', 'lost_reason']

function StatusesSection() {
  const run = useAction()
  const { data } = useSettings(resources.statuses)
  const [reorder] = useReorderStatusesMutation()
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [editing, setEditing] = useState<Partial<LeadStatus> | null>(null)
  const [form, setForm] = useState<{ name: string; color: string; category: string; is_default: boolean; is_active: boolean; is_terminal: boolean; required_fields: string[] }>({ name: '', color: '#8b5cf6', category: 'open', is_default: false, is_active: true, is_terminal: false, required_fields: [] })
  const [dragId, setDragId] = useState<number | null>(null)

  useEffect(() => { if (editing) setForm({ name: editing.name ?? '', color: editing.color ?? '#8b5cf6', category: editing.category ?? 'open', is_default: editing.is_default ?? false, is_active: editing.is_active ?? true, is_terminal: editing.is_terminal ?? false, required_fields: editing.required_fields ?? [] }) }, [editing])

  const onDrop = (targetId: number) => {
    if (!data || dragId === null || dragId === targetId) return
    const ids = data.map((s) => s.id).filter((id) => id !== dragId)
    ids.splice(ids.indexOf(targetId), 0, dragId)
    run(reorder(ids), 'Order saved')
    setDragId(null)
  }

  return (
    <>
      <SectionHeader title="Lifecycle & blueprint" description="Drag to reorder. Each status has a category for analytics, and a blueprint of fields that must be filled before a lead can enter it." action={<Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>Add status</Button>} />
      <div className="space-y-2">
        {data?.map((s) => (
          <div key={s.id} draggable onDragStart={() => setDragId(s.id)} onDragOver={(e) => e.preventDefault()} onDrop={() => onDrop(s.id)}
            className={clsx('card flex cursor-grab items-center gap-4 p-4 active:cursor-grabbing', dragId === s.id && 'opacity-50')}>
            <GripVertical className="size-4 text-slate-300" />
            <span className="size-3 rounded-full" style={{ backgroundColor: s.color, boxShadow: `0 0 10px ${s.color}` }} />
            <div className="min-w-0 flex-1">
              <p className="font-semibold text-slate-900 dark:text-white">{s.name} {s.is_default && <Badge color="#8b5cf6">Default</Badge>} {!s.is_active && <Badge>Hidden</Badge>}</p>
              <p className="text-xs text-slate-500">{humanize(s.category)}{s.is_terminal && ' · terminal'}{s.required_fields?.length ? ` · requires ${s.required_fields.map(humanize).join(', ')}` : ''}</p>
            </div>
            <span className="text-xs text-slate-500">{s.leads_count ?? 0} leads</span>
            <button onClick={() => setEditing(s)} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label="Edit"><Pencil className="size-4" /></button>
            <button onClick={() => run(remove({ ...resources.statuses, id: s.id }), 'Status deleted')} className="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label="Delete"><Trash2 className="size-4" /></button>
          </div>
        ))}
      </div>
      <Modal open={!!editing} onClose={() => setEditing(null)} size="lg" title={editing?.id ? `Edit ${editing.name}` : 'New status'}
        footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button disabled={!form.name} loading={saveState.isLoading} onClick={async () => { if (await run(save({ ...resources.statuses, id: editing?.id, body: form }), 'Status saved')) setEditing(null) }}>Save</Button></>}>
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Name" required><Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} /></Field>
            <Field label="Category" hint="Drives funnel & conversion analytics"><Select value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))}>{['open', 'qualified', 'converted', 'lost'].map((c) => <option key={c} value={c}>{humanize(c)}</option>)}</Select></Field>
          </div>
          <Field label="Colour"><ColorPicker value={form.color} onChange={(c) => setForm((f) => ({ ...f, color: c }))} /></Field>
          <Field label="Blueprint — required before entering this status">
            <div className="flex flex-wrap gap-2">{BLUEPRINT_FIELDS.map((f) => (
              <label key={f} className={clsx('chip cursor-pointer', form.required_fields.includes(f) && 'border-amber-300 bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200')}>
                <Checkbox checked={form.required_fields.includes(f)} onChange={(v) => setForm((x) => ({ ...x, required_fields: v ? [...x.required_fields, f] : x.required_fields.filter((y) => y !== f) }))} />{humanize(f)}
              </label>
            ))}</div>
          </Field>
          <div className="grid gap-3 sm:grid-cols-3">
            <Toggle checked={form.is_default} onChange={(v) => setForm((f) => ({ ...f, is_default: v }))} label="Default" description="New leads start here" />
            <Toggle checked={form.is_active} onChange={(v) => setForm((f) => ({ ...f, is_active: v }))} label="Visible" />
            <Toggle checked={form.is_terminal} onChange={(v) => setForm((f) => ({ ...f, is_terminal: v }))} label="Terminal" description="End of the journey" />
          </div>
        </div>
      </Modal>
    </>
  )
}

type SimpleItem = LeadSource | Tag | PipelineStage

function SimpleList<T extends SimpleItem>({ resource, title, description, fields }: { resource: SettingsResource<T>; title: string; description: string; fields: string[] }) {
  const run = useAction()
  const { data } = useSettings(resource)
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [editing, setEditing] = useState<Partial<T> | null>(null)
  const [form, setForm] = useState<Record<string, string | number | boolean>>({})
  useEffect(() => { if (editing) setForm(Object.fromEntries(fields.map((f) => [f, (editing as Record<string, unknown>)[f] as string ?? (f === 'color' ? '#8b5cf6' : f.startsWith('is_') ? f === 'is_active' : f === 'probability' ? 10 : '')]))) }, [editing]) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <>
      <SectionHeader title={title} description={description} action={<Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>Add</Button>} />
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {data?.map((item) => {
          const it = item as SimpleItem & { leads_count?: number; deals_count?: number; probability?: number; is_active?: boolean }
          return (
            <div key={it.id} className="card group flex items-center gap-3 p-4">
              <span className="size-3 rounded-full" style={{ backgroundColor: it.color, boxShadow: `0 0 10px ${it.color}` }} />
              <div className="min-w-0 flex-1">
                <p className="truncate font-semibold text-slate-900 dark:text-white">{it.name}</p>
                <p className="text-xs text-slate-500">{it.probability !== undefined ? `${it.probability}% · ${it.deals_count ?? 0} deals` : `${it.leads_count ?? 0} leads`}{it.is_active === false && ' · inactive'}</p>
              </div>
              <button onClick={() => setEditing(item)} className="rounded-lg p-1.5 text-slate-400 opacity-0 group-hover:opacity-100 hover:text-brand-600" aria-label="Edit"><Pencil className="size-4" /></button>
              <button onClick={() => run(remove({ ...resource, id: it.id }), 'Deleted')} className="rounded-lg p-1.5 text-slate-400 opacity-0 group-hover:opacity-100 hover:text-rose-600" aria-label="Delete"><Trash2 className="size-4" /></button>
            </div>
          )
        })}
      </div>
      <Modal open={!!editing} onClose={() => setEditing(null)} title={(editing as { id?: number })?.id ? 'Edit' : 'Add'}
        footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button disabled={!form.name} loading={saveState.isLoading} onClick={async () => { if (await run(save({ ...resource, id: (editing as { id?: number })?.id, body: form }), 'Saved')) setEditing(null) }}>Save</Button></>}>
        <div className="space-y-4">
          {fields.map((f) => f === 'color' ? (
            <Field key={f} label="Colour"><ColorPicker value={String(form.color ?? '#8b5cf6')} onChange={(c) => setForm((x) => ({ ...x, color: c }))} /></Field>
          ) : f.startsWith('is_') ? (
            <Toggle key={f} checked={!!form[f]} onChange={(v) => setForm((x) => ({ ...x, [f]: v }))} label={humanize(f.replace('is_', ''))} />
          ) : (
            <Field key={f} label={humanize(f)}><Input type={f === 'probability' ? 'number' : 'text'} value={String(form[f] ?? '')} onChange={(e) => setForm((x) => ({ ...x, [f]: f === 'probability' ? Number(e.target.value) : e.target.value }))} /></Field>
          ))}
        </div>
      </Modal>
    </>
  )
}

function CustomFieldsSection() {
  const run = useAction()
  const { data } = useSettings(resources.customFields)
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [editing, setEditing] = useState<Partial<CustomField> | null>(null)
  const [form, setForm] = useState({ label: '', type: 'text', entity: 'lead', options: '', is_required: false })
  useEffect(() => { if (editing) setForm({ label: editing.label ?? '', type: editing.type ?? 'text', entity: editing.entity ?? 'lead', options: editing.options?.join(', ') ?? '', is_required: editing.is_required ?? false }) }, [editing])

  return (
    <>
      <SectionHeader title="Custom fields" description="Capture business-specific data on leads, contacts, accounts and deals. Fields appear in the matching form and details panel." action={<Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>Add field</Button>} />
      <Card padded={false}>
        <table className="w-full">
          <thead><tr>{['Label', 'Key', 'Applies to', 'Type', 'Required', ''].map((h) => <th key={h} className="table-head">{h}</th>)}</tr></thead>
          <tbody className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
            {data?.map((f) => (
              <tr key={f.id}>
                <td className="table-cell font-medium">{f.label}</td><td className="table-cell font-mono text-xs text-slate-500">{f.key}</td><td className="table-cell capitalize">{f.entity}s</td>
                <td className="table-cell"><Badge>{f.type}</Badge></td><td className="table-cell">{f.is_required ? 'Yes' : '—'}</td>
                <td className="table-cell text-right">
                  <button onClick={() => setEditing(f)} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label="Edit"><Pencil className="size-4" /></button>
                  <button onClick={() => run(remove({ ...resources.customFields, id: f.id }), 'Field deleted')} className="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label="Delete"><Trash2 className="size-4" /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!data?.length && <EmptyState icon={<ListChecks />} title="No custom fields" description="Add fields such as “Contract end date” or “Number of seats”." />}
      </Card>
      <Modal open={!!editing} onClose={() => setEditing(null)} title={editing?.id ? 'Edit field' : 'New field'}
        footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button disabled={!form.label} loading={saveState.isLoading}
          onClick={async () => { if (await run(save({ ...resources.customFields, id: editing?.id, body: { ...form, options: form.type === 'select' ? form.options.split(',').map((o) => o.trim()).filter(Boolean) : null } }), 'Field saved')) setEditing(null) }}>Save</Button></>}>
        <div className="space-y-4">
          <Field label="Label" required><Input value={form.label} onChange={(e) => setForm((f) => ({ ...f, label: e.target.value }))} /></Field>
          <Field label="Applies to"><Select value={form.entity} onChange={(e) => setForm((f) => ({ ...f, entity: e.target.value }))}>{['lead', 'contact', 'account', 'deal'].map((t) => <option key={t} value={t}>{humanize(t)}s</option>)}</Select></Field>
          <Field label="Type"><Select value={form.type} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))}>{['text', 'textarea', 'number', 'date', 'select', 'boolean'].map((t) => <option key={t}>{t}</option>)}</Select></Field>
          {form.type === 'select' && <Field label="Options" hint="Comma separated"><Input value={form.options} onChange={(e) => setForm((f) => ({ ...f, options: e.target.value }))} /></Field>}
          <Toggle checked={form.is_required} onChange={(v) => setForm((f) => ({ ...f, is_required: v }))} label="Required" />
        </div>
      </Modal>
    </>
  )
}
