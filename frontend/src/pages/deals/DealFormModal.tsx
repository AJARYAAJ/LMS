import { useEffect, useState } from 'react'
import { useAction } from '@/app/hooks'
import { useAccountsQuery, useContactsQuery, useMetaQuery, useSaveDealMutation } from '@/services/api'
import { Button, Field, Input, Modal, Select, Textarea } from '@/components/ui'
import type { Deal } from '@/types'
import { CustomFieldInputs } from '@/components/crm/RecordPanels'

export function DealFormModal({ open, onClose, deal, defaults }: { open: boolean; onClose: () => void; deal?: Deal | null; defaults?: Partial<Deal> }) {
  const run = useAction()
  const { data: meta } = useMetaQuery()
  const { data: accounts } = useAccountsQuery({ per_page: 100 }, { skip: !open })
  const { data: contacts } = useContactsQuery({ per_page: 100 }, { skip: !open })
  const [save, { isLoading }] = useSaveDealMutation()
  const [form, setForm] = useState<Record<string, string>>({})
  const [custom, setCustom] = useState<Record<string, unknown>>({})

  useEffect(() => {
    if (!open) return
    const d = { ...defaults, ...deal }
    setForm({
      name: d.name ?? '', amount: d.amount ?? '', pipeline_stage_id: String(d.pipeline_stage_id ?? meta?.stages[0]?.id ?? ''),
      account_id: String(d.account_id ?? ''), contact_id: String(d.contact_id ?? ''), owner_id: String(d.owner_id ?? ''),
      expected_close_date: d.expected_close_date?.slice(0, 10) ?? '', description: d.description ?? '',
    })
    setCustom(d.custom_fields ?? {})
  }, [open, deal, defaults, meta])

  const set = (k: string) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))

  const submit = async () => {
    const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : ['amount'].includes(k) ? Number(v) : ['pipeline_stage_id', 'account_id', 'contact_id', 'owner_id'].includes(k) ? Number(v) : v]))
    if (body.amount === null) delete body.amount
    body.custom_fields = custom as never
    const r = await run(save({ ...(deal ? { id: deal.id } : {}), ...body } as Partial<Deal>), deal ? 'Deal updated' : 'Deal created')
    if (r) onClose()
  }

  return (
    <Modal open={open} onClose={onClose} size="lg" title={deal ? 'Edit deal' : 'New deal'}
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} disabled={!form.name} loading={isLoading}>{deal ? 'Save' : 'Create deal'}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Deal name" required className="sm:col-span-2"><Input autoFocus value={form.name ?? ''} onChange={set('name')} /></Field>
        <Field label="Amount"><Input type="number" min={0} value={form.amount ?? ''} onChange={set('amount')} /></Field>
        <Field label="Stage"><Select value={form.pipeline_stage_id ?? ''} onChange={set('pipeline_stage_id')}>{meta?.stages.map((s) => <option key={s.id} value={s.id}>{s.name} · {s.probability}%</option>)}</Select></Field>
        <Field label="Account"><Select value={form.account_id ?? ''} onChange={set('account_id')} placeholder="—">{accounts?.data.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select></Field>
        <Field label="Contact"><Select value={form.contact_id ?? ''} onChange={set('contact_id')} placeholder="—">{contacts?.data.map((c) => <option key={c.id} value={c.id}>{c.first_name} {c.last_name}</option>)}</Select></Field>
        <Field label="Owner"><Select value={form.owner_id ?? ''} onChange={set('owner_id')} placeholder="Me">{meta?.users.filter((u) => u.role !== 'viewer').map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</Select></Field>
        <Field label="Expected close"><Input type="date" value={form.expected_close_date ?? ''} onChange={set('expected_close_date')} /></Field>
        <CustomFieldInputs entity="deal" value={custom} onChange={setCustom} />
        <Field label="Description" className="sm:col-span-2"><Textarea rows={3} value={form.description ?? ''} onChange={set('description')} /></Field>
      </div>
    </Modal>
  )
}

const LOST_REASONS = ['Price', 'Went with competitor', 'No decision', 'Timing', 'Lost contact', 'Not a fit']

export function LostDealModal({ open, onClose, onConfirm }: { open: boolean; onClose: () => void; onConfirm: (reason: string) => void }) {
  const [reason, setReason] = useState('')
  useEffect(() => { if (open) setReason('') }, [open])
  return (
    <Modal open={open} onClose={onClose} size="sm" title="Why was this deal lost?"
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button variant="danger" disabled={!reason} onClick={() => onConfirm(reason)}>Mark as lost</Button></>}>
      <div className="mb-3 flex flex-wrap gap-1.5">
        {LOST_REASONS.map((r) => <button key={r} type="button" onClick={() => setReason(r)} className={`chip ${reason === r ? 'border-rose-300 bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-200' : ''}`}>{r}</button>)}
      </div>
      <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Or type a reason" />
    </Modal>
  )
}
