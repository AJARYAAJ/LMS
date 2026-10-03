import { Link } from 'react-router-dom'
import { Hand, Inbox } from 'lucide-react'
import { useAction, usePermissions } from '@/app/hooks'
import { useClaimLeadMutation, useLeadQueueQuery } from '@/services/api'
import { Button, EmptyState, PageHeader, PageLoader, ScoreRing } from '@/components/ui'
import { PriorityBadge, RatingBadge, StatusBadge } from '@/components/crm/Badges'
import { ago } from '@/lib/format'

export function QueuePage() {
  const run = useAction()
  const { write } = usePermissions()
  const { data, isLoading } = useLeadQueueQuery(undefined, { pollingInterval: 30_000 })
  const [claim] = useClaimLeadMutation()

  return (
    <div>
      <PageHeader icon={<Inbox />} title="Lead queue" description="Unassigned leads, hottest first. Claim one to make it yours." />
      {isLoading ? <PageLoader /> : !data?.length ? <div className="card"><EmptyState icon={<Inbox />} title="Queue is empty" description="Every lead has an owner. Nice work." /></div> : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {data.map((l) => (
            <div key={l.id} className="card flex flex-col p-5">
              <div className="flex items-start gap-4">
                <ScoreRing score={l.score} size={52} />
                <div className="min-w-0 flex-1">
                  <Link to={`/leads/${l.id}`} className="font-display block truncate font-semibold text-slate-900 hover:text-brand-600 dark:text-white">{l.first_name} {l.last_name}</Link>
                  <p className="truncate text-sm text-slate-500">{l.company ?? l.email ?? '—'}</p>
                  <div className="mt-2 flex flex-wrap gap-1.5"><StatusBadge status={l.status} /><PriorityBadge priority={l.priority} /></div>
                </div>
              </div>
              <div className="mt-4 flex items-center justify-between">
                <span className="flex items-center gap-2 text-xs text-slate-500"><RatingBadge rating={l.rating} /> · waiting {ago(l.created_at).replace(' ago', '')}</span>
                {write && <Button size="sm" icon={<Hand className="size-4" />} onClick={() => run(claim(l.id), 'Lead claimed — it’s yours!')}>Claim</Button>}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
