import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import clsx from 'clsx'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { CalendarClock, FlaskConical, Mail, MousePointerClick, Plus, Send, Trophy, Users } from 'lucide-react'
import { useAction } from '@/app/hooks'
import {
  resources, useBroadcastActionMutation, useBroadcastAudienceMutation, useBroadcastQuery, useBroadcastRecipientsQuery, useBroadcastsQuery,
  useDeleteBroadcastMutation, useLaunchBroadcastMutation, useSaveBroadcastMutation, useSettings,
} from '@/services/api'
import { Badge, Button, ConfirmDialog, EmptyState, Field, Input, Modal, PageLoader, Pagination, Segmented, Select, StatCard, Textarea, Toggle } from '@/components/ui'
import { ConditionBuilder } from '@/components/crm/ConditionBuilder'
import { ChartTooltip } from '@/components/crm/ChartTooltip'
import { ago, dateTime, percent } from '@/lib/format'
import type { Broadcast, BroadcastInput, BroadcastStatus } from '@/types'

const STATUS: Record<BroadcastStatus, { label: string; color: string }> = {
  draft: { label: 'Draft', color: '#64748b' },
  scheduled: { label: 'Scheduled', color: '#0284c7' },
  testing: { label: 'A/B testing', color: '#d97706' },
  sending: { label: 'Sending', color: '#7c3aed' },
  sent: { label: 'Sent', color: '#059669' },
  canceled: { label: 'Canceled', color: '#e11d48' },
}

const EMPTY: BroadcastInput = {
  name: '', campaign_id: null, conditions: [], test_percent: 30, winner_metric: 'open', winner_after_hours: 4,
  variants: [{ subject: 'Quick idea for {company}', body: 'Hi {first_name},\n\n…\n\nBest,\n{sender.name}' }],
}

/** Live "N people will get this" while the segment is edited. */
function AudienceCount({ conditions }: { conditions: BroadcastInput['conditions'] }) {
  const [check, { data, isLoading }] = useBroadcastAudienceMutation()
  const key = JSON.stringify(conditions)
  useEffect(() => {
    const t = setTimeout(() => { check({ conditions }) }, 350)
    return () => clearTimeout(t)
  }, [key]) // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <p className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300" aria-live="polite">
      <Users className="size-4 text-brand-500" />
      {isLoading || !data ? 'Counting…' : <span><strong className="text-slate-900 dark:text-white" data-testid="audience-count">{data.count}</strong> {data.count === 1 ? 'person' : 'people'} with an email who haven't opted out</span>}
    </p>
  )
}

function Editor({ broadcast, onClose, onSaved }: { broadcast: Broadcast | null; onClose: () => void; onSaved: (id: number) => void }) {
  const run = useAction()
  const { data: campaigns } = useSettings(resources.campaigns)
  const [save, saveState] = useSaveBroadcastMutation()
  const [launch, launchState] = useLaunchBroadcastMutation()
  const [form, setForm] = useState<BroadcastInput>(() => broadcast
    ? { ...EMPTY, ...broadcast, conditions: broadcast.conditions ?? [], variants: broadcast.variants.map(({ subject, body }) => ({ subject, body })) }
    : EMPTY)
  const [when, setWhen] = useState<'now' | 'later'>('now')
  const [at, setAt] = useState('')
  const set = <K extends keyof BroadcastInput>(k: K, v: BroadcastInput[K]) => setForm((f) => ({ ...f, [k]: v }))
  const ab = form.variants.length > 1
  const setVariant = (i: number, patch: Partial<BroadcastInput['variants'][number]>) => set('variants', form.variants.map((v, j) => (j === i ? { ...v, ...patch } : v)))
  const valid = form.name.trim() && form.variants.every((v) => v.subject.trim() && v.body.trim()) && (when === 'now' || at)

  const persist = async () => {
    const saved = await run(save({ ...form, id: broadcast?.id }))
    return saved ? (saved as Broadcast).id : null
  }
  const draft = async () => { const id = await persist(); if (id) onSaved(id) }
  const send = async () => {
    const id = await persist()
    if (!id) return
    const ok = await run(launch({ id, scheduled_at: when === 'later' ? new Date(at).toISOString() : undefined }), when === 'later' ? 'Campaign scheduled' : 'Campaign is sending')
    if (ok) onSaved(id)
  }

  return (
    <Modal open onClose={onClose} size="xl" title={broadcast ? 'Edit email campaign' : 'New email campaign'}
      footer={<>
        <Button variant="secondary" onClick={draft} loading={saveState.isLoading && !launchState.isLoading} disabled={!form.name.trim()}>Save draft</Button>
        <Button icon={when === 'later' ? <CalendarClock className="size-4" /> : <Send className="size-4" />} onClick={send} loading={launchState.isLoading} disabled={!valid}>
          {when === 'later' ? 'Schedule' : ab ? 'Start A/B test' : 'Send now'}
        </Button>
      </>}>
      <div className="space-y-6">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Name" required><Input value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="Spring offer" /></Field>
          <Field label="Marketing campaign" hint="Clicks are credited to it in attribution.">
            <Select value={form.campaign_id ?? ''} onChange={(e) => set('campaign_id', e.target.value ? Number(e.target.value) : null)} placeholder="None">
              {campaigns?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </Field>
        </div>

        <section aria-labelledby="audience-h">
          <h3 id="audience-h" className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Audience</h3>
          <ConditionBuilder value={form.conditions} onChange={(c) => set('conditions', c)} emptyLabel="Everyone with an email address." />
          <div className="mt-2"><AudienceCount conditions={form.conditions} /></div>
        </section>

        <section aria-labelledby="content-h">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h3 id="content-h" className="text-sm font-semibold text-slate-900 dark:text-white">Content</h3>
            <Toggle checked={ab} label="A/B test two versions" onChange={(on) => set('variants', on ? [...form.variants, { ...form.variants[0], subject: `${form.variants[0].subject} (B)` }] : [form.variants[0]])} />
          </div>
          <div className={clsx('grid gap-4', ab && 'lg:grid-cols-2')}>
            {form.variants.map((v, i) => (
              <div key={i} className="space-y-3 rounded-2xl border border-slate-200/80 p-4 dark:border-white/10">
                {ab && <Badge color={i ? '#d97706' : '#7c3aed'}>Version {i ? 'B' : 'A'}</Badge>}
                <Field label="Subject"><Input value={v.subject} onChange={(e) => setVariant(i, { subject: e.target.value })} /></Field>
                <Field label="Message" hint="Use {first_name}, {company}, {sender.name}… Links are tracked automatically; an unsubscribe link is added.">
                  <Textarea rows={8} value={v.body} onChange={(e) => setVariant(i, { body: e.target.value })} />
                </Field>
              </div>
            ))}
          </div>
          {ab && (
            <div className="mt-4 grid gap-4 rounded-2xl bg-amber-500/[0.06] p-4 sm:grid-cols-3">
              <Field label="Test on" hint="The rest get the winner."><Select value={form.test_percent} onChange={(e) => set('test_percent', Number(e.target.value))}>{[10, 20, 30, 50, 100].map((p) => <option key={p} value={p}>{p === 100 ? 'Everyone (50/50 split)' : `${p}% of the audience`}</option>)}</Select></Field>
              <Field label="Winner is the one with more"><Select value={form.winner_metric} onChange={(e) => set('winner_metric', e.target.value as 'open' | 'click')}><option value="open">Opens</option><option value="click">Clicks</option></Select></Field>
              <Field label="Decide after"><Select value={form.winner_after_hours} onChange={(e) => set('winner_after_hours', Number(e.target.value))}>{[1, 2, 4, 8, 12, 24, 48].map((h) => <option key={h} value={h}>{h} hour{h > 1 ? 's' : ''}</option>)}</Select></Field>
            </div>
          )}
        </section>

        <section className="flex flex-wrap items-end gap-4">
          <Segmented value={when} onChange={setWhen} options={[{ value: 'now', label: 'Send now' }, { value: 'later', label: 'Schedule' }]} />
          {when === 'later' && <Field label="Send at"><Input type="datetime-local" value={at} onChange={(e) => setAt(e.target.value)} min={new Date(Date.now() + 60_000).toISOString().slice(0, 16)} /></Field>}
        </section>
      </div>
    </Modal>
  )
}

function Detail({ id, onEdit, onClose }: { id: number; onEdit: (b: Broadcast) => void; onClose: () => void }) {
  const run = useAction()
  const [filter, setFilter] = useState('')
  const [page, setPage] = useState(1)
  const { data: b } = useBroadcastQuery(id, { pollingInterval: 15_000, skipPollingIfUnfocused: true })
  const { data: people } = useBroadcastRecipientsQuery({ id, filter, page })
  const [act, actState] = useBroadcastActionMutation()
  const [remove] = useDeleteBroadcastMutation()
  const [deleting, setDeleting] = useState(false)
  if (!b) return <Modal open onClose={onClose} size="xl" title="Email campaign"><PageLoader /></Modal>

  const s = b.stats
  const chart = s.variants.map((v) => ({ name: `Version ${v.key}`, 'Open rate': v.open_rate, 'Click rate': v.click_rate }))
  return (
    <Modal open onClose={onClose} size="xl" title={<span className="flex items-center gap-2">{b.name}<Badge color={STATUS[b.status].color} dot>{STATUS[b.status].label}</Badge></span>}
      description={b.scheduled_at && b.status === 'scheduled' ? `Goes out ${dateTime(b.scheduled_at)}` : b.started_at ? `Started ${dateTime(b.started_at)}${b.creator ? ` by ${b.creator.name}` : ''}` : 'Not sent yet'}
      footer={<>
        {['draft', 'scheduled', 'sent', 'canceled'].includes(b.status) && <Button variant="ghost" className="mr-auto text-rose-600" onClick={() => setDeleting(true)}>Delete</Button>}
        {b.status === 'testing' && <Button variant="secondary" icon={<Trophy className="size-4" />} loading={actState.isLoading} onClick={() => run(act({ id, action: 'pick-winner' }), 'Winner is being sent to everyone else')}>Pick winner now</Button>}
        {['scheduled', 'testing', 'sending'].includes(b.status) && <Button variant="secondary" loading={actState.isLoading} onClick={() => run(act({ id, action: 'cancel' }), 'Campaign canceled')}>{b.status === 'scheduled' ? 'Unschedule' : 'Stop sending'}</Button>}
        {['draft', 'scheduled'].includes(b.status) && <Button onClick={() => onEdit(b)}>Edit</Button>}
      </>}>
      <div className="space-y-6">
        <div className="grid gap-3 sm:grid-cols-4">
          <StatCard label="Sent" value={s.sent} icon={<Send />} hint={s.held ? `${s.held} waiting for the winner` : `of ${s.recipients}`} />
          <StatCard label="Open rate" value={percent(s.open_rate)} icon={<Mail />} accent="#0284c7" hint={`${s.opened} opened`} />
          <StatCard label="Click rate" value={percent(s.click_rate)} icon={<MousePointerClick />} accent="#059669" hint={`${s.clicked} clicked`} />
          <StatCard label="Not sent" value={s.skipped} icon={<Users />} accent="#e11d48" hint="opted out or failed" />
        </div>

        {b.variants.length > 1 && (
          <section className="card p-4" aria-labelledby="ab-h">
            <h3 id="ab-h" className="mb-1 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><FlaskConical className="size-4 text-amber-600" />A/B test</h3>
            <p className="mb-3 text-xs text-slate-500">{b.winner_key ? `Version ${b.winner_key} won on ${b.winner_metric === 'click' ? 'clicks' : 'opens'} and went to everyone else.` : b.winner_at ? `Winner picked by ${b.winner_metric === 'click' ? 'click' : 'open'} rate ${ago(b.winner_at)}.` : 'Testing on everyone.'}</p>
            <div className="grid gap-4 lg:grid-cols-[1fr_280px]">
              <div className="h-44">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={chart} margin={{ left: -16 }}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--chart-grid)" />
                    <XAxis dataKey="name" tickLine={false} axisLine={false} fontSize={11} stroke="var(--chart-axis)" />
                    <YAxis tickLine={false} axisLine={false} fontSize={11} stroke="var(--chart-axis)" unit="%" />
                    <Tooltip content={<ChartTooltip formatter={(v) => percent(Number(v))} />} cursor={{ fill: 'rgba(139,92,246,0.06)' }} />
                    <Bar dataKey="Open rate" fill="var(--series-1)" radius={[6, 6, 0, 0]} maxBarSize={40} />
                    <Bar dataKey="Click rate" fill="var(--series-2)" radius={[6, 6, 0, 0]} maxBarSize={40} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
              <ul className="space-y-2 text-sm">
                {s.variants.map((v) => (
                  <li key={v.key} className={clsx('rounded-xl border p-3', b.winner_key === v.key ? 'border-emerald-400 bg-emerald-500/[0.06]' : 'border-slate-200 dark:border-white/10')}>
                    <p className="flex items-center gap-2 font-semibold">Version {v.key}{b.winner_key === v.key && <Trophy className="size-4 text-emerald-600" aria-label="Winner" />}</p>
                    <p className="truncate text-xs text-slate-500">{v.subject}</p>
                    <p className="mt-1 text-xs">{v.sent} sent · {percent(v.open_rate)} opened · {percent(v.click_rate)} clicked</p>
                  </li>
                ))}
              </ul>
            </div>
          </section>
        )}

        <section aria-labelledby="people-h">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h3 id="people-h" className="text-sm font-semibold text-slate-900 dark:text-white">Recipients</h3>
            <Segmented value={filter} onChange={(f) => { setFilter(f); setPage(1) }} options={[{ value: '', label: 'All' }, { value: 'opened', label: 'Opened' }, { value: 'clicked', label: 'Clicked' }, { value: 'unopened', label: 'Not opened' }, { value: 'failed', label: 'Not sent' }]} />
          </div>
          <div className="card overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-xs text-slate-500"><tr><th className="px-4 py-2 font-medium">Person</th><th className="px-4 py-2 font-medium">Version</th><th className="px-4 py-2 font-medium">Status</th><th className="px-4 py-2 font-medium">Opened</th><th className="px-4 py-2 font-medium">Clicked</th></tr></thead>
              <tbody>
                {people?.data.map((r) => (
                  <tr key={r.id} className="border-t border-slate-200/60 dark:border-white/[0.06]">
                    <td className="px-4 py-2"><Link to={`/leads/${r.lead?.id}`} className="font-medium text-slate-900 hover:text-brand-600 dark:text-white">{[r.lead?.first_name, r.lead?.last_name].filter(Boolean).join(' ')}</Link><span className="block text-xs text-slate-500">{r.lead?.email}</span></td>
                    <td className="px-4 py-2">{r.variant ?? '—'}</td>
                    <td className="px-4 py-2 capitalize" title={r.reason ?? undefined}>{r.status === 'held' ? 'Waiting for winner' : r.status}</td>
                    <td className="px-4 py-2 text-slate-500">{r.opened_at ? `${ago(r.opened_at)}${r.open_count > 1 ? ` (${r.open_count}×)` : ''}` : '—'}</td>
                    <td className="px-4 py-2 text-slate-500">{r.clicked_at ? `${ago(r.clicked_at)}${r.click_count > 1 ? ` (${r.click_count}×)` : ''}` : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {people && !people.data.length && <p className="px-4 py-6 text-center text-sm text-slate-500">Nobody here yet.</p>}
            {people && <Pagination meta={people} onPage={setPage} />}
          </div>
        </section>
      </div>
      <ConfirmDialog open={deleting} onClose={() => setDeleting(false)} title={`Delete ${b.name}?`} message="Its stats are deleted too. Emails already sent stay on lead timelines."
        onConfirm={async () => { if (await run({ unwrap: () => remove(id).unwrap().then(() => true) }, 'Campaign deleted')) onClose(); setDeleting(false) }} />
    </Modal>
  )
}

/** Email campaigns: list, editor and results. */
export function EmailCampaigns() {
  const [params, setParams] = useSearchParams()
  const { data, isLoading } = useBroadcastsQuery(undefined, { pollingInterval: 30_000, skipPollingIfUnfocused: true })
  const [editing, setEditing] = useState<Broadcast | 'new' | null>(null)
  const open = Number(params.get('broadcast')) || null
  const show = (id: number | null) => {
    const next = new URLSearchParams(params)
    if (id) next.set('broadcast', String(id)); else next.delete('broadcast')
    setParams(next, { replace: true })
  }

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-2xl text-sm text-slate-500">Email a segment of your leads, test two subject lines, and let the better one go to everyone else. Opens and clicks are tracked; people who opted out are skipped.</p>
        <Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>New email campaign</Button>
      </div>
      {isLoading ? <PageLoader /> : !data?.length ? (
        <div className="card"><EmptyState icon={<Mail />} title="No email campaigns yet" description="Write one email (or two to A/B test) and pick who gets it." action={<Button size="sm" onClick={() => setEditing('new')}>Create one</Button>} /></div>
      ) : (
        <div className="card overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-xs text-slate-500"><tr><th className="px-4 py-3 font-medium">Campaign</th><th className="px-4 py-3 font-medium">Status</th><th className="px-4 py-3 text-right font-medium">Sent</th><th className="px-4 py-3 text-right font-medium">Opened</th><th className="px-4 py-3 text-right font-medium">Clicked</th><th className="px-4 py-3 font-medium">When</th></tr></thead>
            <tbody>
              {data.map((b) => (
                <tr key={b.id} className="cursor-pointer border-t border-slate-200/60 hover:bg-slate-900/[0.02] dark:border-white/[0.06] dark:hover:bg-white/[0.03]" onClick={() => show(b.id)}>
                  <td className="px-4 py-3">
                    <button className="text-left font-semibold text-slate-900 hover:text-brand-600 dark:text-white" onClick={(e) => { e.stopPropagation(); show(b.id) }}>{b.name}</button>
                    <span className="block text-xs text-slate-500">{b.variants.length > 1 ? 'A/B test · ' : ''}{b.variants[0]?.subject}{b.campaign ? ` · ${b.campaign.name}` : ''}</span>
                  </td>
                  <td className="px-4 py-3"><Badge color={STATUS[b.status].color} dot>{STATUS[b.status].label}</Badge></td>
                  <td className="px-4 py-3 text-right tabular-nums">{b.summary?.sent ?? 0}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{percent(b.summary?.open_rate ?? 0)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{percent(b.summary?.click_rate ?? 0)}</td>
                  <td className="px-4 py-3 text-slate-500">{b.status === 'scheduled' && b.scheduled_at ? dateTime(b.scheduled_at) : b.started_at ? ago(b.started_at) : ago(b.created_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {open && !editing && <Detail id={open} onClose={() => show(null)} onEdit={(b) => setEditing(b)} />}
      {editing && <Editor broadcast={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={(id) => { setEditing(null); show(id) }} />}
    </div>
  )
}
