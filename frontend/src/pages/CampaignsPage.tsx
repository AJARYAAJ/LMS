import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { GitBranch, Mail, Megaphone, Pencil, Plus, Trash2 } from 'lucide-react'
import { useAction, useAppSelector, usePermissions } from '@/app/hooks'
import { resources, useDeleteSettingMutation, useMetaQuery, useSaveSettingMutation, useSettings } from '@/services/api'
import { Badge, Button, ConfirmDialog, EmptyState, Field, Input, Modal, PageHeader, PageLoader, Select, Tabs, Textarea } from '@/components/ui'
import { date, money, percent } from '@/lib/format'
import type { Campaign } from '@/types'
import { EmailCampaigns } from '@/pages/marketing/EmailCampaigns'
import { Attribution } from '@/pages/marketing/Attribution'

const statusColor: Record<string, string> = { planned: '#64748b', active: '#10b981', paused: '#f59e0b', completed: '#8b5cf6' }

function CampaignCards() {
  const run = useAction()
  const { manager } = usePermissions()
  const currency = useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
  const { data, isLoading } = useSettings(resources.campaigns)
  const { data: meta } = useMetaQuery()
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [editing, setEditing] = useState<Partial<Campaign> | null>(null)
  const [deleting, setDeleting] = useState<Campaign | null>(null)
  const [form, setForm] = useState<Record<string, string>>({})

  useEffect(() => {
    if (editing) setForm(Object.fromEntries(['name', 'lead_source_id', 'channel', 'status', 'budget', 'actual_cost', 'starts_on', 'ends_on', 'description']
      .map((k) => [k, String((editing as Record<string, unknown>)[k] ?? (k === 'status' ? 'active' : '')).slice(0, k.endsWith('_on') ? 10 : undefined)])))
  }, [editing])

  const set = (k: string) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))
  const submit = async () => {
    const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : ['budget', 'actual_cost', 'lead_source_id'].includes(k) ? Number(v) : v]))
    if (await run(save({ ...resources.campaigns, id: editing?.id, body }), 'Campaign saved')) setEditing(null)
  }

  return (
    <div>
      {manager && <div className="mb-4 flex justify-end"><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>New campaign</Button></div>}
      {isLoading ? <PageLoader /> : !data?.length ? <div className="card"><EmptyState icon={<Megaphone />} title="No campaigns yet" /></div> : (
        <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
          {data.map((c) => {
            const conv = c.leads_count ? ((c.converted_count ?? 0) / c.leads_count) * 100 : 0
            const cost = Number(c.actual_cost ?? c.budget ?? 0)
            return (
              <div key={c.id} className="card group p-6">
                <div className="flex items-start justify-between">
                  <Badge color={statusColor[c.status]} dot>{c.status}</Badge>
                  {manager && <div className="flex gap-1 opacity-0 transition group-hover:opacity-100">
                    <button onClick={() => setEditing(c)} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label="Edit"><Pencil className="size-4" /></button>
                    <button onClick={() => setDeleting(c)} className="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label="Delete"><Trash2 className="size-4" /></button>
                  </div>}
                </div>
                <h3 className="mt-3 text-lg font-bold text-slate-900 dark:text-white">{c.name}</h3>
                <p className="text-sm text-slate-500">{[c.channel, c.source?.name].filter(Boolean).join(' · ') || '—'}</p>
                <div className="mt-5 grid grid-cols-3 gap-2 text-center">
                  {[['Leads', c.leads_count ?? 0], ['Converted', c.converted_count ?? 0], ['Conv.', percent(conv, 0)]].map(([l, v]) => (
                    <div key={l as string} className="rounded-2xl bg-slate-900/[0.03] py-2.5 dark:bg-white/[0.04]"><p className="font-display text-lg font-bold text-slate-900 dark:text-white">{v}</p><p className="text-[11px] text-slate-500">{l}</p></div>
                  ))}
                </div>
                <div className="mt-4 flex items-center justify-between text-sm">
                  <span className="text-slate-500">Cost / lead</span>
                  <span className="font-display font-semibold">{c.leads_count && cost ? money(cost / c.leads_count, currency) : '—'}</span>
                </div>
                <div className="mt-1 flex items-center justify-between text-sm">
                  <span className="text-slate-500">Pipeline value</span>
                  <span className="font-display font-semibold">{money(c.pipeline_value ?? 0, currency, true)}</span>
                </div>
                <p className="mt-4 text-xs text-slate-400">{date(c.starts_on)} → {date(c.ends_on)}</p>
                <Link to={`/leads?campaign_id=${c.id}`} className="mt-3 inline-block text-xs font-medium text-brand-600 hover:underline">View leads →</Link>
              </div>
            )
          })}
        </div>
      )}
      <Modal open={!!editing} onClose={() => setEditing(null)} size="lg" title={editing?.id ? 'Edit campaign' : 'New campaign'}
        footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button onClick={submit} disabled={!form.name} loading={saveState.isLoading}>Save</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Name" required className="sm:col-span-2"><Input value={form.name ?? ''} onChange={set('name')} /></Field>
          <Field label="Source"><Select value={form.lead_source_id ?? ''} onChange={set('lead_source_id')} placeholder="—">{meta?.sources.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select></Field>
          <Field label="Channel"><Input value={form.channel ?? ''} onChange={set('channel')} placeholder="webinar, paid social…" /></Field>
          <Field label="Status"><Select value={form.status ?? 'active'} onChange={set('status')}>{['planned', 'active', 'paused', 'completed'].map((s) => <option key={s}>{s}</option>)}</Select></Field>
          <Field label="Budget"><Input type="number" value={form.budget ?? ''} onChange={set('budget')} /></Field>
          <Field label="Actual cost"><Input type="number" value={form.actual_cost ?? ''} onChange={set('actual_cost')} /></Field>
          <Field label="Starts"><Input type="date" value={form.starts_on ?? ''} onChange={set('starts_on')} /></Field>
          <Field label="Ends"><Input type="date" value={form.ends_on ?? ''} onChange={set('ends_on')} /></Field>
          <Field label="Description" className="sm:col-span-2"><Textarea value={form.description ?? ''} onChange={set('description')} /></Field>
        </div>
      </Modal>
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} title={`Delete ${deleting?.name}?`} message="Leads keep their data but lose the campaign link."
        onConfirm={async () => { if (deleting) await run(remove({ ...resources.campaigns, id: deleting.id }), 'Campaign deleted'); setDeleting(null) }} />
    </div>
  )
}

type Tab = 'campaigns' | 'email' | 'attribution'

/** Marketing hub: campaigns, email sends with A/B tests, and multi-touch attribution. */
export function CampaignsPage() {
  const { manager } = usePermissions()
  const [params, setParams] = useSearchParams()
  const tabs: { value: Tab; label: string; icon: React.ReactNode }[] = [
    { value: 'campaigns', label: 'Campaigns', icon: <Megaphone /> },
    ...(manager ? [{ value: 'email' as const, label: 'Email campaigns', icon: <Mail /> }] : []),
    { value: 'attribution', label: 'Attribution', icon: <GitBranch /> },
  ]
  const tab = (tabs.some((t) => t.value === params.get('tab')) ? params.get('tab') : 'campaigns') as Tab
  return (
    <div>
      <PageHeader icon={<Megaphone />} title="Campaigns" description="Where leads come from, what each campaign costs, and what actually drives revenue." />
      <Tabs className="mb-6" tabs={tabs} value={tab} onChange={(t) => setParams(t === 'campaigns' ? {} : { tab: t })} />
      {tab === 'email' ? <EmailCampaigns /> : tab === 'attribution' ? <Attribution /> : <CampaignCards />}
    </div>
  )
}
