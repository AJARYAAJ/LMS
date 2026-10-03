import { useEffect, useState } from 'react'
import { useAction, useCurrentUser } from '@/app/hooks'
import { useCreateTaskMutation, useMetaQuery, useUpdateTaskMutation } from '@/services/api'
import { Button, Field, Input, Modal, Select, Textarea } from '@/components/ui'
import { fromLocalInput, humanize, toLocalInput } from '@/lib/format'
import type { Task } from '@/types'

export function TaskFormModal({ open, onClose, task, subject }: {
  open: boolean
  onClose: () => void
  task?: Task | null
  subject?: { type: 'lead' | 'deal' | 'contact' | 'account'; id: number; name: string }
}) {
  const run = useAction()
  const me = useCurrentUser()
  const { data: meta } = useMetaQuery()
  const [create, createState] = useCreateTaskMutation()
  const [update, updateState] = useUpdateTaskMutation()
  const [form, setForm] = useState({ title: '', description: '', type: 'follow_up', priority: 'medium', due_at: '', assigned_to: '' })

  useEffect(() => {
    if (!open) return
    const tomorrow = new Date(Date.now() + 86_400_000)
    tomorrow.setHours(10, 0, 0, 0)
    setForm(task ? {
      title: task.title, description: task.description ?? '', type: task.type, priority: task.priority,
      due_at: toLocalInput(task.due_at), assigned_to: String(task.assigned_to ?? ''),
    } : { title: '', description: '', type: 'follow_up', priority: 'medium', due_at: toLocalInput(tomorrow.toISOString()), assigned_to: String(me?.id ?? '') })
  }, [open, task, me])

  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))

  const submit = async () => {
    const body = {
      ...form,
      due_at: fromLocalInput(form.due_at),
      assigned_to: form.assigned_to ? Number(form.assigned_to) : null,
      ...(subject && !task ? { taskable_type: subject.type, taskable_id: subject.id } : {}),
    }
    const r = task ? await run(update({ id: task.id, ...body }), 'Task updated') : await run(create(body), 'Task created')
    if (r) onClose()
  }

  const quick = (days: number) => {
    const d = new Date(Date.now() + days * 86_400_000)
    d.setHours(10, 0, 0, 0)
    setForm((f) => ({ ...f, due_at: toLocalInput(d.toISOString()) }))
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={task ? 'Edit task' : 'New task'}
      description={subject ? `Linked to ${subject.name}` : undefined}
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} disabled={!form.title} loading={createState.isLoading || updateState.isLoading}>{task ? 'Save' : 'Create task'}</Button></>}
    >
      <div className="space-y-4">
        <Field label="What needs to happen?" required><Input autoFocus value={form.title} onChange={set('title')} placeholder="Call back to discuss pricing" /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Type">
            <Select value={form.type} onChange={set('type')}>{(meta?.enums.task_types ?? ['call', 'email', 'meeting', 'follow_up', 'todo']).map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</Select>
          </Field>
          <Field label="Priority">
            <Select value={form.priority} onChange={set('priority')}>{['low', 'medium', 'high', 'urgent'].map((p) => <option key={p} value={p}>{humanize(p)}</option>)}</Select>
          </Field>
        </div>
        <Field label="Due">
          <Input type="datetime-local" value={form.due_at} onChange={set('due_at')} />
          <div className="mt-2 flex flex-wrap gap-1.5">
            {[['Today', 0], ['Tomorrow', 1], ['In 3 days', 3], ['Next week', 7]].map(([l, d]) => (
              <button key={l} type="button" onClick={() => quick(d as number)} className="chip hover:border-brand-300 hover:text-brand-700">{l}</button>
            ))}
          </div>
        </Field>
        <Field label="Assignee">
          <Select value={form.assigned_to} onChange={set('assigned_to')} placeholder="Unassigned">
            {meta?.users.filter((u) => u.role !== 'viewer').map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </Select>
        </Field>
        <Field label="Notes"><Textarea value={form.description} onChange={set('description')} rows={2} /></Field>
      </div>
    </Modal>
  )
}
