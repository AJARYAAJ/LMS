import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import clsx from 'clsx'
import {
  AreaChart as AreaIcon, BarChart3, CalendarClock, ChartColumnStacked, ChartLine, ChartPie, Copy, Hash, Mail, Pin, PinOff, Plus, Save, Share2, Table2, Trash2, Wand2,
} from 'lucide-react'
import { useAction, useCurrentUser, useToast } from '@/app/hooks'
import {
  useDeleteReportMutation, useReportCatalogQuery, useRunReportQuery, useSaveReportMutation, useSavedReportsQuery, useSendReportMutation,
} from '@/services/api'
import { Badge, Button, ConfirmDialog, EmptyState, Field, Input, Modal, PageLoader, Select, Textarea, Toggle } from '@/components/ui'
import { ReportCard } from '@/components/reports/ReportChart'
import { STUDIO_HANDOFF_KEY } from '@/components/reports/AskBar'
import { ago } from '@/lib/format'
import type { ReportChartType, ReportEntity, ReportSpec, SavedReport } from '@/types'

const CHART_ICONS: Record<ReportChartType, { icon: typeof BarChart3; label: string }> = {
  bar: { icon: BarChart3, label: 'Bars' },
  stacked: { icon: ChartColumnStacked, label: 'Stacked' },
  line: { icon: ChartLine, label: 'Line' },
  area: { icon: AreaIcon, label: 'Area' },
  pie: { icon: ChartPie, label: 'Donut' },
  table: { icon: Table2, label: 'Table' },
  number: { icon: Hash, label: 'Number' },
}

const DEFAULT_SPEC: ReportSpec = { entity: 'leads', metric: 'count', dimension: 'source', split: null, date_field: null, filters: {}, chart: 'bar', range: 'last_30' }

/** Quick starts that show what the studio can do. */
const IDEAS: { name: string; spec: ReportSpec }[] = [
  { name: 'Won revenue by month', spec: { entity: 'deals', metric: 'won_amount', dimension: 'closed', chart: 'bar', range: 'last_12_months' } },
  { name: 'Lead sources over time', spec: { entity: 'leads', metric: 'count', dimension: 'created', split: 'source', chart: 'stacked', range: 'last_90' } },
  { name: 'Win rate by rep', spec: { entity: 'deals', metric: 'win_rate', dimension: 'owner', date_field: 'closed', chart: 'bar', range: 'this_quarter' } },
  { name: 'Overdue work by person', spec: { entity: 'tasks', metric: 'overdue', dimension: 'assignee', chart: 'bar', range: 'last_90' } },
  { name: 'AI call outcomes', spec: { entity: 'calls', metric: 'count', dimension: 'outcome', chart: 'pie', range: 'last_30' } },
  { name: 'Replies by channel', spec: { entity: 'activities', metric: 'count', dimension: 'type', filters: { direction: ['inbound'] }, chart: 'pie', range: 'last_30' } },
]

export function ReportStudio() {
  const run = useAction()
  const toast = useToast()
  const me = useCurrentUser()
  const [params, setParams] = useSearchParams()
  const { data: catalog } = useReportCatalogQuery()
  const { data: saved, isLoading: savedLoading } = useSavedReportsQuery()
  const [saveReport, saveState] = useSaveReportMutation()
  const [removeReport] = useDeleteReportMutation()
  const [sendReport] = useSendReportMutation()
  const [spec, setSpec] = useState<ReportSpec>(DEFAULT_SPEC)
  const [current, setCurrent] = useState<SavedReport | null>(null)
  const [saving, setSaving] = useState<Partial<SavedReport> | null>(null)
  const [deleting, setDeleting] = useState<SavedReport | null>(null)
  const { data: result, isFetching, error } = useRunReportQuery(spec)

  // Open a saved report from the URL (?report=ID), e.g. from an emailed link or the dashboard.
  const openId = Number(params.get('report')) || null
  // A question from the Ask bar can be refined here.
  useEffect(() => {
    try {
      const handed = sessionStorage.getItem(STUDIO_HANDOFF_KEY)
      if (handed) { sessionStorage.removeItem(STUDIO_HANDOFF_KEY); setSpec({ ...DEFAULT_SPEC, ...JSON.parse(handed) }) }
    } catch { /* ignore */ }
  }, [])

  // The URL is the source of truth for which saved report is open.
  useEffect(() => {
    if (!openId) {
      setCurrent(null)
      return
    }
    const r = saved?.find((s) => s.id === openId)
    if (r && r.id !== current?.id) {
      setCurrent(r)
      setSpec({ ...DEFAULT_SPEC, ...r.spec })
    }
  }, [openId, saved, current?.id])

  const entity = catalog?.entities.find((e) => e.key === spec.entity)
  const dims = entity?.dimensions ?? []
  const dateDims = dims.filter((d) => d.type === 'date')
  const dim = dims.find((d) => d.key === spec.dimension)
  const filterable = dims.filter((d) => d.filterable)
  const mine = (r: SavedReport) => r.user_id === me?.id || me?.role === 'admin'

  const set = (patch: Partial<ReportSpec>) => setSpec((s) => ({ ...s, ...patch }))
  const changeEntity = (key: ReportEntity) => {
    const e = catalog?.entities.find((x) => x.key === key)
    const firstGroup = e?.dimensions.find((d) => d.type !== 'date')
    setSpec({ ...DEFAULT_SPEC, entity: key, metric: 'count', dimension: firstGroup?.key ?? null, range: spec.range })
  }
  const changeDimension = (key: string) => {
    const d = dims.find((x) => x.key === key)
    set({
      dimension: key || null,
      split: spec.split === key ? null : spec.split,
      chart: !key ? 'number' : d?.type === 'date' ? (spec.split ? 'stacked' : 'line') : spec.chart === 'line' || spec.chart === 'area' || spec.chart === 'number' ? 'bar' : spec.chart,
    })
  }
  const toggleFilter = (key: string, value: string) => {
    const now = spec.filters?.[key] ?? []
    const next = now.includes(value) ? now.filter((v) => v !== value) : [...now, value]
    const filters = { ...(spec.filters ?? {}) }
    if (next.length) filters[key] = next
    else delete filters[key]
    set({ filters })
  }
  const newReport = () => { setSpec(DEFAULT_SPEC); setParams({ tab: 'studio' }) }
  const openReport = (r: SavedReport) => setParams({ tab: 'studio', report: String(r.id) })

  const charts = useMemo<ReportChartType[]>(() => {
    if (!spec.dimension) return ['number']
    if (dim?.type === 'date') return spec.split ? ['stacked', 'line', 'table'] : ['bar', 'line', 'area', 'table']
    return spec.split ? ['stacked', 'table'] : ['bar', 'pie', 'table']
  }, [spec.dimension, spec.split, dim?.type])

  useEffect(() => {
    if (spec.chart && !charts.includes(spec.chart)) set({ chart: charts[0] })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [charts])

  const submitSave = async (value: Partial<SavedReport>) => {
    const body = { ...value, spec }
    const r = await run(saveReport(body), value.id ? 'Report updated' : 'Report saved')
    if (r) { setSaving(null); openReport(r) }
  }

  if (!catalog) return <PageLoader />

  return (
    <div className="space-y-6">
      <div className="grid gap-6 xl:grid-cols-[340px_1fr]">
        {/* Builder */}
        <div className="card space-y-4 p-5">
          <div className="flex items-center justify-between">
            <h3 className="flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><Wand2 className="size-4 text-brand-500" />{current ? current.name : 'New report'}</h3>
            {current && <button onClick={newReport} className="text-xs font-medium text-brand-600 hover:underline">New</button>}
          </div>
          <Field label="Report on">
            <div className="grid grid-cols-3 gap-1.5">
              {catalog.entities.map((e) => (
                <button key={e.key} onClick={() => changeEntity(e.key)} className={clsx('rounded-xl border px-2 py-2 text-xs font-medium transition', spec.entity === e.key ? 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' : 'border-slate-200 text-slate-600 hover:border-brand-300 dark:border-white/10 dark:text-slate-300')}>{e.label}</button>
              ))}
            </div>
          </Field>
          <Field label="Measure">
            <Select value={spec.metric} onChange={(e) => set({ metric: e.target.value })}>{entity?.metrics.map((m) => <option key={m.key} value={m.key}>{m.label}</option>)}</Select>
          </Field>
          <Field label="Group by">
            <Select value={spec.dimension ?? ''} onChange={(e) => changeDimension(e.target.value)}>
              <option value="">No grouping (one number)</option>
              <optgroup label="Over time">{dateDims.map((d) => <option key={d.key} value={d.key}>{d.label}</option>)}</optgroup>
              <optgroup label="Breakdown">{dims.filter((d) => d.type !== 'date').map((d) => <option key={d.key} value={d.key}>{d.label}</option>)}</optgroup>
            </Select>
          </Field>
          {spec.dimension && (
            <Field label="Split by" hint="Adds a coloured series for each value (up to 8).">
              <Select value={spec.split ?? ''} onChange={(e) => set({ split: e.target.value || null, chart: e.target.value ? 'stacked' : dim?.type === 'date' ? 'line' : 'bar' })}>
                <option value="">No split</option>
                {dims.filter((d) => d.type !== 'date' && d.key !== spec.dimension).map((d) => <option key={d.key} value={d.key}>{d.label}</option>)}
              </Select>
            </Field>
          )}
          <div className="grid grid-cols-2 gap-3">
            <Field label="Date range">
              <Select value={spec.range ?? 'last_30'} onChange={(e) => set({ range: e.target.value })}>{catalog.ranges.filter((r) => r.key !== 'custom').map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}</Select>
            </Field>
            {dim?.type !== 'date' && dateDims.length > 1 ? (
              <Field label="Dated by">
                <Select value={spec.date_field ?? ''} onChange={(e) => set({ date_field: e.target.value || null })}>
                  <option value="">{dateDims[0]?.label}</option>
                  {dateDims.slice(1).map((d) => <option key={d.key} value={d.key}>{d.label}</option>)}
                </Select>
              </Field>
            ) : dim?.type === 'date' ? (
              <Field label="Buckets">
                <Select value={spec.granularity ?? ''} onChange={(e) => set({ granularity: (e.target.value || null) as ReportSpec['granularity'] })}>
                  <option value="">Automatic</option><option value="day">Days</option><option value="week">Weeks</option><option value="month">Months</option>
                </Select>
              </Field>
            ) : <div />}
          </div>
          {filterable.length > 0 && (
            <div>
              <p className="mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">Only include <span className="font-normal text-slate-400">(optional)</span></p>
              <div className="space-y-2">
                {filterable.map((d) => (
                  <div key={d.key}>
                    <p className="mb-1 text-xs text-slate-500">{d.label}</p>
                    <div className="flex flex-wrap gap-1">
                      {d.values?.map(({ value, label }) => {
                        const on = spec.filters?.[d.key]?.includes(value)
                        return <button key={value} onClick={() => toggleFilter(d.key, value)} aria-pressed={on} className={clsx('chip text-[11px] transition', on && 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>{label}</button>
                      })}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
          <Field label="Chart">
            <div className="flex flex-wrap gap-1.5">
              {charts.map((c) => {
                const C = CHART_ICONS[c]
                return (
                  <button key={c} onClick={() => set({ chart: c })} aria-pressed={spec.chart === c} className={clsx('flex items-center gap-1.5 rounded-xl border px-2.5 py-1.5 text-xs font-medium transition', spec.chart === c ? 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' : 'border-slate-200 text-slate-600 dark:border-white/10 dark:text-slate-300')}>
                    <C.icon className="size-3.5" />{C.label}
                  </button>
                )
              })}
            </div>
          </Field>
          <div className="flex gap-2 pt-1">
            {current && mine(current)
              ? <Button className="flex-1" icon={<Save className="size-4" />} loading={saveState.isLoading} onClick={async () => { await run(saveReport({ id: current.id, spec }), 'Report updated') }}>Save changes</Button>
              : <Button className="flex-1" icon={<Save className="size-4" />} onClick={() => setSaving({ name: current ? `${current.name} (copy)` : '', is_shared: false, pinned: false, schedule: 'none', recipients: [], post_to_chat: false })}>Save report</Button>}
            {current && mine(current) && <Button variant="secondary" icon={<Copy className="size-4" />} onClick={() => setSaving({ name: `${current.name} (copy)`, is_shared: false, pinned: false, schedule: 'none', recipients: [], post_to_chat: false })} aria-label="Save as copy" />}
            {current && mine(current) && <Button variant="secondary" icon={<Share2 className="size-4" />} onClick={() => setSaving(current)} aria-label="Sharing and schedule" />}
          </div>
        </div>

        {/* Preview */}
        <div className="min-w-0 space-y-4">
          {error ? <div className="card p-8 text-center text-sm text-rose-600">{(error as { data?: { message?: string } }).data?.message ?? 'This report could not run.'}</div> : (
            <div className={clsx('transition', isFetching && 'opacity-60')}>
              <ReportCard title={current?.name ?? 'Preview'} result={result} height={320}
                subtitle={result ? `${result.entity_label}: ${result.metric_label.toLowerCase()}${result.dimension_label ? ` by ${result.dimension_label.toLowerCase()}` : ''}${result.split_label ? `, split by ${result.split_label.toLowerCase()}` : ''} · ${result.range.label}` : undefined} />
            </div>
          )}
          {!current && (
            <div className="card p-5">
              <p className="mb-3 text-[11px] font-semibold tracking-[0.08em] text-slate-500 uppercase">Ideas to start from</p>
              <div className="flex flex-wrap gap-2">
                {IDEAS.map((i) => <button key={i.name} onClick={() => setSpec({ ...DEFAULT_SPEC, ...i.spec })} className="chip transition hover:border-brand-400">{i.name}</button>)}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Saved reports */}
      <div>
        <div className="mb-3 flex items-center justify-between">
          <h3 className="font-semibold text-slate-900 dark:text-white">Saved reports</h3>
          <Button size="xs" variant="subtle" icon={<Plus className="size-3.5" />} onClick={newReport}>New report</Button>
        </div>
        {savedLoading ? <PageLoader /> : !saved?.length ? <div className="card"><EmptyState icon={<BarChart3 />} title="No saved reports yet" description="Build one above and save it to pin it to your dashboard or email it on a schedule." /></div> : (
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {saved.map((r) => {
              const Icon = CHART_ICONS[r.spec.chart ?? 'bar']?.icon ?? BarChart3
              return (
                <div key={r.id} className={clsx('card group p-4 transition', current?.id === r.id && 'ring-2 ring-brand-400/60')}>
                  <button onClick={() => openReport(r)} className="flex w-full items-start gap-3 text-left">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600"><Icon className="size-4" /></span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm font-semibold text-slate-900 dark:text-white">{r.name}</span>
                      <span className="block text-xs text-slate-500">{r.user?.name ?? 'You'}{r.last_sent_at ? ` · emailed ${ago(r.last_sent_at)}` : ''}</span>
                    </span>
                  </button>
                  <div className="mt-3 flex flex-wrap items-center gap-1.5">
                    {r.pinned && r.user_id === me?.id && <Badge color="#8b5cf6">On dashboard</Badge>}
                    {r.is_shared && <Badge color="#0ea5e9">Shared</Badge>}
                    {r.schedule !== 'none' && <Badge color="#10b981"><CalendarClock className="mr-0.5 inline size-3" />{r.schedule}</Badge>}
                    {mine(r) && (
                      <div className="ml-auto flex opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                        <button onClick={() => run(saveReport({ id: r.id, pinned: !r.pinned }), r.pinned ? 'Removed from dashboard' : 'Pinned to dashboard')} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label={r.pinned ? `Unpin ${r.name}` : `Pin ${r.name} to dashboard`}>{r.pinned ? <PinOff className="size-4" /> : <Pin className="size-4" />}</button>
                        <button onClick={async () => { const res = await run(sendReport(r.id)); if (res) toast('success', res.message) }} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label={`Email ${r.name} now`}><Mail className="size-4" /></button>
                        <button onClick={() => setDeleting(r)} className="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label={`Delete ${r.name}`}><Trash2 className="size-4" /></button>
                      </div>
                    )}
                  </div>
                </div>
              )
            })}
          </div>
        )}
      </div>

      <SaveModal value={saving} onChange={setSaving} onClose={() => setSaving(null)} onSubmit={submitSave} loading={saveState.isLoading} />
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} title={`Delete ${deleting?.name}?`} message="It also disappears from dashboards and stops any scheduled emails."
        onConfirm={async () => {
          if (deleting) await run(removeReport(deleting.id), 'Report deleted')
          if (deleting?.id === current?.id) newReport()
          setDeleting(null)
        }} />
    </div>
  )
}

function SaveModal({ value, onChange, onClose, onSubmit, loading }: {
  value: Partial<SavedReport> | null
  onChange: (v: Partial<SavedReport>) => void
  onClose: () => void
  onSubmit: (value: Partial<SavedReport>) => void
  loading: boolean
}) {
  const [emails, setEmails] = useState('')
  useEffect(() => { if (value) setEmails((value.recipients ?? []).join(', ')) }, [value?.id, value === null]) // eslint-disable-line react-hooks/exhaustive-deps
  if (!value) return null
  const set = (patch: Partial<SavedReport>) => onChange({ ...value, ...patch })
  const recipients = emails.split(/[,\s]+/).map((e) => e.trim()).filter(Boolean)

  return (
    <Modal open onClose={onClose} title={value.id ? 'Sharing & schedule' : 'Save report'}
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button disabled={!value.name?.trim()} loading={loading} onClick={() => onSubmit({ ...value, recipients })}>Save</Button></>}>
      <div className="space-y-4">
        <Field label="Name" required><Input value={value.name ?? ''} onChange={(e) => set({ name: e.target.value })} autoFocus /></Field>
        <Field label="Note" hint="Shown under the chart and in emails."><Textarea rows={2} value={value.description ?? ''} onChange={(e) => set({ description: e.target.value })} /></Field>
        <Toggle checked={!!value.pinned} onChange={(v) => set({ pinned: v })} label="Pin to my dashboard" />
        <Toggle checked={!!value.is_shared} onChange={(v) => set({ is_shared: v })} label="Share with my organization" description="Everyone can open it and see the numbers their own access allows." />
        <Toggle checked={!!value.post_to_chat} onChange={(v) => set({ post_to_chat: v })} label="Also post to Slack / Teams" description="Uses the chat channels connected under Settings → Integrations." />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Email it">
            <Select value={value.schedule ?? 'none'} onChange={(e) => set({ schedule: e.target.value as SavedReport['schedule'] })}>
              <option value="none">Never</option><option value="weekly">Every Monday</option><option value="monthly">On the 1st of each month</option>
            </Select>
          </Field>
          <Field label="To" hint="Blank sends it to you."><Input value={emails} onChange={(e) => setEmails(e.target.value)} placeholder="boss@company.com, team@…" /></Field>
        </div>
      </div>
    </Modal>
  )
}
