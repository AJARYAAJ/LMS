import { useState } from 'react'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { BarChart3, Download, GitBranch, Table2 } from 'lucide-react'
import { useAppSelector } from '@/app/hooks'
import { useAttributionQuery } from '@/services/api'
import { Button, EmptyState, PageLoader, Segmented, Select, StatCard } from '@/components/ui'
import { ChartTooltip } from '@/components/crm/ChartTooltip'
import { downloadCsv } from '@/lib/download'
import { money, number } from '@/lib/format'
import type { AttributionModel, AttributionResult } from '@/types'

const MODELS: { value: AttributionModel; label: string; hint: string }[] = [
  { value: 'first', label: 'First touch', hint: 'All credit to how the lead first arrived' },
  { value: 'last', label: 'Last touch', hint: 'All credit to the last touch before converting' },
  { value: 'linear', label: 'Linear', hint: 'Credit shared equally across every touch' },
]
type Metric = 'leads' | 'conversions' | 'revenue'

/** Which campaigns, sources and channels actually earn the conversions and revenue. */
export function Attribution() {
  const currency = useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
  const [by, setBy] = useState<AttributionResult['by']>('campaign')
  const [range, setRange] = useState('last_90')
  const [metric, setMetric] = useState<Metric>('conversions')
  const [view, setView] = useState<'chart' | 'table'>('chart')
  const { data, isFetching } = useAttributionQuery({ by, range })

  const revenueHidden = data && data.totals.revenue === null
  const m = revenueHidden && metric === 'revenue' ? 'conversions' : metric
  const fmt = (v: number | string) => (m === 'revenue' ? money(Number(v), currency) : number(Number(v)))
  const rows = (data?.rows ?? []).slice(0, 12)
  const chart = rows.map((r) => ({ name: r.label, ...Object.fromEntries(MODELS.map((x) => [x.label, (r[m] ?? { first: 0, last: 0, linear: 0 })[x.value]])) }))
  const exportCsv = () => data && downloadCsv(`attribution-by-${by}.csv`, [
    ['Name', 'Touches', ...MODELS.flatMap((x) => [`Leads (${x.label})`, `Conversions (${x.label})`, ...(revenueHidden ? [] : [`Revenue (${x.label})`])])],
    ...data.rows.map((r) => [r.label, r.touches, ...MODELS.flatMap((x) => [r.leads[x.value], r.conversions[x.value], ...(r.revenue ? [r.revenue[x.value]] : [])])]),
  ])

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-3">
        <Segmented value={by} onChange={setBy} options={[{ value: 'campaign', label: 'By campaign' }, { value: 'source', label: 'By source' }, { value: 'channel', label: 'By channel' }]} />
        <Select className="w-44" value={range} onChange={(e) => setRange(e.target.value)} aria-label="Leads created">
          {[['last_30', 'Last 30 days'], ['last_90', 'Last 90 days'], ['this_quarter', 'This quarter'], ['this_year', 'This year'], ['last_12_months', 'Last 12 months']].map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </Select>
        <span className="ml-auto" />
        <Segmented value={m} onChange={setMetric} options={[{ value: 'leads', label: 'Leads' }, { value: 'conversions', label: 'Conversions' }, ...(revenueHidden ? [] : [{ value: 'revenue' as const, label: 'Won revenue' }])]} />
      </div>

      {!data ? <PageLoader /> : (
        <>
          <div className="grid gap-4 sm:grid-cols-4">
            <StatCard label="Leads in range" value={number(data.totals.leads)} hint={data.range.label} />
            <StatCard label="Converted" value={number(data.totals.converted)} accent="#059669" />
            <StatCard label="Won revenue" value={data.totals.revenue === null ? '—' : money(data.totals.revenue, currency, true)} accent="#d97706" />
            <StatCard label="Touches per lead" value={data.totals.leads ? (data.totals.touches / data.totals.leads).toFixed(1) : '0'} accent="#0284c7" hint="before converting" />
          </div>

          <section className="card p-5" aria-labelledby="attr-h" aria-busy={isFetching}>
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 id="attr-h" className="flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><GitBranch className="size-4 text-brand-500" />{m === 'revenue' ? 'Won revenue' : m === 'conversions' ? 'Conversions' : 'Leads'} credited {by === 'campaign' ? 'per campaign' : by === 'source' ? 'per source' : 'per channel'}</h2>
                <p className="mt-0.5 text-xs text-slate-500">Compare the three models: where they disagree, a campaign is either opening or closing the journey.</p>
              </div>
              <div className="flex gap-1">
                <Button size="sm" variant="ghost" icon={view === 'chart' ? <Table2 className="size-4" /> : <BarChart3 className="size-4" />} onClick={() => setView(view === 'chart' ? 'table' : 'chart')}>{view === 'chart' ? 'Table' : 'Chart'}</Button>
                <Button size="sm" variant="ghost" icon={<Download className="size-4" />} onClick={exportCsv}>CSV</Button>
              </div>
            </div>
            {!rows.length ? <EmptyState icon={<GitBranch />} title="No touches in this range" description="Touches are recorded when leads arrive, fill in a form, book a meeting, call in or click a campaign email." className="py-10" />
              : view === 'chart' ? (
                <div style={{ height: Math.max(220, rows.length * 44 + 60) }}>
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={chart} layout="vertical" margin={{ left: 8, right: 16 }} barGap={2}>
                      <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="var(--chart-grid)" />
                      <XAxis type="number" tickLine={false} axisLine={false} fontSize={11} stroke="var(--chart-axis)" tickFormatter={(v) => (m === 'revenue' ? money(v, currency, true) : number(v))} />
                      <YAxis type="category" dataKey="name" width={140} tickLine={false} axisLine={false} fontSize={12} stroke="var(--chart-axis)" />
                      <Tooltip content={<ChartTooltip formatter={fmt} />} cursor={{ fill: 'rgba(139,92,246,0.06)' }} />
                      <Legend iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12 }} />
                      {MODELS.map((x, i) => <Bar key={x.value} dataKey={x.label} fill={`var(--series-${i + 1})`} radius={[0, 4, 4, 0]} maxBarSize={12} />)}
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="text-left text-xs text-slate-500"><tr><th className="py-2 pr-4 font-medium">Name</th><th className="py-2 pr-4 text-right font-medium">Touches</th>{MODELS.map((x) => <th key={x.value} className="py-2 pr-4 text-right font-medium" title={x.hint}>{x.label}</th>)}</tr></thead>
                    <tbody>
                      {data.rows.map((r) => (
                        <tr key={r.key} className="border-t border-slate-200/60 dark:border-white/[0.06]">
                          <td className="py-2 pr-4 font-medium">{r.label}</td>
                          <td className="py-2 pr-4 text-right tabular-nums">{r.touches}</td>
                          {MODELS.map((x) => <td key={x.value} className="py-2 pr-4 text-right tabular-nums">{fmt((r[m] ?? { first: 0, last: 0, linear: 0 })[x.value])}</td>)}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
          </section>
          <ul className="grid gap-3 text-xs text-slate-500 sm:grid-cols-3">{MODELS.map((x) => <li key={x.value}><strong className="text-slate-700 dark:text-slate-200">{x.label}:</strong> {x.hint}.</li>)}</ul>
        </>
      )}
    </div>
  )
}
