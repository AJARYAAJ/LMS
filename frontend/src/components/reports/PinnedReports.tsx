import { Link } from 'react-router-dom'
import { PinOff, SquareArrowOutUpRight } from 'lucide-react'
import { useAction } from '@/app/hooks'
import { useRunSavedReportQuery, useSaveReportMutation, useSavedReportsQuery } from '@/services/api'
import { ReportCard } from '@/components/reports/ReportChart'
import type { SavedReport } from '@/types'

function PinnedReport({ report }: { report: SavedReport }) {
  const run = useAction()
  const { data } = useRunSavedReportQuery({ id: report.id })
  const [save] = useSaveReportMutation()
  return (
    <ReportCard title={report.name} subtitle={report.description ?? undefined} result={data} height={220}
      actions={<>
        <Link to={`/reports?tab=studio&report=${report.id}`} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label={`Open ${report.name} in the studio`} title="Open in studio"><SquareArrowOutUpRight className="size-4" /></Link>
        <button onClick={() => run(save({ id: report.id, pinned: false }), 'Removed from dashboard')} className="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label={`Unpin ${report.name}`} title="Unpin"><PinOff className="size-4" /></button>
      </>} />
  )
}

/** Saved reports the user pinned, shown on the dashboard. */
export function PinnedReports() {
  const { data } = useSavedReportsQuery({ pinned: true })
  if (!data?.length) return null
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      {data.map((r) => <PinnedReport key={r.id} report={r} />)}
    </div>
  )
}
