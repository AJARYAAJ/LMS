import { useState } from 'react'
import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { BarChart3, Download } from 'lucide-react'
import { useAppSelector } from '@/app/hooks'
import { useReportsQuery } from '@/services/api'
import { Avatar, Button, Card, EmptyState, Input, PageHeader, PageLoader } from '@/components/ui'
import { ChartTooltip } from '@/components/crm/ChartTooltip'
import { money, number, percent } from '@/lib/format'

const FUNNEL_COLORS = ['#8b5cf6', '#a855f7', '#d946ef', '#ec4899', '#10b981']

export function ReportsPage() {
  const currency = useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
  const [range, setRange] = useState(() => {
    const to = new Date()
    const from = new Date(Date.now() - 89 * 86_400_000)
    return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) }
  })
  const { data, isFetching } = useReportsQuery(range)

  const exportCsv = () => {
    if (!data) return
    const rows = [['Section', 'Name', 'Leads', 'Converted', 'Conversion %', 'Value'],
      ...data.sources.map((s) => ['Source', s.name, s.leads, s.converted, s.conversion_rate, s.value]),
      ...data.reps.map((r) => ['Rep', r.name, r.leads, r.converted, r.conversion_rate, r.won_value]),
      ...data.campaigns.map((c) => ['Campaign', c.name, c.leads, c.converted, c.conversion_rate, c.cost])]
    const blob = new Blob([rows.map((r) => r.join(',')).join('\n')], { type: 'text/csv' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `lead-report-${range.from}-${range.to}.csv`
    a.click()
  }

  const max = data?.funnel[0]?.count || 1

  return (
    <div>
      <PageHeader icon={<BarChart3 />} title="Insights" description="Funnel, source ROI, team performance and pipeline health."
        actions={<>
          <Input type="date" value={range.from} onChange={(e) => setRange((r) => ({ ...r, from: e.target.value }))} className="w-auto" />
          <span className="text-slate-400">→</span>
          <Input type="date" value={range.to} onChange={(e) => setRange((r) => ({ ...r, to: e.target.value }))} className="w-auto" />
          <Button variant="secondary" size="sm" icon={<Download className="size-4" />} onClick={exportCsv}>CSV</Button>
        </>}
      />
      {!data ? <PageLoader /> : (
        <div className={isFetching ? 'opacity-60 transition' : 'transition'}>
          {/* Funnel river */}
          <Card title="Conversion funnel" subtitle="How leads flow from capture to won deals" className="mb-6">
            <div className="space-y-3 py-2">
              {data.funnel.map((f, i) => {
                const w = Math.max((f.count / max) * 100, 4)
                const prev = i > 0 ? data.funnel[i - 1].count : null
                return (
                  <div key={f.stage} className="flex items-center gap-4">
                    <span className="w-24 shrink-0 text-right text-sm font-medium text-slate-600 dark:text-slate-300">{f.stage}</span>
                    <div className="relative flex-1">
                      <div className="mx-auto flex h-12 items-center justify-center rounded-2xl text-sm font-bold text-white shadow-[0_10px_30px_-12px_var(--c)] transition-all duration-700"
                        style={{ width: `${w}%`, background: `linear-gradient(90deg, ${FUNNEL_COLORS[i]}, ${FUNNEL_COLORS[i]}cc)`, ['--c' as string]: FUNNEL_COLORS[i] }}>
                        <span className="font-display">{number(f.count)}</span>
                      </div>
                    </div>
                    <span className="w-20 shrink-0 text-xs text-slate-500">{prev ? percent((f.count / (prev || 1)) * 100, 0) + ' step' : ''}</span>
                  </div>
                )
              })}
            </div>
          </Card>

          <div className="mb-6 grid gap-6 lg:grid-cols-2">
            <Card title="Source performance" padded={false}>
              <table className="w-full">
                <thead><tr>{['Source', 'Leads', 'Qualified', 'Converted', 'Conv.', 'Value'].map((h) => <th key={h} className="table-head">{h}</th>)}</tr></thead>
                <tbody className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
                  {data.sources.map((s) => (
                    <tr key={s.id}>
                      <td className="table-cell"><span className="flex items-center gap-2"><span className="size-2.5 rounded-full" style={{ backgroundColor: s.color }} />{s.name}</span></td>
                      <td className="table-cell">{s.leads}</td><td className="table-cell">{s.qualified}</td><td className="table-cell">{s.converted}</td>
                      <td className="table-cell"><span className="font-semibold text-emerald-600">{percent(s.conversion_rate)}</span></td>
                      <td className="table-cell font-display">{money(s.value, currency, true)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {!data.sources.length && <EmptyState title="No data in range" />}
            </Card>
            <Card title="Lead aging" subtitle="Open leads by age">
              <div className="h-64">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={data.aging} margin={{ left: -20 }}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="currentColor" className="text-slate-200 dark:text-white/5" />
                    <XAxis dataKey="bucket" tickLine={false} axisLine={false} fontSize={11} stroke="#94a3b8" />
                    <YAxis allowDecimals={false} tickLine={false} axisLine={false} fontSize={11} stroke="#94a3b8" />
                    <Tooltip content={<ChartTooltip />} cursor={{ fill: 'rgba(139,92,246,0.06)' }} />
                    <Bar dataKey="count" name="Leads" radius={[10, 10, 4, 4]} maxBarSize={56}>
                      {data.aging.map((_, i) => <Cell key={i} fill={['#10b981', '#8b5cf6', '#f59e0b', '#ef4444'][i]} />)}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </Card>
          </div>

          <Card title="Team leaderboard" className="mb-6" padded={false}>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[640px]">
                <thead><tr>{['#', 'Rep', 'Leads', 'Converted', 'Conversion', 'Activities', 'Won value'].map((h) => <th key={h} className="table-head">{h}</th>)}</tr></thead>
                <tbody className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
                  {[...data.reps].sort((a, b) => b.won_value - a.won_value || b.converted - a.converted).map((r, i) => (
                    <tr key={r.id}>
                      <td className="table-cell font-display font-bold text-slate-400">{i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : i + 1}</td>
                      <td className="table-cell"><span className="flex items-center gap-2"><Avatar name={r.name} color={r.avatar_color} size="sm" />{r.name}</span></td>
                      <td className="table-cell">{r.leads}</td><td className="table-cell">{r.converted}</td>
                      <td className="table-cell">
                        <div className="flex items-center gap-2"><div className="h-1.5 w-20 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10"><div className="h-full rounded-full bg-gradient-to-r from-brand-500 to-fuchsia-500" style={{ width: `${Math.min(r.conversion_rate, 100)}%` }} /></div><span className="text-xs">{percent(r.conversion_rate)}</span></div>
                      </td>
                      <td className="table-cell">{r.activities}</td>
                      <td className="table-cell font-display font-semibold">{money(r.won_value, currency, true)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>

          <Card title="Campaign ROI" padded={false}>
            <table className="w-full">
              <thead><tr>{['Campaign', 'Status', 'Leads', 'Converted', 'Conv.', 'Cost', 'Cost / lead'].map((h) => <th key={h} className="table-head">{h}</th>)}</tr></thead>
              <tbody className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
                {data.campaigns.map((c) => (
                  <tr key={c.id}>
                    <td className="table-cell font-medium">{c.name}</td><td className="table-cell capitalize">{c.status}</td><td className="table-cell">{c.leads}</td><td className="table-cell">{c.converted}</td>
                    <td className="table-cell">{percent(c.conversion_rate)}</td><td className="table-cell">{money(c.cost, currency)}</td><td className="table-cell font-display">{c.cost_per_lead ? money(c.cost_per_lead, currency) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {!data.campaigns.length && <EmptyState title="No campaigns" />}
          </Card>
        </div>
      )}
    </div>
  )
}
