import { useState } from 'react'
import { History } from 'lucide-react'
import { useAuditLogsQuery } from '@/services/api'
import { Avatar, Badge, EmptyState, Input, PageHeader, PageLoader, Pagination } from '@/components/ui'
import { ago, dateTime, humanize } from '@/lib/format'

const tone = (event: string) => event.endsWith('deleted') ? '#ef4444' : event.endsWith('created') ? '#10b981' : event.includes('status') ? '#8b5cf6' : event.includes('assign') ? '#0ea5e9' : '#64748b'

function Diff({ old, next }: { old: Record<string, unknown> | null; next: Record<string, unknown> | null }) {
  const keys = [...new Set([...Object.keys(old ?? {}), ...Object.keys(next ?? {})])].slice(0, 6)
  if (!keys.length) return null
  return (
    <div className="mt-2 flex flex-wrap gap-2">
      {keys.map((k) => (
        <span key={k} className="chip font-mono text-[11px]">
          {humanize(k)}: {old && k in old && <span className="text-rose-500 line-through">{String(old[k] ?? '∅').slice(0, 30)}</span>}
          {next && k in next && <span className="text-emerald-600">{String(next[k] ?? '∅').slice(0, 30)}</span>}
        </span>
      ))}
    </div>
  )
}

export function AuditLogPage() {
  const [event, setEvent] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useAuditLogsQuery({ event, page })

  return (
    <div>
      <PageHeader icon={<History />} title="Audit log" description="Every change to leads, ownership, status and configuration — who, what and when." />
      <div className="card mb-5 p-3"><Input placeholder="Filter by event prefix, e.g. lead.status" value={event} onChange={(e) => { setEvent(e.target.value); setPage(1) }} /></div>
      {isLoading ? <PageLoader /> : (
        <div className="card p-2">
          <ul className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
            {data?.data.map((l) => (
              <li key={l.id} className="flex gap-4 px-4 py-3.5">
                <Avatar name={l.user?.name ?? 'System'} color={l.user?.avatar_color ?? '#64748b'} size="sm" />
                <div className="min-w-0 flex-1">
                  <p className="text-sm"><span className="font-medium text-slate-900 dark:text-white">{l.user?.name ?? 'System'}</span> <Badge color={tone(l.event)}>{l.event}</Badge> <span className="text-slate-500">{l.auditable_type} #{l.auditable_id}</span></p>
                  <Diff old={l.old_values} next={l.new_values} />
                </div>
                <span className="text-xs whitespace-nowrap text-slate-400" title={dateTime(l.created_at)}>{ago(l.created_at)}</span>
              </li>
            ))}
          </ul>
          {!data?.data.length && <EmptyState title="No audit entries" />}
          {data && <Pagination meta={data} onPage={setPage} />}
        </div>
      )}
    </div>
  )
}
