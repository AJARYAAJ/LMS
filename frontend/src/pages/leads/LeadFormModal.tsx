import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertTriangle } from 'lucide-react'
import { useAction, usePermissions } from '@/app/hooks'
import { fieldErrors, useCreateLeadMutation, useMetaQuery, useUpdateLeadMutation } from '@/services/api'
import { Button, Field, Input, Modal, Select, Textarea } from '@/components/ui'
import { StatusBadge } from '@/components/crm/Badges'
import { fromLocalInput, humanize, toLocalInput } from '@/lib/format'
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
    next_follow_up_at: toLocalInput(lead.next_follow_up_at),
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

  const set = (key: string) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const setCustom = (key: string, value: unknown) => setForm((f) => ({ ...f, custom_fields: { ...(f.custom_fields as object), [key]: value } }))

  const payload = (allowDuplicate = false) => {
    const body: Record<string, unknown> = {}
    for (const [k, v] of Object.entries(form)) {
      if (k === 'tag_ids' || k === 'custom_fields') body[k] = v
      else body[k] = v === '' ? null : v
    }
    body.next_follow_up_at = fromLocalInput(form.next_follow_up_at as string)
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

  const toggleTag = (id: number) =>
    setForm((f) => {
      const ids = f.tag_ids as number[]
      return { ...f, tag_ids: ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id] }
    })

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

        <section>
          <h4 className="mb-3 text-xs font-semibold tracking-wider text-slate-400 uppercase">Contact</h4>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Field label="First name" error={errors.first_name} required><Input value={form.first_name as string} onChange={set('first_name')} autoFocus required /></Field>
            <Field label="Last name" error={errors.last_name}><Input value={form.last_name as string} onChange={set('last_name')} /></Field>
            <Field label="Job title"><Input value={form.job_title as string} onChange={set('job_title')} /></Field>
            <Field label="Email" error={errors.email}><Input type="email" value={form.email as string} onChange={set('email')} /></Field>
            <Field label="Phone" error={errors.phone}><Input value={form.phone as string} onChange={set('phone')} /></Field>
            <Field label="City"><Input value={form.city as string} onChange={set('city')} /></Field>
          </div>
        </section>

        <section>
          <h4 className="mb-3 text-xs font-semibold tracking-wider text-slate-400 uppercase">Company</h4>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Field label="Company"><Input value={form.company as string} onChange={set('company')} /></Field>
            <Field label="Website"><Input value={form.website as string} onChange={set('website')} placeholder="acme.com" /></Field>
            <Field label="Industry"><Input value={form.industry as string} onChange={set('industry')} /></Field>
            <Field label="Company size">
              <Select value={form.company_size as string} onChange={set('company_size')} placeholder="—">
                {['1-10', '11-50', '51-200', '201-1000', '1000+'].map((s) => <option key={s}>{s}</option>)}
              </Select>
            </Field>
            <Field label="State / region"><Input value={form.state as string} onChange={set('state')} /></Field>
            <Field label="Country"><Input value={form.country as string} onChange={set('country')} /></Field>
          </div>
        </section>

        <section>
          <h4 className="mb-3 text-xs font-semibold tracking-wider text-slate-400 uppercase">Pipeline & qualification</h4>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Field label="Status">
              <Select value={form.lead_status_id as string} onChange={set('lead_status_id')} placeholder={lead ? undefined : 'Default'}>
                {meta?.statuses.filter((s) => s.is_active).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </Select>
            </Field>
            <Field label="Source">
              <Select value={form.lead_source_id as string} onChange={set('lead_source_id')} placeholder="—">
                {meta?.sources.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </Select>
            </Field>
            <Field label="Campaign">
              <Select value={form.campaign_id as string} onChange={set('campaign_id')} placeholder="—">
                {meta?.campaigns.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </Select>
            </Field>
            {manager && (
              <Field label="Owner" hint={!lead ? 'Leave empty to use assignment rules' : undefined}>
                <Select value={form.owner_id as string} onChange={set('owner_id')} placeholder={lead ? 'Unassigned' : 'Auto-assign'}>
                  {meta?.users.filter((u) => u.role !== 'viewer').map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                </Select>
              </Field>
            )}
            <Field label="Team">
              <Select value={form.team_id as string} onChange={set('team_id')} placeholder="—">
                {meta?.teams.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
              </Select>
            </Field>
            <Field label="Priority">
              <Select value={form.priority as string} onChange={set('priority')}>
                {meta?.enums.priorities.map((p) => <option key={p} value={p}>{humanize(p)}</option>)}
              </Select>
            </Field>
            <Field label="Budget" error={errors.budget}><Input type="number" min={0} value={form.budget as string} onChange={set('budget')} /></Field>
            <Field label="Expected value" error={errors.expected_value}><Input type="number" min={0} value={form.expected_value as string} onChange={set('expected_value')} /></Field>
            <Field label="Timeline">
              <Select value={form.timeline as string} onChange={set('timeline')} placeholder="—">
                {['This month', 'This quarter', 'Next quarter', '6+ months'].map((t) => <option key={t}>{t}</option>)}
              </Select>
            </Field>
            <Field label="Next follow-up"><Input type="datetime-local" value={form.next_follow_up_at as string} onChange={set('next_follow_up_at')} /></Field>
          </div>
          <Field label="Requirements" className="mt-4"><Textarea value={form.requirements as string} onChange={set('requirements')} rows={3} placeholder="What does the lead need? Pain points, use-case, decision process…" /></Field>
          {!!meta?.tags.length && (
            <Field label="Tags" className="mt-4">
              <div className="flex flex-wrap gap-2">
                {meta.tags.map((t) => {
                  const on = (form.tag_ids as number[]).includes(t.id)
                  return (
                    <button key={t.id} type="button" onClick={() => toggleTag(t.id)}
                      className="rounded-full border px-3 py-1 text-xs font-medium transition"
                      style={on ? { backgroundColor: `${t.color}1f`, borderColor: t.color, color: t.color } : undefined}>
                      {on ? '✓ ' : ''}{t.name}
                    </button>
                  )
                })}
              </div>
            </Field>
          )}
        </section>

        {!!meta?.custom_fields.filter((f) => f.entity === 'lead').length && (
          <section>
            <h4 className="mb-3 text-xs font-semibold tracking-wider text-slate-400 uppercase">Additional fields</h4>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {meta.custom_fields.filter((f) => f.entity === 'lead').map((f) => {
                const value = (form.custom_fields as Record<string, unknown>)[f.key]
                return (
                  <Field key={f.id} label={f.label} required={f.is_required}>
                    {f.type === 'select' ? (
                      <Select value={String(value ?? '')} onChange={(e) => setCustom(f.key, e.target.value)} placeholder="—">
                        {f.options?.map((o) => <option key={o}>{o}</option>)}
                      </Select>
                    ) : f.type === 'boolean' ? (
                      <Select value={value === true ? 'yes' : value === false ? 'no' : ''} onChange={(e) => setCustom(f.key, e.target.value === '' ? null : e.target.value === 'yes')} placeholder="—">
                        <option value="yes">Yes</option><option value="no">No</option>
                      </Select>
                    ) : f.type === 'textarea' ? (
                      <Textarea value={String(value ?? '')} onChange={(e) => setCustom(f.key, e.target.value)} rows={2} />
                    ) : (
                      <Input type={f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'} value={String(value ?? '')} onChange={(e) => setCustom(f.key, e.target.value)} required={f.is_required} />
                    )}
                  </Field>
                )
              })}
            </div>
          </section>
        )}
        <button type="submit" className="hidden" />
      </form>
    </Modal>
  )
}
