import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertTriangle } from 'lucide-react'
import { useAction, usePermissions } from '@/app/hooks'
import { fieldErrors, useCreateLeadMutation, useMetaQuery, useUpdateLeadMutation } from '@/services/api'
import { Button, Modal } from '@/components/ui'
import { StatusBadge } from '@/components/crm/Badges'
import { LayoutFields, type LayoutValues } from '@/lib/layouts'
import type { Lead } from '@/types'

type FormState = Record<string, string | number[] | Record<string, unknown>>

const empty: FormState = {
  first_name: '', last_name: '', email: '', phone: '', company: '', job_title: '', website: '', industry: '',
  company_size: '', city: '', state: '', country: '', lead_status_id: '', lead_source_id: '', campaign_id: '',
  owner_id: '', team_id: '', priority: 'medium', budget: '', expected_value: '', timeline: '', requirements: '',
  next_follow_up_at: '', tag_ids: [], custom_fields: {},
}

function fromLead(lead: Lead): FormState {
  const s = (v: unknown) => (v === null || v === undefined ? '' : String(v))
  return {
    ...Object.fromEntries(Object.keys(empty).map((k) => [k, s((lead as unknown as Record<string, unknown>)[k])])),
    next_follow_up_at: lead.next_follow_up_at ?? '',
    tag_ids: lead.tags?.map((t) => t.id) ?? [],
    custom_fields: lead.custom_fields ?? {},
  }
}

export function LeadFormModal({ open, onClose, lead }: { open: boolean; onClose: () => void; lead?: Lead }) {
  const navigate = useNavigate()
  const run = useAction()
  const { manager } = usePermissions()
  const { data: meta } = useMetaQuery()
  const [createLead, createState] = useCreateLeadMutation()
  const [updateLead, updateState] = useUpdateLeadMutation()
  const [form, setForm] = useState<FormState>(empty)
  const [duplicates, setDuplicates] = useState<Lead[] | null>(null)
  const errors = fieldErrors(createState.error ?? updateState.error)

  useEffect(() => {
    if (open) {
      setForm(lead ? fromLead(lead) : empty)
      setDuplicates(null)
    }
  }, [open, lead])


  const payload = (allowDuplicate = false) => {
    const body: Record<string, unknown> = {}
    for (const [k, v] of Object.entries(form)) {
      if (k === 'tag_ids' || k === 'custom_fields') body[k] = v
      else body[k] = v === '' ? null : v
    }
    body.next_follow_up_at = form.next_follow_up_at || null
    if (!lead) {
      for (const k of ['lead_status_id', 'owner_id']) if (body[k] === null) delete body[k]
      if (allowDuplicate) body.allow_duplicate = true
    } else {
      if (!manager) delete body.owner_id
    }
    return body
  }

  const submit = async (e?: FormEvent, allowDuplicate = false) => {
    e?.preventDefault()
    if (lead) {
      const result = await run(updateLead({ id: lead.id, ...payload() }), 'Lead updated')
      if (result) onClose()
      return
    }
    try {
      const created = await createLead(payload(allowDuplicate)).unwrap()
      onClose()
      navigate(`/leads/${created.id}`)
    } catch (err) {
      const e = err as { status?: number; data?: { duplicates?: Lead[] } }
      if (e.status === 409) setDuplicates(e.data?.duplicates ?? [])
    }
  }

  const loading = createState.isLoading || updateState.isLoading

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="xl"
      title={lead ? `Edit ${lead.full_name}` : 'New lead'}
      description={lead ? undefined : 'Capture a new lead. Scoring and assignment rules run automatically.'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          {duplicates && <Button variant="secondary" onClick={() => submit(undefined, true)} loading={loading}>Create anyway</Button>}
          <Button onClick={() => submit()} loading={loading && !duplicates}>{lead ? 'Save changes' : 'Create lead'}</Button>
        </>
      }
    >
      <form onSubmit={submit} className="space-y-6">
        {duplicates && (
          <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
            <p className="flex items-center gap-2 text-sm font-medium text-amber-800 dark:text-amber-300"><AlertTriangle className="size-4" /> Possible duplicate{duplicates.length > 1 && 's'} found</p>
            <ul className="mt-2 space-y-1.5">
              {duplicates.map((d) => (
                <li key={d.id} className="flex items-center justify-between gap-3 text-sm">
                  <button type="button" onClick={() => { onClose(); navigate(`/leads/${d.id}`) }} className="font-medium text-amber-900 underline-offset-2 hover:underline dark:text-amber-200">
                    {d.first_name} {d.last_name} · {d.email ?? d.phone}
                  </button>
                  <StatusBadge status={d.status} />
                </li>
              ))}
            </ul>
          </div>
        )}

        <LayoutFields
          entity="lead"
          meta={meta}
          values={form as LayoutValues}
          errors={errors}
          required={['first_name']}
          omit={manager ? [] : ['owner_id']}
          placeholders={lead ? {} : { lead_status_id: 'Default', owner_id: 'Auto-assign (rules)' }}
          onChange={(key, value) => setForm((f) => key.startsWith('custom.')
            ? { ...f, custom_fields: { ...(f.custom_fields as object), [key.slice(7)]: value } }
            : { ...f, [key]: value as string })}
        />
        {!lead && <p className="-mt-2 text-xs text-slate-500">Layout and field order are configurable in Settings → Page layouts.</p>}
        <button type="submit" className="hidden" />
      </form>
    </Modal>
  )
}
