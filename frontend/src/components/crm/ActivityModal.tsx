import { useEffect, useState } from 'react'
import clsx from 'clsx'
import { Calendar, Mail, MessageCircle, MessageSquare, Phone } from 'lucide-react'
import { useAction } from '@/app/hooks'
import { useLogActivityMutation, type SubjectType } from '@/services/api'
import { Button, Field, Input, Modal, Select, Textarea } from '@/components/ui'
import { fromLocalInput, toLocalInput } from '@/lib/format'

const types = [
  { value: 'call', label: 'Call', icon: Phone, outcomes: ['Connected', 'Left voicemail', 'No answer', 'Wrong number', 'Interested', 'Not interested'] },
  { value: 'email', label: 'Email', icon: Mail, outcomes: ['Sent', 'Replied', 'Bounced'] },
  { value: 'meeting', label: 'Meeting', icon: Calendar, outcomes: ['Held', 'No-show', 'Rescheduled'] },
  { value: 'sms', label: 'SMS', icon: MessageSquare, outcomes: ['Sent', 'Replied'] },
  { value: 'whatsapp', label: 'WhatsApp', icon: MessageCircle, outcomes: ['Sent', 'Replied'] },
]

export function ActivityModal({ open, onClose, subjectType, subjectId, initialType = 'call' }: {
  open: boolean
  onClose: () => void
  subjectType: SubjectType
  subjectId: number
  initialType?: string
}) {
  const run = useAction()
  const [log, { isLoading }] = useLogActivityMutation()
  const [form, setForm] = useState({ type: initialType, title: '', description: '', outcome: '', direction: 'outbound', duration_minutes: '', occurred_at: '', next_follow_up_at: '' })

  useEffect(() => {
    if (open) setForm({ type: initialType, title: '', description: '', outcome: '', direction: 'outbound', duration_minutes: '', occurred_at: toLocalInput(new Date().toISOString()), next_follow_up_at: '' })
  }, [open, initialType])

  const current = types.find((t) => t.value === form.type) ?? types[0]
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))

  const submit = async () => {
    const r = await run(log({
      subject: subjectType,
      id: subjectId,
      type: form.type,
      title: form.title || `${current.label}${form.outcome ? ` — ${form.outcome}` : ''}`,
      description: form.description || null,
      direction: form.direction,
      outcome: form.outcome || null,
      duration_minutes: form.duration_minutes ? Number(form.duration_minutes) : null,
      occurred_at: fromLocalInput(form.occurred_at),
      next_follow_up_at: subjectType === 'leads' ? fromLocalInput(form.next_follow_up_at) : undefined,
    }), 'Activity logged')
    if (r) onClose()
  }

  return (
    <Modal open={open} onClose={onClose} title="Log activity" footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} loading={isLoading}>Log {current.label.toLowerCase()}</Button></>}>
      <div className="space-y-4">
        <div className="grid grid-cols-5 gap-2">
          {types.map((t) => (
            <button key={t.value} type="button" onClick={() => setForm((f) => ({ ...f, type: t.value, outcome: '' }))}
              className={clsx('flex flex-col items-center gap-1.5 rounded-2xl border p-3 text-xs font-medium transition-all',
                form.type === t.value ? 'border-brand-400 bg-brand-50 text-brand-700 shadow-[0_6px_20px_-8px_rgba(139,92,246,0.6)] dark:bg-brand-500/15 dark:text-brand-200' : 'border-slate-200 text-slate-500 hover:border-brand-200 dark:border-white/10')}>
              <t.icon className="size-5" />{t.label}
            </button>
          ))}
        </div>
        <Field label="Outcome">
          <div className="flex flex-wrap gap-1.5">
            {current.outcomes.map((o) => (
              <button key={o} type="button" onClick={() => setForm((f) => ({ ...f, outcome: f.outcome === o ? '' : o }))}
                className={clsx('chip transition', form.outcome === o && 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>{o}</button>
            ))}
          </div>
        </Field>
        <Field label="Summary"><Input value={form.title} onChange={set('title')} placeholder={`${current.label} summary (optional)`} /></Field>
        <Field label="Details"><Textarea value={form.description} onChange={set('description')} rows={3} placeholder="Key points, objections, agreed next steps…" /></Field>
        <div className="grid grid-cols-3 gap-3">
          <Field label="Direction">
            <Select value={form.direction} onChange={set('direction')}><option value="outbound">Outbound</option><option value="inbound">Inbound</option></Select>
          </Field>
          <Field label="Minutes"><Input type="number" min={0} value={form.duration_minutes} onChange={set('duration_minutes')} /></Field>
          <Field label="When"><Input type="datetime-local" value={form.occurred_at} onChange={set('occurred_at')} /></Field>
        </div>
        {subjectType === 'leads' && (
          <Field label="Schedule next follow-up" hint="Updates the lead's next follow-up date.">
            <Input type="datetime-local" value={form.next_follow_up_at} onChange={set('next_follow_up_at')} />
          </Field>
        )}
      </div>
    </Modal>
  )
}
