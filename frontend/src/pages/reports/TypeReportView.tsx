import clsx from 'clsx'
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react'
import { useAppSelector } from '@/app/hooks'
import { useTypeReportQuery } from '@/services/api'
import { ReportCard, formatValue } from '@/components/reports/ReportChart'
import type { ReportKpi } from '@/types'

export interface RangeQuery { range: string; from?: string; to?: string }

export function Kpi({ kpi }: { kpi: ReportKpi }) {
  const currency = useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
  const good = kpi.delta === null || kpi.delta === 0 ? null : (kpi.delta > 0) === (kpi.better === 'up')
  const Icon = kpi.delta === null || kpi.delta === 0 ? Minus : kpi.delta > 0 ? ArrowUpRight : ArrowDownRight
  return (
    <div className="card p-4">
      <p className="text-[11px] font-semibold tracking-[0.08em] text-slate-500 uppercase dark:text-slate-400">{kpi.label}</p>
      <p className="font-display mt-2 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{formatValue(kpi.value, kpi.format, currency, true)}</p>
      <p className="mt-1 flex items-center gap-1 text-xs">
        <span className={clsx('inline-flex items-center gap-0.5 font-semibold', good === null ? 'text-slate-400' : good ? 'text-emerald-600' : 'text-rose-600')}>
          <Icon className="size-3.5" aria-hidden />
          {kpi.delta === null ? 'new' : `${kpi.delta > 0 ? '+' : ''}${kpi.delta}${kpi.delta_unit === 'pts' ? ' pts' : '%'}`}
        </span>
        <span className="text-slate-400">vs {formatValue(kpi.previous, kpi.format, currency, true)} before</span>
      </p>
    </div>
  )
}

/** One area's report page: KPI tiles (with change vs the previous period) and its charts. */
export function TypeReportView({ type, range, children }: { type: string; range: RangeQuery; children?: React.ReactNode }) {
  const { data, isFetching, isError } = useTypeReportQuery({ type, ...range })

  if (isError) return <div className="card p-8 text-center text-sm text-slate-500">This report could not be loaded.</div>

  return (
    <div className={clsx('space-y-6 transition', isFetching && 'opacity-60')}>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        {data ? data.kpis.map((k) => <Kpi key={k.label} kpi={k} />) : Array.from({ length: 5 }, (_, i) => <div key={i} className="card h-[104px] animate-pulse" />)}
      </div>
      <div className="grid gap-6 lg:grid-cols-2">
        {data
          ? data.widgets.map((w) => <ReportCard key={w.title} title={w.title} subtitle={w.subtitle ?? undefined} result={w.result} className={w.span === 2 ? 'lg:col-span-2' : ''} height={w.span === 2 ? 280 : 240} />)
          : Array.from({ length: 4 }, (_, i) => <ReportCard key={i} title="Loading…" result={undefined} />)}
      </div>
      {children}
    </div>
  )
}
