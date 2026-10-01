import { useState, type ReactNode } from 'react'
import {
  Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts'
import { BarChart3, Download, Table2 } from 'lucide-react'
import clsx from 'clsx'
import { useAppSelector } from '@/app/hooks'
import { ChartTooltip } from '@/components/crm/ChartTooltip'
import { EmptyState } from '@/components/ui'
import { downloadCsv } from '@/lib/download'
import { money, number, percent } from '@/lib/format'
import type { ReportChartType, ReportFormat, ReportResult } from '@/types'

const SLOTS = 8

/** Categorical colour for the n-th series (fixed order; "Other"/"None" are always grey). */
export function seriesColor(index: number, key?: string, own?: string | null): string {
  if (own) return own
  if (key === '__other' || key === '__none') return 'var(--series-other)'
  return `var(--series-${(index % SLOTS) + 1})`
}

export function formatValue(value: number, format: ReportFormat, currency = 'USD', compact = false): string {
  switch (format) {
    case 'money': return money(value, currency, compact)
    case 'percent': return percent(value)
    case 'duration': return `${Math.floor(value / 60)}:${String(Math.round(value % 60)).padStart(2, '0')}`
    case 'decimal': return value.toFixed(1)
    default: return number(value, compact)
  }
}

function useCurrency() {
  return useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
}

/** Flatten a result for CSV / table view: one row per group, one column per series. */
export function resultTable(r: ReportResult): { head: string[]; rows: (string | number)[][] } {
  const head = [r.dimension_label ?? 'Total', ...(r.series.length ? r.series.map((s) => s.label) : []), r.metric_label]
  const rows = r.rows.map((row) => [row.label, ...r.series.map((s) => row.values?.[s.key] ?? 0), row.value])
  return { head, rows }
}

export function exportResult(r: ReportResult, name: string) {
  const { head, rows } = resultTable(r)
  downloadCsv(`${name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${r.range.from}-${r.range.to}.csv`, [head, ...rows, [], ['Total', ...r.series.map(() => ''), r.total]])
}

function Legend({ items }: { items: { key: string; label: string; color: string }[] }) {
  return (
    <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-slate-600 dark:text-slate-300" aria-label="Legend">
      {items.map((i) => (
        <li key={i.key} className="flex items-center gap-1.5"><span className="size-2.5 rounded-[3px]" style={{ background: i.color }} />{i.label}</li>
      ))}
    </ul>
  )
}

export function ReportTable({ result }: { result: ReportResult }) {
  const currency = useCurrency()
  const { head, rows } = resultTable(result)
  const numeric = (i: number) => i > 0
  return (
    <div className="max-h-80 overflow-auto">
      <table className="w-full text-sm">
        <thead className="sticky top-0 bg-white/90 dark:bg-ink-900/90">
          <tr>{head.map((h, i) => <th key={h + i} className={clsx('table-head', numeric(i) && 'text-right')}>{h}</th>)}</tr>
        </thead>
        <tbody className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
          {rows.map((r, ri) => (
            <tr key={ri}>{r.map((c, i) => (
              <td key={i} className={clsx('table-cell', numeric(i) && 'text-right tabular-nums', i === r.length - 1 && 'font-semibold')}>
                {typeof c === 'number' ? formatValue(c, result.format, currency) : c}
              </td>
            ))}</tr>
          ))}
        </tbody>
        <tfoot>
          <tr className="border-t border-slate-200 dark:border-white/10">
            <td className="table-cell font-semibold" colSpan={head.length - 1}>Total</td>
            <td className="table-cell text-right font-semibold tabular-nums">{formatValue(result.total, result.format, currency)}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  )
}

/**
 * Renders any report result. Single-series bars and lines use one colour (the axis names
 * each bar); split series and pie slices take their entity colour or the next palette slot.
 */
export function ReportChart({ result, chart, height = 260, view = 'chart' }: { result: ReportResult; chart?: ReportChartType; height?: number; view?: 'chart' | 'table' }) {
  const currency = useCurrency()
  const type = chart ?? result.spec.chart ?? 'bar'
  const fmt = (v: number | string) => formatValue(Number(v), result.format, currency)
  const fmtAxis = (v: number) => formatValue(v, result.format, currency, true)

  if (type === 'table' || view === 'table') return <ReportTable result={result} />

  if (type === 'number' || !result.dimension_label) {
    return (
      <div className="flex flex-col justify-center py-6" style={{ minHeight: Math.min(height, 160) }}>
        <p className="font-display text-5xl font-bold tracking-tight text-slate-900 dark:text-white">{fmt(result.total)}</p>
        <p className="mt-2 text-sm text-slate-500">{result.metric_label} · {result.range.label}</p>
      </div>
    )
  }

  if (!result.rows.length || (result.total === 0 && result.rows.every((r) => r.value === 0))) {
    return <EmptyState icon={<BarChart3 />} title="Nothing in this range" description="Try a longer date range or different filters." className="py-10" />
  }

  const isDate = result.dimension_type === 'date'
  const series = result.series.map((s, i) => ({ ...s, fill: seriesColor(i, s.key, s.color) }))
  const data = result.rows.map((r) => ({ name: r.label, value: r.value, ...(r.values ?? {}) }))
  const grid = <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--chart-grid)" />
  const axisProps = { tickLine: false, axisLine: false, fontSize: 11, stroke: 'var(--chart-axis)' }
  const tooltip = <Tooltip content={<ChartTooltip formatter={fmt} />} cursor={isDate && type !== 'bar' && type !== 'stacked' ? { stroke: 'var(--chart-axis)', strokeDasharray: '3 3' } : { fill: 'rgba(139,92,246,0.06)' }} />

  if (type === 'pie') {
    const slices = result.rows.filter((r) => r.value > 0).map((r, i) => ({ ...r, fill: seriesColor(i, r.key, r.color) }))
    return (
      <div>
        <div className="relative" style={{ height }}>
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie data={slices} dataKey="value" nameKey="label" innerRadius="62%" outerRadius="92%" paddingAngle={1} stroke="var(--chart-gap)" strokeWidth={2} animationDuration={500}>
                {slices.map((s) => <Cell key={s.key} fill={s.fill} />)}
              </Pie>
              <Tooltip content={<ChartTooltip formatter={fmt} />} />
            </PieChart>
          </ResponsiveContainer>
          <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
            <span className="font-display text-2xl font-bold text-slate-900 dark:text-white">{fmtAxis(result.total)}</span>
            <span className="text-[11px] text-slate-500">{result.metric_label}</span>
          </div>
        </div>
        <Legend items={slices.map((s) => ({ key: s.key, label: `${s.label} · ${fmt(s.value)}`, color: s.fill }))} />
      </div>
    )
  }

  if (isDate && (type === 'line' || type === 'area') && !series.length) {
    const Chart = type === 'area' ? AreaChart : LineChart
    return (
      <div style={{ height }}>
        <ResponsiveContainer width="100%" height="100%">
          <Chart data={data} margin={{ left: -8, right: 8, top: 8 }}>
            <defs>
              <linearGradient id="report-area" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="var(--series-1)" stopOpacity={0.28} />
                <stop offset="100%" stopColor="var(--series-1)" stopOpacity={0} />
              </linearGradient>
            </defs>
            {grid}
            <XAxis dataKey="name" {...axisProps} minTickGap={24} />
            <YAxis {...axisProps} tickFormatter={fmtAxis} width={56} />
            {tooltip}
            {type === 'area'
              ? <Area type="monotone" dataKey="value" name={result.metric_label} stroke="var(--series-1)" strokeWidth={2} fill="url(#report-area)" activeDot={{ r: 5, strokeWidth: 2, stroke: 'var(--chart-gap)' }} />
              : <Line type="monotone" dataKey="value" name={result.metric_label} stroke="var(--series-1)" strokeWidth={2} dot={false} activeDot={{ r: 5, strokeWidth: 2, stroke: 'var(--chart-gap)' }} />}
          </Chart>
        </ResponsiveContainer>
      </div>
    )
  }

  if (isDate && (type === 'line' || type === 'area')) {
    return (
      <div>
        <div style={{ height }}>
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={data} margin={{ left: -8, right: 8, top: 8 }}>
              {grid}
              <XAxis dataKey="name" {...axisProps} minTickGap={24} />
              <YAxis {...axisProps} tickFormatter={fmtAxis} width={56} />
              {tooltip}
              {series.map((s) => <Line key={s.key} type="monotone" dataKey={s.key} name={s.label} stroke={s.fill} strokeWidth={2} dot={false} activeDot={{ r: 5, strokeWidth: 2, stroke: 'var(--chart-gap)' }} />)}
            </LineChart>
          </ResponsiveContainer>
        </div>
        <Legend items={series.map((s) => ({ key: s.key, label: s.label, color: s.fill }))} />
      </div>
    )
  }

  // Bars: columns over time, horizontal bars for categories (long names stay readable).
  const horizontal = !isDate
  const stacked = series.length > 0
  const barHeight = horizontal ? Math.max(height, data.length * 34 + 24) : height
  return (
    <div>
      <div style={{ height: barHeight }}>
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data} layout={horizontal ? 'vertical' : 'horizontal'} margin={horizontal ? { left: 8, right: 16 } : { left: -8, right: 8, top: 8 }} barCategoryGap={horizontal ? 8 : '18%'}>
            <CartesianGrid strokeDasharray="3 3" vertical={horizontal} horizontal={!horizontal} stroke="var(--chart-grid)" />
            {horizontal ? (
              <>
                <XAxis type="number" {...axisProps} tickFormatter={fmtAxis} />
                <YAxis type="category" dataKey="name" {...axisProps} width={116} tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} />
              </>
            ) : (
              <>
                <XAxis dataKey="name" {...axisProps} minTickGap={16} />
                <YAxis {...axisProps} tickFormatter={fmtAxis} width={56} />
              </>
            )}
            {tooltip}
            {stacked
              ? series.map((s, i) => (
                <Bar key={s.key} dataKey={s.key} name={s.label} stackId="a" fill={s.fill} stroke="var(--chart-gap)" strokeWidth={1}
                  radius={i === series.length - 1 ? (horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]) : 0} maxBarSize={horizontal ? 22 : 40} />
              ))
              : <Bar dataKey="value" name={result.metric_label} fill="var(--series-1)" radius={horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]} maxBarSize={horizontal ? 22 : 40} />}
          </BarChart>
        </ResponsiveContainer>
      </div>
      {stacked && <Legend items={series.map((s) => ({ key: s.key, label: s.label, color: s.fill }))} />}
    </div>
  )
}

/** A card around a report: title, total, chart/table switch and CSV export. */
export function ReportCard({ title, subtitle, result, actions, height, className, footer }: {
  title: ReactNode
  subtitle?: ReactNode
  result: ReportResult | undefined
  actions?: ReactNode
  height?: number
  className?: string
  footer?: ReactNode
}) {
  const currency = useCurrency()
  const [view, setView] = useState<'chart' | 'table'>('chart')
  const chartable = result && result.spec.chart !== 'table' && result.spec.chart !== 'number' && !!result.dimension_label
  return (
    <div className={clsx('card flex flex-col p-5', className)}>
      <div className="mb-4 flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 className="truncate font-semibold text-slate-900 dark:text-white">{title}</h3>
          <p className="text-xs text-slate-500">
            {subtitle ?? (result && `${result.metric_label}${result.dimension_label ? ` by ${result.dimension_label.toLowerCase()}` : ''}`)}
            {result && result.dimension_label && <> · <span className="font-semibold text-slate-700 dark:text-slate-200">{formatValue(result.total, result.format, currency)}</span> total</>}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-1">
          {actions}
          {chartable && (
            <button onClick={() => setView((v) => (v === 'chart' ? 'table' : 'chart'))} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-900/[0.04] hover:text-slate-700 dark:hover:bg-white/[0.06] dark:hover:text-slate-200"
              aria-label={view === 'chart' ? 'Show as table' : 'Show as chart'} title={view === 'chart' ? 'Show as table' : 'Show as chart'}>
              {view === 'chart' ? <Table2 className="size-4" /> : <BarChart3 className="size-4" />}
            </button>
          )}
          {result && (
            <button onClick={() => exportResult(result, typeof title === 'string' ? title : 'report')} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-900/[0.04] hover:text-slate-700 dark:hover:bg-white/[0.06] dark:hover:text-slate-200" aria-label="Download CSV" title="Download CSV">
              <Download className="size-4" />
            </button>
          )}
        </div>
      </div>
      <div className="flex-1">{result ? <ReportChart result={result} height={height} view={view} /> : <div className="h-48 animate-pulse rounded-2xl bg-slate-900/[0.04] dark:bg-white/[0.04]" />}</div>
      {footer}
    </div>
  )
}
