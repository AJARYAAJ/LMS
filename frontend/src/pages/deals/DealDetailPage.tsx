import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import clsx from 'clsx'
import { ArrowLeft, Building2, Check, Pencil, Phone, Target, Trash2, Trophy, User } from 'lucide-react'
import { useAction, useAppSelector, usePermissions } from '@/app/hooks'
import { useActivitiesQuery, useDealQuery, useDeleteDealMutation, useMetaQuery, useMoveDealMutation } from '@/services/api'
import { Avatar, Badge, Button, Card, ConfirmDialog, DescriptionList, EmptyState, PageLoader } from '@/components/ui'
import { Timeline } from '@/components/crm/Timeline'
import { ActivityModal } from '@/components/crm/ActivityModal'
import { date, money } from '@/lib/format'
import { DealFormModal } from './DealFormModal'

export function DealDetailPage() {
  const id = Number(useParams().id)
  const navigate = useNavigate()
  const run = useAction()
  const { write, manager } = usePermissions()
  const currency = useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
  const { data: deal, isLoading } = useDealQuery(id)
  const { data: meta } = useMetaQuery()
  const { data: activities } = useActivitiesQuery({ type: 'deals', id, per_page: 50 })
  const [move] = useMoveDealMutation()
  const [remove, removeState] = useDeleteDealMutation()
  const [modal, setModal] = useState<null | 'edit' | 'activity' | 'delete'>(null)

  if (isLoading) return <PageLoader />
  if (!deal) return <EmptyState title="Deal not found" />

  const color = deal.stage?.color ?? '#8b5cf6'
  const weighted = (Number(deal.amount) * deal.probability) / 100

  return (
    <div className="space-y-6">
      <Link to="/deals" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand-600"><ArrowLeft className="size-4" /> Pipeline</Link>
      <section className="card overflow-hidden p-6">
        <div className="absolute inset-0 -z-10 opacity-50" style={{ background: `radial-gradient(70% 120% at 0% 0%, ${color}40, transparent 60%)` }} />
        <div className="flex flex-wrap items-start justify-between gap-6">
          <div>
            <div className="flex items-center gap-2">
              <Badge color={color} dot>{deal.stage?.name ?? 'No stage'}</Badge>
              <Badge color={deal.status === 'won' ? '#10b981' : deal.status === 'lost' ? '#ef4444' : '#8b5cf6'}>{deal.status}</Badge>
            </div>
            <h1 className="mt-3 text-3xl font-bold tracking-tight text-slate-900 dark:text-white">{deal.name}</h1>
            <p className="mt-1 flex flex-wrap gap-4 text-sm text-slate-500">
              {deal.account && <Link to={`/accounts/${deal.account.id}`} className="flex items-center gap-1 hover:text-brand-600"><Building2 className="size-4" />{deal.account.name}</Link>}
              {deal.contact && <Link to={`/contacts/${deal.contact.id}`} className="flex items-center gap-1 hover:text-brand-600"><User className="size-4" />{deal.contact.first_name} {deal.contact.last_name}</Link>}
              {deal.lead && <Link to={`/leads/${deal.lead.id}`} className="flex items-center gap-1 hover:text-brand-600"><Target className="size-4" />Original lead</Link>}
            </p>
          </div>
          <div className="text-right">
            <p className="text-xs tracking-wider text-slate-500 uppercase">Amount</p>
            <p className="font-display text-4xl font-bold gradient-text">{money(deal.amount, deal.currency || currency)}</p>
            <p className="text-sm text-slate-500">{deal.probability}% · weighted {money(weighted, deal.currency || currency)}</p>
          </div>
        </div>
        {write && (
          <div className="mt-6 flex flex-wrap gap-2">
            <Button size="sm" variant="secondary" icon={<Phone className="size-4" />} onClick={() => setModal('activity')}>Log activity</Button>
            <Button size="sm" variant="secondary" icon={<Pencil className="size-4" />} onClick={() => setModal('edit')}>Edit</Button>
            {manager && <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" />} onClick={() => setModal('delete')}>Delete</Button>}
          </div>
        )}
      </section>

      <section className="card p-2">
        <ol className="flex gap-1 overflow-x-auto">
          {meta?.stages.map((s) => {
            const current = s.id === deal.pipeline_stage_id
            return (
              <li key={s.id} className="min-w-28 flex-1">
                <button disabled={!write || current} onClick={() => run(move({ id: deal.id, pipeline_stage_id: s.id }), `Moved to ${s.name}`)}
                  className={clsx('flex w-full items-center justify-center gap-1.5 rounded-2xl px-3 py-3 text-sm font-medium transition-all', current ? 'text-white shadow-lg' : 'text-slate-500 hover:bg-slate-900/[0.04] dark:hover:bg-white/[0.05]')}
                  style={current ? { background: `linear-gradient(135deg, ${s.color}, ${s.color}bb)` } : undefined}>
                  {s.is_won ? <Trophy className="size-4" /> : current ? <Check className="size-4" /> : null}{s.name}
                </button>
              </li>
            )
          })}
        </ol>
      </section>

      <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
        <Card title="Timeline">{activities?.data.length ? <Timeline items={activities.data} /> : <EmptyState title="No activity yet" />}</Card>
        <Card title="Details">
          <DescriptionList items={[
            { label: 'Owner', value: deal.owner && <span className="inline-flex items-center gap-2"><Avatar name={deal.owner.name} color={deal.owner.avatar_color} size="xs" />{deal.owner.name}</span> },
            { label: 'Expected close', value: date(deal.expected_close_date) },
            { label: 'Closed', value: deal.closed_at && date(deal.closed_at) },
            { label: 'Lost reason', value: deal.lost_reason },
            { label: 'Created', value: date(deal.created_at) },
          ]} />
          {deal.description && <p className="mt-4 text-sm whitespace-pre-line text-slate-600 dark:text-slate-400">{deal.description}</p>}
        </Card>
      </div>

      <DealFormModal open={modal === 'edit'} onClose={() => setModal(null)} deal={deal} />
      <ActivityModal open={modal === 'activity'} onClose={() => setModal(null)} subjectType="deals" subjectId={deal.id} />
      <ConfirmDialog open={modal === 'delete'} onClose={() => setModal(null)} title="Delete deal?" loading={removeState.isLoading}
        onConfirm={async () => { if (await run(remove(deal.id), 'Deal deleted') !== undefined) navigate('/deals') }} />
    </div>
  )
}
