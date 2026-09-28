import { useState } from 'react'
import { Recycle, RotateCcw } from 'lucide-react'
import { useAction } from '@/app/hooks'
import { useRestoreLeadMutation, useTrashQuery } from '@/services/api'
import { Button, EmptyState, PageHeader, PageLoader, Pagination } from '@/components/ui'
import { StatusBadge } from '@/components/crm/Badges'
import { ago } from '@/lib/format'

export function TrashPage() {
  const run = useAction()
  const [page, setPage] = useState(1)
  const { data, isLoading } = useTrashQuery(page)
  const [restore] = useRestoreLeadMutation()

  return (
    <div>
      <PageHeader icon={<Recycle />} title="Recycle bin" description="Deleted and merged leads. Restore anything removed by mistake." />
      {isLoading ? <PageLoader /> : (
        <div className="card p-2">
          <ul className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
            {data?.data.map((l) => (
              <li key={l.id} className="flex items-center gap-4 px-4 py-3">
                <div className="min-w-0 flex-1"><p className="font-medium text-slate-900 dark:text-white">{l.first_name} {l.last_name}</p><p className="text-xs text-slate-500">{l.company ?? l.email} · deleted {ago(l.deleted_at)}</p></div>
                <StatusBadge status={l.status} />
                <Button size="sm" variant="secondary" icon={<RotateCcw className="size-4" />} onClick={() => run(restore(l.id), 'Lead restored')}>Restore</Button>
              </li>
            ))}
          </ul>
          {!data?.data.length && <EmptyState icon={<Recycle />} title="Recycle bin is empty" />}
          {data && <Pagination meta={data} onPage={setPage} />}
        </div>
      )}
    </div>
  )
}
