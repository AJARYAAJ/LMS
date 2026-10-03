import { useEffect, useState } from 'react'
import clsx from 'clsx'
import { ArrowDown, BookOpenCheck, Calendar, GripVertical, Mail, MessageSquareText, Pencil, Phone, Plus, Repeat, Trash2, Users } from 'lucide-react'
import { useAction, usePermissions } from '@/app/hooks'
import { resources, useDeleteSettingMutation, useMetaQuery, useSaveSettingMutation, useSettings } from '@/services/api'
import { Badge, Button, ConfirmDialog, EmptyState, Field, Input, Modal, PageHeader, PageLoader, Select, Tabs, Textarea, Toggle } from '@/components/ui'
import { humanize } from '@/lib/format'
import type { EmailTemplate, Sequence, SequenceStep } from '@/types'

const stepIcon: Record<string, typeof Phone> = { call: Phone, email: Mail, meeting: Calendar, follow_up: Repeat, todo: MessageSquareText }

export function PlaybooksPage() {
  const [tab, setTab] = useState<'sequences' | 'templates'>('sequences')
  return (
    <div>
      <PageHeader icon={<BookOpenCheck />} title="Playbooks" description="Reusable sequences (cadences) and email templates that keep follow-ups consistent." />
      <Tabs value={tab} onChange={setTab} className="mb-6 w-fit" tabs={[{ value: 'sequences', label: 'Sequences', icon: <Repeat /> }, { value: 'templates', label: 'Email templates', icon: <Mail /> }]} />
      {tab === 'sequences' ? <Sequences /> : <Templates />}
    </div>
  )
}

function Sequences() {
  const run = useAction()
  const { manager } = usePermissions()
  const { data: meta } = useMetaQuery()
  const { data, isLoading } = useSettings(resources.sequences)
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [editing, setEditing] = useState<Partial<Sequence> | null>(null)
  const [deleting, setDeleting] = useState<Sequence | null>(null)
  const [steps, setSteps] = useState<SequenceStep[]>([])
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [active, setActive] = useState(true)

  useEffect(() => {
    if (!editing) return
    setName(editing.name ?? '')
    setDescription(editing.description ?? '')
    setActive(editing.is_active ?? true)
    setSteps(editing.steps ?? [{ day_offset: 0, type: 'call', title: 'Intro call' }])
  }, [editing])

  const patch = (i: number, p: Partial<SequenceStep>) => setSteps((s) => s.map((x, j) => (j === i ? { ...x, ...p } : x)))
  const submit = async () => {
    const body = { name, description, is_active: active, steps: steps.map((s) => ({ ...s, day_offset: Number(s.day_offset), email_template_id: s.email_template_id || null })) }
    if (await run(save({ ...resources.sequences, id: editing?.id, body }), 'Sequence saved')) setEditing(null)
  }

  if (isLoading) return <PageLoader />

  return (
    <>
      {manager && <div className="mb-4 flex justify-end"><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>New sequence</Button></div>}
      <div className="grid gap-5 lg:grid-cols-2">
        {data?.map((s) => (
          <div key={s.id} className="card group p-6">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h3 className="text-lg font-bold text-slate-900 dark:text-white">{s.name}</h3>
                <p className="text-sm text-slate-500">{s.description}</p>
              </div>
              <div className="flex items-center gap-2">
                <Badge color={s.is_active ? '#10b981' : '#94a3b8'}>{s.is_active ? 'Active' : 'Paused'}</Badge>
                {manager && <>
                  <button onClick={() => setEditing(s)} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label="Edit"><Pencil className="size-4" /></button>
                  <button onClick={() => setDeleting(s)} className="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label="Delete"><Trash2 className="size-4" /></button>
                </>}
              </div>
            </div>
            <ol className="relative mt-5 space-y-3 before:absolute before:top-2 before:bottom-2 before:left-[15px] before:w-px before:bg-gradient-to-b before:from-brand-400 before:to-cyan-400">
              {s.steps.map((st, i) => {
                const Icon = stepIcon[st.type] ?? Repeat
                return (
                  <li key={i} className="relative flex items-center gap-3">
                    <span className="relative z-10 flex size-8 items-center justify-center rounded-full bg-white text-brand-600 shadow ring-1 ring-brand-200 dark:bg-ink-900 dark:ring-white/10"><Icon className="size-4" /></span>
                    <span className="text-xs font-semibold text-slate-400">Day {st.day_offset}</span>
                    <span className="text-sm text-slate-700 dark:text-slate-300">{st.title}</span>
                  </li>
                )
              })}
            </ol>
            <div className="mt-5 flex gap-4 text-xs text-slate-500">
              <span className="flex items-center gap-1"><Users className="size-3.5" />{s.active_enrollments_count ?? 0} active</span>
              <span>{s.completed_enrollments_count ?? 0} completed</span>
            </div>
          </div>
        ))}
      </div>
      {!data?.length && <div className="card"><EmptyState icon={<Repeat />} title="No sequences yet" /></div>}

      <Modal open={!!editing} onClose={() => setEditing(null)} size="xl" title={editing?.id ? 'Edit sequence' : 'New sequence'}
        footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button onClick={submit} disabled={!name || !steps.length} loading={saveState.isLoading}>Save sequence</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Name" required><Input value={name} onChange={(e) => setName(e.target.value)} /></Field>
          <Field label="Description"><Input value={description} onChange={(e) => setDescription(e.target.value)} /></Field>
        </div>
        <div className="mt-3"><Toggle checked={active} onChange={setActive} label="Active" description="Only active sequences can be used for new enrollments." /></div>
        <p className="label mt-6">Steps</p>
        <div className="space-y-2">
          {steps.map((st, i) => (
            <div key={i}>
              <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200/80 bg-white/60 p-2.5 dark:border-white/10 dark:bg-white/[0.03]">
                <GripVertical className="size-4 text-slate-300" />
                <span className="text-xs font-semibold text-slate-400">Day</span>
                <Input type="number" min={0} className="w-20" value={st.day_offset} onChange={(e) => patch(i, { day_offset: Number(e.target.value) })} />
                <Select className="w-32" value={st.type} onChange={(e) => patch(i, { type: e.target.value })}>{meta?.enums.task_types.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</Select>
                <Input className="min-w-48 flex-1" value={st.title} onChange={(e) => patch(i, { title: e.target.value })} placeholder="Step title" />
                {st.type === 'email' && (
                  <Select className="w-52" value={String(st.email_template_id ?? '')} onChange={(e) => patch(i, { email_template_id: e.target.value ? Number(e.target.value) : null })} placeholder="No template">
                    {meta?.email_templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                  </Select>
                )}
                <button onClick={() => setSteps((s) => s.filter((_, j) => j !== i))} className="rounded-lg p-2 text-slate-400 hover:text-rose-600" aria-label="Remove step"><Trash2 className="size-4" /></button>
              </div>
              {i < steps.length - 1 && <ArrowDown className="mx-auto my-1 size-4 text-slate-300" />}
            </div>
          ))}
        </div>
        <Button size="sm" variant="subtle" className="mt-3" icon={<Plus className="size-4" />} onClick={() => setSteps((s) => [...s, { day_offset: (s.at(-1)?.day_offset ?? 0) + 2, type: 'follow_up', title: '' }])}>Add step</Button>
      </Modal>
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} title={`Delete ${deleting?.name}?`}
        onConfirm={async () => { if (deleting) await run(remove({ ...resources.sequences, id: deleting.id }), 'Sequence deleted'); setDeleting(null) }} />
    </>
  )
}

function Templates() {
  const run = useAction()
  const { manager } = usePermissions()
  const { data: meta } = useMetaQuery()
  const { data, isLoading } = useSettings(resources.emailTemplates)
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [editing, setEditing] = useState<Partial<EmailTemplate> | null>(null)
  const [form, setForm] = useState({ name: '', category: 'general', subject: '', body: '' })

  useEffect(() => { if (editing) setForm({ name: editing.name ?? '', category: editing.category ?? 'general', subject: editing.subject ?? '', body: editing.body ?? '' }) }, [editing])

  if (isLoading) return <PageLoader />

  return (
    <>
      {manager && <div className="mb-4 flex justify-end"><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>New template</Button></div>}
      <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        {data?.map((t) => (
          <div key={t.id} className={clsx('card group flex flex-col p-6')}>
            <div className="flex items-center justify-between">
              <Badge color="#8b5cf6">{humanize(t.category)}</Badge>
              <span className="text-xs text-slate-400">Used {t.usage_count}×</span>
            </div>
            <h3 className="mt-3 font-bold text-slate-900 dark:text-white">{t.name}</h3>
            <p className="mt-1 text-sm font-medium text-slate-600 dark:text-slate-300">{t.subject}</p>
            <p className="mt-2 line-clamp-4 flex-1 text-sm whitespace-pre-line text-slate-500">{t.body}</p>
            {manager && <div className="mt-4 flex gap-2">
              <Button size="xs" variant="secondary" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(t)}>Edit</Button>
              <Button size="xs" variant="ghost" icon={<Trash2 className="size-3.5" />} onClick={() => run(remove({ ...resources.emailTemplates, id: t.id }), 'Template deleted')}>Delete</Button>
            </div>}
          </div>
        ))}
      </div>
      <Modal open={!!editing} onClose={() => setEditing(null)} size="lg" title={editing?.id ? 'Edit template' : 'New template'}
        footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button disabled={!form.name || !form.subject || !form.body} loading={saveState.isLoading}
          onClick={async () => { if (await run(save({ ...resources.emailTemplates, id: editing?.id, body: form }), 'Template saved')) setEditing(null) }}>Save</Button></>}>
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Name" required><Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} /></Field>
            <Field label="Category"><Select value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))}>{['general', 'outreach', 'follow_up', 'proposal', 'nurture'].map((c) => <option key={c} value={c}>{humanize(c)}</option>)}</Select></Field>
          </div>
          <Field label="Subject" required><Input value={form.subject} onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))} /></Field>
          <Field label="Body" required hint={<>Merge fields: {meta?.enums.merge_fields.join(' ')}</>}><Textarea rows={10} value={form.body} onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))} /></Field>
        </div>
      </Modal>
    </>
  )
}
