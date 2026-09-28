import { useEffect, useMemo, useState } from 'react'
import clsx from 'clsx'
import { AlertTriangle, ArrowRight, Building2, CalendarDays, Eye, GitMerge, Handshake, MessageCircle, MessageSquare, RefreshCw, Search, Sparkles, User as UserIcon, Wand2 } from 'lucide-react'
import { useAction, useAppSelector, useToast } from '@/app/hooks'
import {
  useAssignLeadMutation, useChangeLeadStatusMutation, useConvertLeadMutation, useEnrollMutation, useLeadsQuery, useMergeLeadMutation,
  errorMessage, useAiBriefMutation, useMetaQuery, usePreviewEmailMutation, useSendEmailMutation, useSendMessageMutation,
} from '@/services/api'
import { Avatar, Badge, Button, Field, Input, Modal, Select, Textarea, Toggle } from '@/components/ui'
import { StatusBadge } from '@/components/crm/Badges'
import { ago, humanize, money } from '@/lib/format'
import type { Lead, LeadStatus } from '@/types'

export function EmailComposerModal({ lead, open, onClose, templateId }: { lead: Lead; open: boolean; onClose: () => void; templateId?: number | null }) {
  const run = useAction()
  const { data: meta } = useMetaQuery()
  const [send, { isLoading }] = useSendEmailMutation()
  const [preview, previewState] = usePreviewEmailMutation()
  const [form, setForm] = useState({ template: '', subject: '', body: '' })
  const [showPreview, setShowPreview] = useState(false)

  useEffect(() => {
    if (!open) return
    const t = meta?.email_templates.find((x) => x.id === templateId)
    setForm(t ? { template: String(t.id), subject: t.subject, body: t.body } : { template: '', subject: '', body: '' })
    setShowPreview(false)
  }, [open, templateId, meta])

  const pick = (id: string) => {
    const t = meta?.email_templates.find((x) => String(x.id) === id)
    setForm(t ? { template: id, subject: t.subject, body: t.body } : { template: '', subject: form.subject, body: form.body })
  }

  const togglePreview = async () => {
    if (!showPreview) await preview({ id: lead.id, subject: form.subject, body: form.body })
    setShowPreview((s) => !s)
  }

  const submit = async () => {
    const r = await run(send({ id: lead.id, subject: form.subject, body: form.body, email_template_id: form.template ? Number(form.template) : null }), `Email sent to ${lead.email}`)
    if (r) onClose()
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title="Compose email"
      description={<>To <span className="font-medium text-slate-700 dark:text-slate-200">{lead.full_name}</span> &lt;{lead.email ?? 'no email'}&gt;</>}
      footer={
        <>
          <Button variant="ghost" icon={<Eye className="size-4" />} onClick={togglePreview} loading={previewState.isLoading} className="mr-auto">{showPreview ? 'Edit' : 'Preview'}</Button>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button onClick={submit} loading={isLoading} disabled={!lead.email || !form.subject || !form.body}>Send email</Button>
        </>
      }
    >
      {showPreview && previewState.data ? (
        <div className="rounded-2xl border border-slate-200/70 bg-white/70 p-5 dark:border-white/10 dark:bg-white/[0.03]">
          <p className="text-xs text-slate-500">Subject</p>
          <p className="font-display mb-4 text-lg font-semibold text-slate-900 dark:text-white">{previewState.data.subject}</p>
          <p className="text-sm whitespace-pre-line text-slate-700 dark:text-slate-300">{previewState.data.body}</p>
        </div>
      ) : (
        <div className="space-y-4">
          <Field label="Template">
            <Select value={form.template} onChange={(e) => pick(e.target.value)} placeholder="Blank email">
              {meta?.email_templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </Select>
          </Field>
          <Field label="Subject" required><Input value={form.subject} onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))} /></Field>
          <Field label="Message" required hint={<>Merge fields: {meta?.enums.merge_fields.map((m) => (
            <button key={m} type="button" onClick={() => setForm((f) => ({ ...f, body: `${f.body}${m}` }))} className="mr-1 rounded bg-brand-50 px-1 font-mono text-[10px] text-brand-700 hover:bg-brand-100 dark:bg-brand-500/15 dark:text-brand-200">{m}</button>
          ))}</>}>
            <Textarea rows={10} value={form.body} onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))} />
          </Field>
        </div>
      )}
    </Modal>
  )
}

export function ConvertModal({ lead, open, onClose }: { lead: Lead; open: boolean; onClose: () => void }) {
  const run = useAction()
  const currency = useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
  const { data: meta } = useMetaQuery()
  const [convert, { isLoading }] = useConvertLeadMutation()
  const stages = meta?.stages.filter((s) => !s.is_won && !s.is_lost) ?? []
  const [form, setForm] = useState({ create_account: true, create_deal: true, deal_name: '', deal_amount: '', pipeline_stage_id: '', expected_close_date: '' })

  useEffect(() => {
    if (open) setForm({ create_account: !!lead.company, create_deal: true, deal_name: `${lead.company || lead.full_name} deal`, deal_amount: lead.expected_value ?? '', pipeline_stage_id: String(stages[0]?.id ?? ''), expected_close_date: '' })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, lead])

  const submit = async () => {
    const r = await run(convert({
      id: lead.id,
      create_account: form.create_account,
      create_deal: form.create_deal,
      deal_name: form.deal_name || null,
      deal_amount: form.deal_amount ? Number(form.deal_amount) : null,
      pipeline_stage_id: form.pipeline_stage_id ? Number(form.pipeline_stage_id) : null,
      expected_close_date: form.expected_close_date || null,
    }), '🎉 Lead converted')
    if (r) onClose()
  }

  const outputs = [
    { icon: UserIcon, label: 'Contact', value: lead.full_name, on: true },
    { icon: Building2, label: 'Account', value: lead.company ?? '—', on: form.create_account && !!lead.company },
    { icon: Handshake, label: 'Deal', value: form.deal_amount ? money(form.deal_amount, currency) : 'No amount', on: form.create_deal },
  ]

  return (
    <Modal open={open} onClose={onClose} size="lg" title="Convert lead" description="Turn this lead into a contact, account and deal in one step."
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} loading={isLoading} icon={<Sparkles className="size-4" />}>Convert</Button></>}>
      <div className="mb-6 grid grid-cols-3 gap-3">
        {outputs.map((o, i) => (
          <div key={o.label} className={clsx('relative rounded-2xl border p-4 text-center transition-all', o.on ? 'border-brand-300 bg-gradient-to-b from-brand-50 to-white shadow-[0_10px_30px_-12px_rgba(139,92,246,0.5)] dark:border-brand-500/40 dark:from-brand-500/15 dark:to-transparent' : 'border-dashed border-slate-300 opacity-50 dark:border-white/10')}>
            <o.icon className="mx-auto size-5 text-brand-600 dark:text-brand-300" />
            <p className="mt-2 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">{o.label}</p>
            <p className="mt-0.5 truncate text-sm font-medium text-slate-900 dark:text-white">{o.value}</p>
            {i < 2 && <ArrowRight className="absolute top-1/2 -right-3 z-10 size-4 -translate-y-1/2 text-slate-300" />}
          </div>
        ))}
      </div>
      <div className="space-y-4">
        <Toggle checked={form.create_account} onChange={(v) => setForm((f) => ({ ...f, create_account: v }))} label="Create or link account" description={lead.company ? `Matches existing account "${lead.company}" if found.` : 'Lead has no company.'} disabled={!lead.company} />
        <Toggle checked={form.create_deal} onChange={(v) => setForm((f) => ({ ...f, create_deal: v }))} label="Create deal" description="Adds an opportunity to the pipeline." />
        {form.create_deal && (
          <div className="grid animate-fade-in gap-3 sm:grid-cols-2">
            <Field label="Deal name" className="sm:col-span-2"><Input value={form.deal_name} onChange={(e) => setForm((f) => ({ ...f, deal_name: e.target.value }))} /></Field>
            <Field label={`Amount (${currency})`}><Input type="number" min={0} value={form.deal_amount} onChange={(e) => setForm((f) => ({ ...f, deal_amount: e.target.value }))} /></Field>
            <Field label="Stage">
              <Select value={form.pipeline_stage_id} onChange={(e) => setForm((f) => ({ ...f, pipeline_stage_id: e.target.value }))}>
                {stages.map((s) => <option key={s.id} value={s.id}>{s.name} · {s.probability}%</option>)}
              </Select>
            </Field>
            <Field label="Expected close"><Input type="date" value={form.expected_close_date} onChange={(e) => setForm((f) => ({ ...f, expected_close_date: e.target.value }))} /></Field>
          </div>
        )}
      </div>
    </Modal>
  )
}

export function AssignModal({ lead, open, onClose }: { lead: Lead; open: boolean; onClose: () => void }) {
  const run = useAction()
  const { data: meta } = useMetaQuery()
  const [assign, { isLoading }] = useAssignLeadMutation()
  const [owner, setOwner] = useState('')
  const [reason, setReason] = useState('')

  useEffect(() => { if (open) { setOwner(String(lead.owner_id ?? '')); setReason('') } }, [open, lead])

  const submit = async (auto = false) => {
    const r = await run(assign(auto ? { id: lead.id, auto: true } : { id: lead.id, owner_id: owner ? Number(owner) : null, reason: reason || undefined }), auto ? 'Assignment rules applied' : 'Owner updated')
    if (r) onClose()
  }

  return (
    <Modal open={open} onClose={onClose} title="Assign lead" footer={<>
      <Button variant="ghost" className="mr-auto" icon={<Sparkles className="size-4" />} onClick={() => submit(true)} loading={isLoading}>Use assignment rules</Button>
      <Button variant="secondary" onClick={onClose}>Cancel</Button>
      <Button onClick={() => submit()} loading={isLoading}>Assign</Button>
    </>}>
      <div className="space-y-2">
        <button type="button" onClick={() => setOwner('')} className={clsx('flex w-full items-center gap-3 rounded-2xl border p-3 text-left text-sm transition', owner === '' ? 'border-brand-400 bg-brand-50 dark:bg-brand-500/10' : 'border-slate-200 dark:border-white/10')}>
          <span className="flex size-9 items-center justify-center rounded-full border-2 border-dashed border-slate-300 text-slate-400">?</span>
          <span className="font-medium">Unassigned queue</span>
        </button>
        <div className="grid max-h-72 gap-2 overflow-y-auto sm:grid-cols-2">
          {meta?.users.filter((u) => u.role !== 'viewer').map((u) => (
            <button key={u.id} type="button" onClick={() => setOwner(String(u.id))}
              className={clsx('flex items-center gap-3 rounded-2xl border p-3 text-left transition', owner === String(u.id) ? 'border-brand-400 bg-brand-50 shadow-[0_6px_20px_-8px_rgba(139,92,246,0.5)] dark:bg-brand-500/10' : 'border-slate-200 hover:border-brand-200 dark:border-white/10')}>
              <Avatar name={u.name} color={u.avatar_color} size="sm" />
              <span className="min-w-0">
                <span className="block truncate text-sm font-medium text-slate-900 dark:text-white">{u.name}</span>
                <span className="block text-xs text-slate-500">{humanize(u.role)}</span>
              </span>
            </button>
          ))}
        </div>
        <Field label="Reason (optional)" className="pt-2"><Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Territory change" /></Field>
      </div>
    </Modal>
  )
}

export function MergeModal({ lead, open, onClose }: { lead: Lead; open: boolean; onClose: () => void }) {
  const run = useAction()
  const [merge, { isLoading }] = useMergeLeadMutation()
  const [search, setSearch] = useState('')
  const [picked, setPicked] = useState<Lead | null>(null)
  const guess = lead.email?.split('@')[0] ?? lead.last_name ?? lead.first_name
  const { data } = useLeadsQuery({ search: search || guess, per_page: 8 }, { skip: !open })
  const candidates = useMemo(() => (data?.data ?? []).filter((l) => l.id !== lead.id && !l.converted_at), [data, lead.id])

  useEffect(() => { if (open) { setSearch(''); setPicked(null) } }, [open])

  const submit = async () => {
    if (!picked) return
    const r = await run(merge({ id: lead.id, duplicate_id: picked.id }), `Merged ${picked.full_name} into ${lead.full_name}`)
    if (r) onClose()
  }

  return (
    <Modal open={open} onClose={onClose} size="lg" title="Merge duplicate" description={`Pick the lead to merge into ${lead.full_name}. Empty fields are filled, history moves over, the duplicate goes to the recycle bin.`}
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} disabled={!picked} loading={isLoading} icon={<GitMerge className="size-4" />}>Merge</Button></>}>
      <Input icon={<Search className="size-4" />} placeholder="Search leads…" value={search} onChange={(e) => setSearch(e.target.value)} autoFocus />
      <ul className="mt-3 space-y-2">
        {candidates.map((c) => (
          <li key={c.id}>
            <button type="button" onClick={() => setPicked(c)} className={clsx('flex w-full items-center gap-3 rounded-2xl border p-3 text-left transition', picked?.id === c.id ? 'border-brand-400 bg-brand-50 dark:bg-brand-500/10' : 'border-slate-200 hover:border-brand-200 dark:border-white/10')}>
              <Avatar name={c.full_name} color={c.status?.color} size="sm" />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium text-slate-900 dark:text-white">{c.full_name}</span>
                <span className="block truncate text-xs text-slate-500">{[c.email, c.phone, c.company].filter(Boolean).join(' · ')}</span>
              </span>
              <StatusBadge status={c.status} />
            </button>
          </li>
        ))}
        {!candidates.length && <li className="py-8 text-center text-sm text-slate-500">No matching leads.</li>}
      </ul>
    </Modal>
  )
}

export function LostModal({ lead, status, open, onClose }: { lead: Lead; status: LeadStatus | null; open: boolean; onClose: () => void }) {
  const run = useAction()
  const [change, { isLoading }] = useChangeLeadStatusMutation()
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const reasons = ['Budget', 'Went with competitor', 'No response', 'Timing', 'Not a fit', 'Duplicate']

  useEffect(() => { if (open) { setReason(lead.lost_reason ?? ''); setNote('') } }, [open, lead])
  if (!status) return null

  const submit = async () => {
    const r = await run(change({ id: lead.id, lead_status_id: status.id, lost_reason: reason, note: note || undefined }), `Marked as ${status.name}`)
    if (r) onClose()
  }

  return (
    <Modal open={open} onClose={onClose} size="sm" title={`Mark as ${status.name}`} footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button variant="danger" onClick={submit} disabled={!reason} loading={isLoading}>Confirm</Button></>}>
      <Field label="Reason" required>
        <div className="mb-2 flex flex-wrap gap-1.5">
          {reasons.map((r) => <button key={r} type="button" onClick={() => setReason(r)} className={clsx('chip', reason === r && 'border-rose-300 bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-200')}>{r}</button>)}
        </div>
        <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Or type a reason" />
      </Field>
      <Field label="Note" className="mt-4"><Textarea rows={2} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
    </Modal>
  )
}

export function EnrollModal({ lead, open, onClose }: { lead: Lead; open: boolean; onClose: () => void }) {
  const run = useAction()
  const { data: meta } = useMetaQuery()
  const [enroll, { isLoading }] = useEnrollMutation()
  const [picked, setPicked] = useState<number | null>(null)

  useEffect(() => { if (open) setPicked(meta?.sequences[0]?.id ?? null) }, [open, meta])

  const submit = async () => {
    if (!picked) return
    const r = await run(enroll({ id: lead.id, sequence_id: picked }), 'Enrolled — steps scheduled as tasks')
    if (r) onClose()
  }

  return (
    <Modal open={open} onClose={onClose} size="lg" title="Enroll in sequence" description="Each step becomes a dated task for the lead owner."
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} disabled={!picked} loading={isLoading}>Enroll</Button></>}>
      <div className="space-y-3">
        {meta?.sequences.map((s) => (
          <button key={s.id} type="button" onClick={() => setPicked(s.id)} className={clsx('w-full rounded-2xl border p-4 text-left transition', picked === s.id ? 'border-brand-400 bg-brand-50/70 shadow-[0_10px_30px_-14px_rgba(139,92,246,0.6)] dark:bg-brand-500/10' : 'border-slate-200 hover:border-brand-200 dark:border-white/10')}>
            <div className="flex items-center justify-between">
              <p className="font-medium text-slate-900 dark:text-white">{s.name}</p>
              <Badge>{s.steps.length} steps</Badge>
            </div>
            {s.description && <p className="mt-0.5 text-sm text-slate-500">{s.description}</p>}
            <div className="mt-3 flex flex-wrap items-center gap-1.5">
              {s.steps.map((st, i) => (
                <span key={i} className="chip"><CalendarDays className="size-3" />Day {st.day_offset} · {st.title}</span>
              ))}
            </div>
          </button>
        ))}
        {!meta?.sequences.length && <p className="py-8 text-center text-sm text-slate-500">No active sequences. Create one in Playbooks.</p>}
      </div>
    </Modal>
  )
}

export function MessageModal({ lead, open, onClose }: { lead: Lead; open: boolean; onClose: () => void }) {
  const toast = useToast()
  const { data: meta } = useMetaQuery()
  const [send, { isLoading }] = useSendMessageMutation()
  const [channel, setChannel] = useState<'sms' | 'whatsapp'>('whatsapp')
  const [body, setBody] = useState('')
  useEffect(() => { if (open) setBody('Hi {first_name}, ') }, [open])
  const logOnly = meta?.features.messaging_driver !== 'twilio'

  const submit = async () => {
    try {
      const r = await send({ id: lead.id, channel, body }).unwrap()
      toast('success', r.message)
      onClose()
    } catch (e) { toast('error', errorMessage(e)) }
  }

  return (
    <Modal open={open} onClose={onClose} title="Send a message" description={<>To {lead.full_name} · {lead.phone}</>}
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} disabled={!body.trim()} loading={isLoading}>Send {channel === 'sms' ? 'SMS' : 'WhatsApp'}</Button></>}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-2">
          {([['whatsapp', 'WhatsApp', MessageCircle, '#22c55e'], ['sms', 'SMS', MessageSquare, '#0ea5e9']] as const).map(([v, l, Icon, c]) => (
            <button key={v} type="button" onClick={() => setChannel(v)} className={clsx('flex items-center justify-center gap-2 rounded-2xl border p-3 text-sm font-medium transition', channel === v ? 'shadow-[0_8px_24px_-12px_var(--c)]' : 'border-slate-200 text-slate-500 dark:border-white/10')}
              style={channel === v ? { borderColor: c, color: c, backgroundColor: `${c}14`, ['--c' as string]: c } : undefined}>
              <Icon className="size-4" />{l}
            </button>
          ))}
        </div>
        <Field label="Message" hint={`${body.length} characters · merge fields like {first_name}, {company} are filled in`}>
          <Textarea rows={5} value={body} onChange={(e) => setBody(e.target.value)} maxLength={1600} />
        </Field>
        {logOnly && (
          <p className="flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
            <AlertTriangle className="mt-0.5 size-3.5 shrink-0" /> No SMS provider is configured, so the message is recorded on the timeline but not delivered. Set MESSAGING_DRIVER=twilio on the server to send for real.
          </p>
        )}
      </div>
    </Modal>
  )
}

export function AiBriefPanel({ leadId }: { leadId: number }) {
  const { data: meta } = useMetaQuery()
  const [generate, { data, isLoading, error, reset }] = useAiBriefMutation()
  useEffect(() => { reset() }, [leadId, reset])

  if (!meta?.features.ai) {
    return <p className="mt-4 flex items-center gap-1.5 text-[11px] text-slate-400"><Wand2 className="size-3.5" /> AI briefs turn on when an Anthropic API key is added to the server.</p>
  }

  if (!data) {
    return (
      <div className="mt-4 flex flex-wrap items-center gap-3">
        <Button size="sm" variant="subtle" icon={<Wand2 className="size-4" />} loading={isLoading} onClick={() => generate({ id: leadId })}>Generate AI brief</Button>
        {error ? <span className="text-xs text-rose-600">{errorMessage(error)}</span> : <span className="text-xs text-slate-500">Claude reads the timeline, notes and qualification to coach your next step.</span>}
      </div>
    )
  }

  return (
    <div className="mt-5 animate-fade-in rounded-2xl border border-fuchsia-200/70 bg-gradient-to-br from-fuchsia-50/70 via-white/40 to-cyan-50/60 p-4 dark:border-fuchsia-500/20 dark:from-fuchsia-500/10 dark:via-transparent dark:to-cyan-500/5">
      <div className="flex items-center justify-between">
        <p className="flex items-center gap-1.5 text-[11px] font-bold tracking-[0.12em] text-fuchsia-600 uppercase dark:text-fuchsia-300"><Wand2 className="size-3.5" /> AI brief</p>
        <button onClick={() => generate({ id: leadId, refresh: true })} className="flex items-center gap-1 text-xs text-slate-500 hover:text-brand-600" disabled={isLoading}>
          <RefreshCw className={clsx('size-3.5', isLoading && 'animate-spin')} /> Regenerate
        </button>
      </div>
      <p className="mt-2 text-sm leading-relaxed text-slate-700 dark:text-slate-200">{data.summary}</p>
      <p className="mt-3 text-sm"><span className="font-semibold text-slate-900 dark:text-white">Next: {data.next_action_title}.</span> <span className="text-slate-600 dark:text-slate-400">{data.next_action_reason}</span></p>
      {!!data.talking_points.length && (
        <ul className="mt-3 space-y-1">{data.talking_points.map((t, i) => <li key={i} className="flex gap-2 text-sm text-slate-700 dark:text-slate-300"><span className="text-fuchsia-500">•</span>{t}</li>)}</ul>
      )}
      {data.risk && <p className="mt-3 flex items-start gap-1.5 text-xs text-rose-600"><AlertTriangle className="mt-0.5 size-3.5 shrink-0" />{data.risk}</p>}
      <p className="mt-3 text-[10px] text-slate-400">Generated by {data.model} · {ago(data.generated_at)} · AI can make mistakes; verify before acting.</p>
    </div>
  )
}
