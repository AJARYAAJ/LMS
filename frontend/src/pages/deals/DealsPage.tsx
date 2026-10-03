import { useState, type DragEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import clsx from 'clsx'
import { CalendarDays, Kanban, List, Plus, Search, TrendingUp, Trophy } from 'lucide-react'
import { useAction, useAppSelector, usePermissions } from '@/app/hooks'
import { useDealBoardQuery, useDealsQuery, useMetaQuery, useMoveDealMutation } from '@/services/api'
import { Avatar, Badge, Button, EmptyState, Input, PageHeader, Pagination, Segmented, Select, Skeleton, StatCard } from '@/components/ui'
import { date, money } from '@/lib/format'
import { DealFormModal, LostDealModal } from './DealFormModal'

export function DealsPage() {
  const navigate = useNavigate()
  const run = useAction()
  const { write } = usePermissions()
  const currency = useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
  const [view, setView] = useState<'board' | 'list'>('board')
  const [search, setSearch] = useState('')
  const [owner, setOwner] = useState('')
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [dragOver, setDragOver] = useState<number | null>(null)
  const [showForm, setShowForm] = useState(false)
  const [lostMove, setLostMove] = useState<{ id: number; stageId: number } | null>(null)
  const { data: meta } = useMetaQuery()
  const [pipelineId, setPipelineId] = useState<number | null>(null)
  const pipelines = meta?.pipelines ?? []
  const activePipeline = pipelineId ?? pipelines.find((p) => p.is_default)?.id ?? pipelines[0]?.id
  const board = useDealBoardQuery({ search, owner_id: owner, pipeline_id: activePipeline }, { skip: view !== 'board' || !meta })
  const list = useDealsQuery({ search, owner_id: owner, status, page, pipeline_id: pipelines.length > 1 ? activePipeline : undefined }, { skip: view !== 'list' || !meta })
  const [move] = useMoveDealMutation()

  const totals = (board.data ?? []).reduce((acc, c) => ({
    open: acc.open + (c.stage.is_won || c.stage.is_lost ? 0 : c.value),
    weighted: acc.weighted + (c.stage.is_won || c.stage.is_lost ? 0 : c.weighted),
    won: acc.won + (c.stage.is_won ? c.value : 0),
    count: acc.count + (c.stage.is_won || c.stage.is_lost ? 0 : c.total),
  }), { open: 0, weighted: 0, won: 0, count: 0 })

  const onDrop = (e: DragEvent, stageId: number) => {
    e.preventDefault()
    setDragOver(null)
    const { id, from } = JSON.parse(e.dataTransfer.getData('application/json'))
    if (from === stageId) return
    if (board.data?.find((c) => c.stage.id === stageId)?.stage.is_lost) setLostMove({ id, stageId })
    else run(move({ id, pipeline_stage_id: stageId }), 'Deal moved')
  }

  return (
    <div>
      <PageHeader icon={<Kanban />} title="Pipeline" description="Drag deals between stages. Probabilities update automatically."
        actions={<>
          <Link to="/forecast" className="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-500/10"><TrendingUp className="size-4" />Forecast</Link>
          <Segmented value={view} onChange={setView} options={[{ value: 'board', label: 'Board', icon: <Kanban /> }, { value: 'list', label: 'List', icon: <List /> }]} />
          {write && <Button size="sm" icon={<Plus className="size-4" />} onClick={() => setShowForm(true)}>New deal</Button>}
        </>}
      />

      {view === 'board' && (
        <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <StatCard label="Open pipeline" value={money(totals.open, currency, true)} hint={`${totals.count} open deals`} accent="#8b5cf6" />
          <StatCard label="Weighted forecast" value={money(totals.weighted, currency, true)} hint="amount × probability" accent="#0ea5e9" />
          <StatCard label="Won" value={money(totals.won, currency, true)} icon={<Trophy />} accent="#10b981" />
          <StatCard label="Avg. deal size" value={money(totals.count ? totals.open / totals.count : 0, currency, true)} accent="#d946ef" />
        </div>
      )}

      {pipelines.length > 1 && (
        <div className="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="Pipelines">
          {pipelines.map((p) => (
            <button key={p.id} role="tab" aria-selected={p.id === activePipeline} onClick={() => setPipelineId(p.id)}
              className={clsx('chip transition', p.id === activePipeline && 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>{p.name}</button>
          ))}
        </div>
      )}

      <div className="card mb-5 flex flex-wrap gap-2 p-3">
        <div className="min-w-56 flex-1"><Input icon={<Search className="size-4" />} placeholder="Search deals…" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} /></div>
        <Select className="w-auto" value={owner} onChange={(e) => setOwner(e.target.value)}><option value="">All owners</option><option value="me">My deals</option></Select>
        {view === 'list' && <Select className="w-auto" value={status} onChange={(e) => setStatus(e.target.value)}><option value="">Any status</option><option value="open">Open</option><option value="won">Won</option><option value="lost">Lost</option></Select>}
      </div>

      {view === 'board' ? (
        board.isLoading ? <div className="flex gap-4">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-96 w-72" />)}</div> : (
          <div className="-mx-4 flex gap-4 overflow-x-auto px-4 pb-4 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            {board.data?.map((col) => (
              <div key={col.stage.id}
                onDragOver={(e) => { if (write) { e.preventDefault(); setDragOver(col.stage.id) } }}
                onDragLeave={() => setDragOver(null)}
                onDrop={(e) => onDrop(e, col.stage.id)}
                className={clsx('glass flex w-[290px] shrink-0 flex-col rounded-3xl transition-all duration-300', dragOver === col.stage.id && 'scale-[1.01] ring-2 ring-brand-400')}>
                <div className="relative overflow-hidden rounded-t-3xl px-4 pt-4 pb-3">
                  <div className="absolute inset-x-0 top-0 h-1" style={{ background: `linear-gradient(90deg, ${col.stage.color}, transparent)` }} />
                  <div className="flex items-center justify-between">
                    <span className="font-display text-sm font-semibold text-slate-900 dark:text-white">{col.stage.name}</span>
                    <Badge color={col.stage.color}>{col.stage.probability}%</Badge>
                  </div>
                  <p className="mt-1 text-xs text-slate-500">{col.total} deals · <span className="font-medium text-slate-700 dark:text-slate-300">{money(col.value, currency, true)}</span></p>
                </div>
                <div className="flex max-h-[calc(100vh-380px)] min-h-40 flex-col gap-2.5 overflow-y-auto px-3 pb-3">
                  {col.deals.map((d) => (
                    <Link key={d.id} to={`/deals/${d.id}`} draggable={write}
                      onDragStart={(e) => e.dataTransfer.setData('application/json', JSON.stringify({ id: d.id, from: col.stage.id }))}
                      className="group rounded-2xl border border-white/80 bg-white/80 p-3.5 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[0_14px_30px_-14px_rgba(76,29,149,0.5)] dark:border-white/[0.06] dark:bg-white/[0.04]">
                      <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{d.name}</p>
                      <p className="truncate text-xs text-slate-500">{d.account?.name ?? '—'}</p>
                      <div className="mt-3 flex items-center justify-between">
                        <span className="font-display text-[15px] font-bold text-slate-900 dark:text-white">{money(d.amount, d.currency || currency, true)}</span>
                        <div className="flex items-center gap-2">
                          {d.expected_close_date && <span className="flex items-center gap-1 text-[11px] text-slate-500"><CalendarDays className="size-3" />{date(d.expected_close_date, 'MMM d')}</span>}
                          {d.owner && <Avatar name={d.owner.name} color={d.owner.avatar_color} size="xs" />}
                        </div>
                      </div>
                    </Link>
                  ))}
                  {!col.deals.length && <p className="py-8 text-center text-xs text-slate-400">Drop deals here</p>}
                </div>
              </div>
            ))}
          </div>
        )
      ) : (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[800px]">
              <thead><tr>{['Deal', 'Account', 'Stage', 'Amount', 'Close date', 'Owner', 'Status'].map((h) => <th key={h} className="table-head">{h}</th>)}</tr></thead>
              <tbody className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
                {list.data?.data.map((d) => (
                  <tr key={d.id} onClick={() => navigate(`/deals/${d.id}`)} className="cursor-pointer transition hover:bg-brand-50/40 dark:hover:bg-white/[0.03]">
                    <td className="table-cell font-medium text-slate-900 dark:text-white">{d.name}</td>
                    <td className="table-cell">{d.account?.name ?? '—'}</td>
                    <td className="table-cell">{d.stage && <Badge color={d.stage.color} dot>{d.stage.name}</Badge>}</td>
                    <td className="table-cell font-display font-semibold">{money(d.amount, d.currency || currency)}</td>
                    <td className="table-cell text-slate-500">{date(d.expected_close_date)}</td>
                    <td className="table-cell">{d.owner && <span className="flex items-center gap-2"><Avatar name={d.owner.name} color={d.owner.avatar_color} size="xs" />{d.owner.name}</span>}</td>
                    <td className="table-cell"><Badge color={d.status === 'won' ? '#10b981' : d.status === 'lost' ? '#ef4444' : '#8b5cf6'}>{d.status}</Badge></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {list.data && !list.data.data.length && <EmptyState title="No deals" description="Convert a lead or create a deal to start your pipeline." />}
          {list.data && <Pagination meta={list.data} onPage={setPage} />}
        </div>
      )}
      <DealFormModal open={showForm} onClose={() => setShowForm(false)} />
      <LostDealModal open={!!lostMove} onClose={() => setLostMove(null)} onConfirm={async (reason) => { if (lostMove && (await run(move({ id: lostMove.id, pipeline_stage_id: lostMove.stageId, lost_reason: reason }), 'Deal marked lost'))) setLostMove(null) }} />
    </div>
  )
}
