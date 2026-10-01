import { useSearchParams } from 'react-router-dom'
import { Activity, BarChart3, CheckSquare, Flag, Kanban, MessagesSquare, PhoneCall, Target, Wand2 } from 'lucide-react'
import { PageHeader, Input, Select, Tabs } from '@/components/ui'
import { TypeReportView, type RangeQuery } from '@/pages/reports/TypeReportView'
import { LeadDeepDive } from '@/pages/reports/LeadDeepDive'
import { GoalsView } from '@/pages/reports/GoalsView'
import { ReportStudio } from '@/pages/reports/ReportStudio'

type Tab = 'leads' | 'pipeline' | 'activities' | 'tasks' | 'calls' | 'messaging' | 'goals' | 'studio'

const TABS: { value: Tab; label: string; icon: React.ReactNode }[] = [
  { value: 'leads', label: 'Leads', icon: <Target /> },
  { value: 'pipeline', label: 'Pipeline', icon: <Kanban /> },
  { value: 'activities', label: 'Activities', icon: <Activity /> },
  { value: 'tasks', label: 'Tasks', icon: <CheckSquare /> },
  { value: 'calls', label: 'AI calls', icon: <PhoneCall /> },
  { value: 'messaging', label: 'Messaging', icon: <MessagesSquare /> },
  { value: 'goals', label: 'Goals', icon: <Flag /> },
  { value: 'studio', label: 'Report studio', icon: <Wand2 /> },
]

const RANGES: [string, string][] = [
  ['last_7', 'Last 7 days'], ['last_30', 'Last 30 days'], ['last_90', 'Last 90 days'], ['this_month', 'This month'], ['last_month', 'Last month'],
  ['this_quarter', 'This quarter'], ['this_year', 'This year'], ['last_12_months', 'Last 12 months'], ['custom', 'Custom…'],
]

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

/** Concrete dates for a preset (the lead deep-dive takes from/to). */
function presetDates(preset: string, from: string, to: string): [string, string] {
  const now = new Date()
  const back = (days: number) => iso(new Date(now.getFullYear(), now.getMonth(), now.getDate() - days))
  switch (preset) {
    case 'custom': return [from, to]
    case 'last_7': return [back(6), iso(now)]
    case 'last_30': return [back(29), iso(now)]
    case 'this_month': return [iso(new Date(now.getFullYear(), now.getMonth(), 1)), iso(now)]
    case 'last_month': return [iso(new Date(now.getFullYear(), now.getMonth() - 1, 1)), iso(new Date(now.getFullYear(), now.getMonth(), 0))]
    case 'this_quarter': return [iso(new Date(now.getFullYear(), Math.floor(now.getMonth() / 3) * 3, 1)), iso(now)]
    case 'this_year': return [iso(new Date(now.getFullYear(), 0, 1)), iso(now)]
    case 'last_12_months': return [iso(new Date(now.getFullYear(), now.getMonth() - 11, 1)), iso(now)]
    default: return [back(89), iso(now)]
  }
}

export function ReportsPage() {
  const [params, setParams] = useSearchParams()
  const tab = (TABS.some((t) => t.value === params.get('tab')) ? params.get('tab') : 'leads') as Tab
  const preset = params.get('range') ?? 'last_90'
  const from = params.get('from') ?? iso(new Date(Date.now() - 29 * 86_400_000))
  const to = params.get('to') ?? iso(new Date())
  const range: RangeQuery = preset === 'custom' ? { range: 'custom', from, to } : { range: preset }
  const [deepFrom, deepTo] = presetDates(preset, from, to)

  const update = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params)
    Object.entries(patch).forEach(([k, v]) => (v === null ? next.delete(k) : next.set(k, v)))
    setParams(next, { replace: true })
  }

  const showRange = tab !== 'goals' && tab !== 'studio'

  return (
    <div>
      <PageHeader icon={<BarChart3 />} title="Insights" description="Reports and charts for every part of LeadFlow, plus goals and a studio to build your own."
        actions={showRange && <>
          <Select value={preset} onChange={(e) => update({ range: e.target.value })} className="w-auto" aria-label="Date range">
            {RANGES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
          </Select>
          {preset === 'custom' && <>
            <Input type="date" value={from} onChange={(e) => update({ from: e.target.value })} className="w-auto" aria-label="From" />
            <span className="text-slate-400">→</span>
            <Input type="date" value={to} onChange={(e) => update({ to: e.target.value })} className="w-auto" aria-label="To" />
          </>}
        </>}
      />
      <Tabs className="mb-6" tabs={TABS} value={tab} onChange={(t) => update({ tab: t, report: null })} />

      {tab === 'goals' ? <GoalsView />
        : tab === 'studio' ? <ReportStudio />
          : (
            <TypeReportView key={tab} type={tab} range={range}>
              {tab === 'leads' && (
                <div>
                  <h2 className="mt-4 mb-4 font-display text-lg font-bold text-slate-900 dark:text-white">Funnel, sources & team</h2>
                  <LeadDeepDive from={deepFrom} to={deepTo} />
                </div>
              )}
            </TypeReportView>
          )}
    </div>
  )
}
