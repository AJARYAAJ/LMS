import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Bot, CalendarCheck, Clock, Headphones, Pencil, PhoneCall, PhoneForwarded, Plus, Rocket, Trash2, X } from 'lucide-react'
import { useAction, usePermissions, useToast } from '@/app/hooks'
import {
  resources, useCallStatsQuery, useCallsQuery, useDeleteSettingMutation, useLaunchCampaignMutation, useMetaQuery, useSaveSettingMutation, useSettings,
} from '@/services/api'
import {
  Badge, Button, ConfirmDialog, EmptyState, Field, Input, Modal, PageHeader, PageLoader, Pagination, Segmented, Select, StatCard, Tabs, Textarea, Toggle,
} from '@/components/ui'
import { ConditionBuilder } from '@/components/crm/ConditionBuilder'
import { CallDrawer, CallStatusBadge, OUTCOMES, OutcomeBadge, duration } from '@/components/crm/Calls'
import { ago, humanize, percent } from '@/lib/format'
import type { AiAgent, Condition } from '@/types'

type Tab = 'calls' | 'agents'

export function CallsPage() {
  const [params, setParams] = useSearchParams()
  const tab = (params.get('tab') as Tab) ?? 'calls'
  const { manager } = usePermissions()
  const { data: stats } = useCallStatsQuery(30, { pollingInterval: 15_000, skipPollingIfUnfocused: true })
  const { data: agents } = useSettings(resources.aiAgents)
  const [campaignFor, setCampaignFor] = useState<AiAgent | 'pick' | null>(null)
  const [editing, setEditing] = useState<Partial<AiAgent> | null>(null)

  return (
    <div>
      <PageHeader icon={<PhoneCall />} title="AI calls" description="Voice agents that call leads, qualify them and book the next step — every call lands on the lead's timeline."
        actions={manager && <>
          <Button size="sm" variant="secondary" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>New agent</Button>
          <Button size="sm" icon={<Rocket className="size-4" />} onClick={() => setCampaignFor('pick')} disabled={!agents?.some((a) => a.is_active)}>Launch campaign</Button>
        </>} />

      <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Calls · 30 days" value={stats?.total ?? '—'} icon={<PhoneCall />} hint={stats?.active ? `${stats.active} live now` : 'none live'} accent="#6366f1" />
        <StatCard label="Connect rate" value={stats ? percent(stats.connect_rate, 0) : '—'} icon={<PhoneForwarded />} hint={`${stats?.connected ?? 0} conversations`} accent="#0ea5e9" />
        <StatCard label="Meetings booked" value={stats?.meetings ?? '—'} icon={<CalendarCheck />} hint={`${stats?.interested ?? 0} positive outcomes`} accent="#10b981" />
        <StatCard label="Avg. talk time" value={duration(stats?.avg_duration)} icon={<Clock />} hint="connected calls" accent="#f59e0b" />
      </div>

      {!!stats && Object.keys(stats.outcomes).length > 0 && <OutcomeBar outcomes={stats.outcomes} />}

      <Tabs className="mb-5 w-fit" value={tab} onChange={(t) => setParams(t === 'calls' ? {} : { tab: t })}
        tabs={[{ value: 'calls', label: 'Call log', icon: <Headphones />, count: stats?.total }, { value: 'agents', label: 'Agents', icon: <Bot />, count: agents?.length }]} />

      {tab === 'calls' ? <CallLog /> : <Agents agents={agents} onEdit={setEditing} onCampaign={setCampaignFor} />}

      {manager && <AgentModal agent={editing} onClose={() => setEditing(null)} />}
      {manager && <CampaignModal target={campaignFor} agents={agents ?? []} onClose={() => setCampaignFor(null)} />}
    </div>
  )
}

function OutcomeBar({ outcomes }: { outcomes: Record<string, number> }) {
  const total = Object.values(outcomes).reduce((a, b) => a + b, 0)
  const entries = Object.entries(outcomes).sort((a, b) => b[1] - a[1])
  return (
    <div className="card mb-6 p-5">
      <p className="mb-3 text-[11px] font-semibold tracking-[0.08em] text-slate-500 uppercase">Outcome mix</p>
      <div className="flex h-3 overflow-hidden rounded-full bg-slate-100 dark:bg-white/[0.05]">
        {entries.map(([k, v]) => <div key={k} style={{ width: `${(v / total) * 100}%`, background: OUTCOMES[k]?.color ?? '#94a3b8' }} title={`${OUTCOMES[k]?.label ?? k}: ${v}`} />)}
      </div>
      <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1.5 text-xs">
        {entries.map(([k, v]) => (
          <span key={k} className="flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
            <span className="size-2 rounded-full" style={{ background: OUTCOMES[k]?.color ?? '#94a3b8' }} />{OUTCOMES[k]?.label ?? humanize(k)} <span className="font-semibold">{v}</span>
          </span>
        ))}
      </div>
    </div>
  )
}

function CallLog() {
  const [filter, setFilter] = useState<'all' | 'live' | 'positive' | 'missed'>('all')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState<number | null>(null)
  const query = {
    all: {}, live: { status: 'queued,ringing,in_progress' }, positive: { outcome: 'meeting_booked,interested,callback' }, missed: { status: 'no_answer,voicemail,failed' },
  }[filter]
  const { data, isLoading } = useCallsQuery({ ...query, page }, { pollingInterval: 5000, skipPollingIfUnfocused: true })

  return (
    <div className="card overflow-hidden">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/60 p-4 dark:border-white/[0.06]">
        <Segmented value={filter} onChange={(v) => { setFilter(v); setPage(1) }}
          options={[{ value: 'all', label: 'All' }, { value: 'live', label: 'Live' }, { value: 'positive', label: 'Positive' }, { value: 'missed', label: 'Missed' }]} />
        <p className="text-xs text-slate-500">Refreshes automatically</p>
      </div>
      {isLoading ? <PageLoader /> : !data?.data.length ? <EmptyState icon={<Headphones />} title="No calls here yet" description="Start one from a lead with the AI call button, or launch a campaign." /> : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-[11px] tracking-wide text-slate-500 uppercase">
                <tr>{['Lead', 'Agent', 'Status', 'Outcome', 'Duration', 'Summary', 'When'].map((h) => <th key={h} className="px-4 py-3 font-semibold">{h}</th>)}</tr>
              </thead>
              <tbody className="divide-y divide-slate-200/60 dark:divide-white/[0.05]">
                {data.data.map((c) => (
                  <tr key={c.id} onClick={() => setOpen(c.id)} className="cursor-pointer transition hover:bg-brand-500/[0.04]">
                    <td className="px-4 py-3">
                      {c.lead ? <Link to={`/leads/${c.lead.id}`} onClick={(e) => e.stopPropagation()} className="font-medium text-slate-900 hover:text-brand-600 dark:text-white">{[c.lead.first_name, c.lead.last_name].filter(Boolean).join(' ')}</Link> : '—'}
                      <p className="text-xs text-slate-500">{c.lead?.company ?? c.to_number}</p>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">{c.agent?.name ?? '—'}{c.campaign_key && <Badge className="ml-1.5" color="#8b5cf6">campaign</Badge>}</td>
                    <td className="px-4 py-3"><CallStatusBadge status={c.status} /></td>
                    <td className="px-4 py-3"><OutcomeBadge outcome={c.outcome} /></td>
                    <td className="px-4 py-3 tabular-nums">{duration(c.duration_seconds)}</td>
                    <td className="max-w-xs truncate px-4 py-3 text-slate-500">{c.summary ?? c.error ?? '—'}</td>
                    <td className="px-4 py-3 whitespace-nowrap text-slate-500">{ago(c.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="p-4"><Pagination meta={data} onPage={setPage} /></div>
        </>
      )}
      <CallDrawer callId={open} onClose={() => setOpen(null)} />
    </div>
  )
}

function Agents({ agents, onEdit, onCampaign }: { agents?: AiAgent[]; onEdit: (a: AiAgent) => void; onCampaign: (a: AiAgent) => void }) {
  const run = useAction()
  const { manager } = usePermissions()
  const [remove] = useDeleteSettingMutation()
  const [deleting, setDeleting] = useState<AiAgent | null>(null)
  if (!agents) return <PageLoader />
  if (!agents.length) return <div className="card"><EmptyState icon={<Bot />} title="No agents yet" description="Create a voice agent with a goal and the questions it should ask." /></div>

  return (
    <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
      {agents.map((a) => {
        const rate = a.calls_count ? ((a.meetings_count ?? 0) / a.calls_count) * 100 : 0
        return (
          <div key={a.id} className="card group p-6">
            <div className="flex items-start justify-between">
              <span className="flex size-11 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-fuchsia-500 text-white shadow-lg shadow-brand-500/30"><Bot className="size-5" /></span>
              <div className="flex items-center gap-1">
                <Badge color={a.is_active ? '#10b981' : '#94a3b8'} dot>{a.is_active ? 'active' : 'paused'}</Badge>
                {manager && <div className="flex opacity-0 transition group-hover:opacity-100">
                  <button onClick={() => onEdit(a)} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label="Edit agent"><Pencil className="size-4" /></button>
                  <button onClick={() => setDeleting(a)} className="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label="Delete agent"><Trash2 className="size-4" /></button>
                </div>}
              </div>
            </div>
            <h3 className="mt-3 text-lg font-bold text-slate-900 dark:text-white">{a.name}</h3>
            <p className="text-xs text-slate-500">{a.integration ? humanize(a.integration.provider) : 'Best available provider'} · {a.voice} · {a.language}</p>
            <p className="mt-3 line-clamp-3 text-sm text-slate-600 dark:text-slate-300">{a.goal}</p>
            <div className="mt-5 grid grid-cols-3 gap-2 text-center">
              {[['Calls', a.calls_count ?? 0], ['Connected', a.completed_calls_count ?? 0], ['Meetings', a.meetings_count ?? 0]].map(([l, v]) => (
                <div key={l as string} className="rounded-2xl bg-slate-900/[0.03] py-2.5 dark:bg-white/[0.04]"><p className="font-display text-lg font-bold text-slate-900 dark:text-white">{v}</p><p className="text-[11px] text-slate-500">{l}</p></div>
              ))}
            </div>
            <div className="mt-4 flex items-center justify-between">
              <span className="text-xs text-slate-500">{a.questions?.length ?? 0} questions · {percent(rate, 0)} booked</span>
              {manager && a.is_active && <Button size="xs" variant="subtle" icon={<Rocket className="size-3.5" />} onClick={() => onCampaign(a)}>Campaign</Button>}
            </div>
          </div>
        )
      })}
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} title={`Delete ${deleting?.name}?`} message="Past calls stay in the log."
        onConfirm={async () => { if (deleting) await run(remove({ ...resources.aiAgents, id: deleting.id }), 'Agent deleted'); setDeleting(null) }} />
    </div>
  )
}

const VOICES = ['alloy', 'aria', 'nova', 'shimmer', 'echo', 'onyx']
const LANGUAGES = [['en-US', 'English (US)'], ['en-GB', 'English (UK)'], ['en-IN', 'English (India)'], ['hi-IN', 'Hindi'], ['es-ES', 'Spanish'], ['fr-FR', 'French'], ['de-DE', 'German']]

function AgentModal({ agent, onClose }: { agent: Partial<AiAgent> | null; onClose: () => void }) {
  const run = useAction()
  const { data: meta } = useMetaQuery()
  const [save, state] = useSaveSettingMutation()
  const [form, setForm] = useState<Partial<AiAgent>>({})
  const voiceProviders = meta?.features.voice_providers ?? []

  useEffect(() => {
    if (agent) setForm({
      voice: 'alloy', language: 'en-US', max_duration_seconds: 300, is_active: true, questions: [{ key: '', question: '' }], ...agent,
      integration_id: agent.integration_id ?? null,
    })
  }, [agent])

  const set = <K extends keyof AiAgent>(k: K, v: AiAgent[K]) => setForm((f) => ({ ...f, [k]: v }))
  const questions = form.questions ?? []
  const submit = async () => {
    const body = { ...form, questions: questions.filter((q) => q.question.trim()) }
    delete (body as Record<string, unknown>).integration
    if (await run(save({ ...resources.aiAgents, id: agent?.id, body }), 'Agent saved')) onClose()
  }

  return (
    <Modal open={!!agent} onClose={onClose} size="lg" title={agent?.id ? 'Edit AI agent' : 'New AI agent'} description="Describe the goal in plain words — the agent follows it on every call."
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} disabled={!form.name || !form.goal || !form.first_message} loading={state.isLoading}>Save agent</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Name" required><Input value={form.name ?? ''} onChange={(e) => set('name', e.target.value)} placeholder="Ava — inbound qualifier" /></Field>
        <Field label="Voice provider" hint="Connect vendors under Settings → Integrations">
          <Select value={form.integration_id ?? ''} onChange={(e) => set('integration_id', e.target.value ? Number(e.target.value) : null)} placeholder="Best available">
            {voiceProviders.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </Select>
        </Field>
        <Field label="Goal" required className="sm:col-span-2"><Textarea rows={3} value={form.goal ?? ''} onChange={(e) => set('goal', e.target.value)} placeholder="Qualify the lead on budget, authority, need and timeline, then offer a 20-minute demo." /></Field>
        <Field label="Opening line" required className="sm:col-span-2" hint="Use {first_name}, {name}, {company} or {organization} to personalise."><Input value={form.first_message ?? ''} onChange={(e) => set('first_message', e.target.value)} placeholder="Hi {first_name}, this is Ava from Acme — is now a good time?" /></Field>
        <Field label="Voice"><Select value={form.voice ?? 'alloy'} onChange={(e) => set('voice', e.target.value)}>{[...new Set([...VOICES, form.voice ?? 'alloy'])].map((v) => <option key={v}>{v}</option>)}</Select></Field>
        <Field label="Language"><Select value={form.language ?? 'en-US'} onChange={(e) => set('language', e.target.value)}>{[...LANGUAGES, ...(LANGUAGES.some(([v]) => v === form.language) ? [] : [[form.language ?? 'en-US', form.language ?? 'en-US']])].map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select></Field>
        <Field label="Max call length (seconds)"><Input type="number" min={30} max={1800} value={form.max_duration_seconds ?? 300} onChange={(e) => set('max_duration_seconds', Number(e.target.value))} /></Field>
        <div className="flex items-end pb-2"><Toggle checked={form.is_active ?? true} onChange={(v) => set('is_active', v)} label="Active" /></div>
        <div className="sm:col-span-2">
          <p className="mb-2 text-sm font-medium text-slate-700 dark:text-slate-200">Qualification questions</p>
          <div className="space-y-2">
            {questions.map((q, i) => (
              <div key={i} className="flex gap-2">
                <Input value={q.question} placeholder={`Question ${i + 1}`} onChange={(e) => set('questions', questions.map((x, j) => (j === i ? { ...x, question: e.target.value } : x)))} />
                <button onClick={() => set('questions', questions.filter((_, j) => j !== i))} className="rounded-lg px-2 text-slate-400 hover:text-rose-600" aria-label="Remove question"><X className="size-4" /></button>
              </div>
            ))}
            {questions.length < 10 && <Button size="xs" variant="subtle" icon={<Plus className="size-3.5" />} onClick={() => set('questions', [...questions, { key: '', question: '' }])}>Add question</Button>}
          </div>
        </div>
      </div>
    </Modal>
  )
}

function CampaignModal({ target, agents, onClose }: { target: AiAgent | 'pick' | null; agents: AiAgent[]; onClose: () => void }) {
  const run = useAction()
  const toast = useToast()
  const [launch, state] = useLaunchCampaignMutation()
  const [agentId, setAgentId] = useState('')
  const [conditions, setConditions] = useState<Condition[]>([])
  const [limit, setLimit] = useState(25)
  const active = agents.filter((a) => a.is_active)

  useEffect(() => {
    if (target) {
      setAgentId(String(target === 'pick' ? active[0]?.id ?? '' : target.id))
      setConditions([])
      setLimit(25)
    }
  }, [target])

  const submit = async () => {
    const r = await run(launch({ agentId: Number(agentId), conditions, limit }))
    if (r) {
      toast(r.queued ? 'success' : 'info', `${r.queued} call${r.queued === 1 ? '' : 's'} queued`, r.skipped ? `${r.skipped} skipped (already on a call or no phone).` : undefined)
      onClose()
    }
  }

  return (
    <Modal open={!!target} onClose={onClose} size="lg" title="Launch a call campaign" description="The agent calls every matching lead with a phone number, highest score first. Leads already on a live call are skipped."
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button icon={<Rocket className="size-4" />} disabled={!agentId} loading={state.isLoading} onClick={submit}>Start calling</Button></>}>
      <div className="space-y-5">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Agent"><Select value={agentId} onChange={(e) => setAgentId(e.target.value)}>{active.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select></Field>
          <Field label="Max calls" hint="Up to 200 per launch"><Input type="number" min={1} max={200} value={limit} onChange={(e) => setLimit(Math.min(200, Math.max(1, Number(e.target.value))))} /></Field>
        </div>
        <div>
          <p className="mb-2 text-sm font-medium text-slate-700 dark:text-slate-200">Which leads?</p>
          <ConditionBuilder value={conditions} onChange={setConditions} emptyLabel="All open leads you can see." />
        </div>
      </div>
    </Modal>
  )
}
