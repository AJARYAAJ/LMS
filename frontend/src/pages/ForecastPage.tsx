import { useState } from 'react'
import { Link } from 'react-router-dom'
import clsx from 'clsx'
import { TrendingUp } from 'lucide-react'
import { useAction, useAppSelector, usePermissions } from '@/app/hooks'
import { useForecastQuery, useMetaQuery, useSaveDealMutation } from '@/services/api'
import { Avatar, EmptyState, PageHeader, PageLoader, Segmented, Select, StatCard } from '@/components/ui'
import { FORECAST_LABEL } from '@/lib/constants'
import { date, money, percent } from '@/lib/format'
import type { ForecastRow } from '@/types'

/** Category order and colours (fixed palette slots; open pipeline stays neutral). */
const SEGMENTS: { key: keyof Pick<ForecastRow, 'closed' | 'commit' | 'best_case' | 'pipeline'>; color: string }[] = [
  { key: 'closed', color: 'var(--series-3)' },
  { key: 'commit', color: 'var(--series-1)' },
  { key: 'best_case', color: 'var(--series-7)' },
  { key: 'pipeline', color: 'var(--series-other)' },
]

function StackBar({ row, max, currency }: { row: ForecastRow; max: number; currency: string }) {
  const scale = (v: number) => (max ? (v / max) * 100 : 0)
  return (
    <div className="relative h-6 w-full rounded-lg bg-slate-900/[0.04] dark:bg-white/[0.05]">
      <div className="flex h-full gap-[2px] overflow-hidden rounded-lg">
        {SEGMENTS.map((s) => row[s.key] > 0 && (
          <div key={s.key} className="h-full first:rounded-l-lg last:rounded-r-lg" style={{ width: `${scale(row[s.key])}%`, background: s.color }}
            title={`${FORECAST_LABEL[s.key]}: ${money(row[s.key], currency)}`} />
        ))}
      </div>
      {row.quota && (
        <span className="absolute -top-1 -bottom-1 w-0.5 rounded bg-slate-900 dark:bg-white" style={{ left: `${Math.min(scale(row.quota), 100)}%` }} title={`Quota ${money(row.quota, currency)}`} />
      )}
    </div>
  )
}

export function ForecastPage() {
  const run = useAction()
  const { write, manager } = usePermissions()
  const me = useAppSelector((s) => s.auth.user)
  const currency = me?.organization?.currency ?? 'USD'
  const [period, setPeriod] = useState<'this_month' | 'next_month' | 'this_quarter' | 'next_quarter'>('this_quarter')
  const [pipelineId, setPipelineId] = useState('')
  const { data: meta } = useMetaQuery()
  const { data, isFetching } = useForecastQuery({ period, pipeline_id: pipelineId || undefined })
  const [save] = useSaveDealMutation()

  const max = data ? Math.max(1, ...data.reps.map((r) => Math.max(r.closed + r.commit + r.best_case + r.pipeline, r.quota ?? 0))) : 1
  const canEdit = (ownerId: number | null) => write && (manager || ownerId === me?.id)

  return (
    <div>
      <PageHeader icon={<TrendingUp />} title="Forecast" description="Closed revenue plus open deals expected to close in the period, by forecast category — against each rep's revenue goal."
        actions={<>
          {(meta?.pipelines.length ?? 0) > 1 && (
            <Select className="w-auto" value={pipelineId} onChange={(e) => setPipelineId(e.target.value)} aria-label="Pipeline">
              <option value="">All pipelines</option>
              {meta?.pipelines.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </Select>
          )}
          <Segmented value={period} onChange={setPeriod} options={[
            { value: 'this_month', label: 'This month' }, { value: 'next_month', label: 'Next month' },
            { value: 'this_quarter', label: 'This quarter' }, { value: 'next_quarter', label: 'Next quarter' },
          ]} />
        </>} />

      {!data ? <PageLoader /> : (
        <div className={clsx('space-y-6 transition', isFetching && 'opacity-60')}>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <StatCard label={`Closed won · ${data.period_label}`} value={money(data.total.closed, currency, true)} accent="#1baf7a" />
            <StatCard label="Commit" value={money(data.total.commit, currency, true)} hint="probability ≥ 70% or set by hand" accent="#2a78d6" />
            <StatCard label="Best case" value={money(data.total.best_case, currency, true)} accent="#4a3aa7" />
            <StatCard label="Projected (closed + commit)" value={money(data.total.projected, currency, true)} hint={`best case ${money(data.total.best_projection, currency, true)}`} accent="#8b5cf6" />
            <StatCard label="Team quota" value={data.total.quota ? percent(data.total.attainment ?? 0, 0) : '—'} hint={data.total.quota ? `${money(data.total.closed, currency, true)} of ${money(data.total.quota, currency, true)}` : 'Set a team revenue goal under Insights → Goals'} accent="#f59e0b" />
          </div>

          <div className="card p-5">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
              <h3 className="font-semibold text-slate-900 dark:text-white">By rep</h3>
              <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600 dark:text-slate-300" aria-label="Legend">
                {SEGMENTS.map((s) => <li key={s.key} className="flex items-center gap-1.5"><span className="size-2.5 rounded-[3px]" style={{ background: s.color }} />{FORECAST_LABEL[s.key]}</li>)}
                <li className="flex items-center gap-1.5"><span className="h-3 w-0.5 rounded bg-slate-900 dark:bg-white" />Quota</li>
              </ul>
            </div>
            {!data.reps.length ? <EmptyState title="Nothing closing in this period" description="Set expected close dates on open deals to see them here." /> : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[760px] text-sm">
                  <thead><tr className="text-left text-[11px] tracking-wide text-slate-500 uppercase">
                    <th className="pb-2 font-semibold">Rep</th><th className="w-[38%] pb-2 font-semibold" /><th className="pb-2 text-right font-semibold">Closed</th><th className="pb-2 text-right font-semibold">Commit</th><th className="pb-2 text-right font-semibold">Best case</th><th className="pb-2 text-right font-semibold">Quota</th><th className="pb-2 text-right font-semibold">Attained</th>
                  </tr></thead>
                  <tbody className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
                    {data.reps.map((r) => (
                      <tr key={r.owner?.id ?? 'none'}>
                        <td className="py-3 pr-3"><span className="flex items-center gap-2"><Avatar name={r.owner?.name ?? '?'} color={r.owner?.avatar_color} size="sm" />{r.owner?.name ?? 'Unassigned'}</span></td>
                        <td className="py-3 pr-4"><StackBar row={r} max={max} currency={currency} /></td>
                        <td className="py-3 text-right tabular-nums">{money(r.closed, currency, true)}</td>
                        <td className="py-3 text-right tabular-nums">{money(r.commit, currency, true)}</td>
                        <td className="py-3 text-right tabular-nums">{money(r.best_case, currency, true)}</td>
                        <td className="py-3 text-right tabular-nums">{r.quota ? money(r.quota, currency, true) : '—'}</td>
                        <td className="py-3 text-right font-semibold tabular-nums">{r.attainment !== null ? percent(r.attainment, 0) : '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          <div className="card overflow-hidden">
            <div className="border-b border-slate-200/60 p-5 dark:border-white/[0.06]"><h3 className="font-semibold text-slate-900 dark:text-white">Deals in this forecast</h3><p className="text-xs text-slate-500">Change a category to commit or hold back a deal; “Automatic” follows the stage probability.</p></div>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] text-sm">
                <thead><tr className="text-left text-[11px] tracking-wide text-slate-500 uppercase">{['Deal', 'Owner', 'Stage', 'Close', 'Amount', 'Category'].map((h) => <th key={h} className="table-head">{h}</th>)}</tr></thead>
                <tbody className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
                  {data.deals.map((d) => (
                    <tr key={d.id}>
                      <td className="table-cell"><Link to={`/deals/${d.id}`} className="font-medium text-slate-900 hover:text-brand-600 dark:text-white">{d.name}</Link></td>
                      <td className="table-cell">{d.owner?.name ?? '—'}</td>
                      <td className="table-cell"><span className="inline-flex items-center gap-1.5"><span className="size-2 rounded-full" style={{ background: d.stage?.color ?? '#94a3b8' }} />{d.stage?.name ?? '—'} · {d.probability}%</span></td>
                      <td className="table-cell">{date(d.status === 'won' ? d.closed_at : d.expected_close_date)}</td>
                      <td className="table-cell font-medium tabular-nums">{money(Number(d.amount), d.currency || currency)}</td>
                      <td className="table-cell">
                        {d.status === 'won' ? <span className="text-xs font-semibold text-emerald-600">Closed won</span> : (
                          <Select className="w-auto py-1 text-xs" value={d.forecast_override ? d.forecast_category ?? 'pipeline' : 'auto'} disabled={!canEdit(d.owner_id)} aria-label={`Forecast category for ${d.name}`}
                            onChange={(e) => run(save({ id: d.id, forecast_category: e.target.value as never }), 'Forecast updated')}>
                            <option value="auto">Auto · {FORECAST_LABEL[d.forecast_category ?? 'pipeline']}</option>
                            {(['pipeline', 'best_case', 'commit', 'omitted'] as const).map((c) => <option key={c} value={c}>{FORECAST_LABEL[c]}</option>)}
                          </Select>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {!data.deals.length && <EmptyState title="No deals in this period" className="py-8" />}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
