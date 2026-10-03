import { useMemo, useState, type DragEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import clsx from 'clsx'
import {
  ArrowDownUp, Bookmark, CalendarClock, Download, Filter, Inbox, Recycle, KanbanSquare, List, Plus, Search, Sparkles, Tag as TagIcon, Target, Trash2, Upload, UserPlus, X,
} from 'lucide-react'
import { useAction, useAppSelector, usePermissions, useToast } from '@/app/hooks'
import {
  useBulkLeadsMutation, useChangeLeadStatusMutation, useCreateSavedViewMutation, useDeleteSavedViewMutation,
  useLeadBoardQuery, useLeadsQuery, useMetaQuery, useSavedViewsQuery,
} from '@/services/api'
import {
  Avatar, Button, Checkbox, ConfirmDialog, EmptyState, Input, Menu, MenuItem, Modal, PageHeader, Pagination, Segmented, Select, Skeleton,
} from '@/components/ui'
import { Owner, PriorityBadge, RatingBadge, ScoreBar, StatusBadge } from '@/components/crm/Badges'
import { ago, friendlyDue, humanize, money } from '@/lib/format'
import { downloadFile } from '@/lib/download'
import type { Condition, Lead } from '@/types'
import { ConditionBuilder } from '@/components/crm/ConditionBuilder'
import { LeadFormModal } from './LeadFormModal'
import { ImportModal } from './ImportModal'

const FILTER_KEYS = ['search', 'status_id', 'source_id', 'owner_id', 'priority', 'rating', 'tag_id', 'follow_up', 'campaign_id', 'team_id', 'converted', 'min_score', 'created_from', 'created_to', 'conditions'] as const

export function LeadsPage() {
  const [params, setParams] = useSearchParams()
  const navigate = useNavigate()
  const run = useAction()
  const toast = useToast()
  const { write, manager } = usePermissions()
  const currency = useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
  const { data: meta } = useMetaQuery()

  const view = (params.get('view') as 'table' | 'board') ?? 'table'
  const filters = useMemo(() => Object.fromEntries(FILTER_KEYS.map((k) => [k, params.get(k) ?? ''])), [params])
  const page = Number(params.get('page') ?? 1)
  const sort = params.get('sort') ?? 'created_at'
  const direction = params.get('direction') ?? 'desc'
  const activeFilters = FILTER_KEYS.filter((k) => k !== 'search' && filters[k])

  const [selected, setSelected] = useState<number[]>([])
  const [showForm, setShowForm] = useState(params.get('new') === '1')
  const [showImport, setShowImport] = useState(false)
  const [showFilters, setShowFilters] = useState(activeFilters.length > 0)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [saveViewOpen, setSaveViewOpen] = useState(false)
  const segment = useMemo<Condition[]>(() => { try { return JSON.parse(filters.conditions || '[]') } catch { return [] } }, [filters.conditions])
  const [showSegment, setShowSegment] = useState(segment.length > 0)
  const [draftSegment, setDraftSegment] = useState<Condition[]>(segment)
  const [viewName, setViewName] = useState('')

  const list = useLeadsQuery({ ...filters, page, sort, direction, per_page: 25 }, { skip: view !== 'table' })
  const board = useLeadBoardQuery(filters, { skip: view !== 'board' })
  const { data: savedViews } = useSavedViewsQuery('lead')
  const [bulk, bulkState] = useBulkLeadsMutation()
  const [createView] = useCreateSavedViewMutation()
  const [deleteView] = useDeleteSavedViewMutation()

  const update = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(patch)) (v ? next.set(k, v) : next.delete(k))
    if (!('page' in patch)) next.delete('page')
    next.delete('new')
    setParams(next, { replace: true })
    setSelected([])
  }

  const clearFilters = () => update(Object.fromEntries(FILTER_KEYS.map((k) => [k, null])))

  const toggleSort = (column: string) =>
    update({ sort: column, direction: sort === column && direction === 'desc' ? 'asc' : 'desc', page: null })

  const leads = list.data?.data ?? []
  const allSelected = leads.length > 0 && leads.every((l) => selected.includes(l.id))

  const doBulk = async (body: Record<string, unknown>) => {
    const r = await run(bulk({ ids: selected, ...body }))
    if (r) {
      setSelected([])
      toast(r.skipped ? 'info' : 'success', r.message)
    }
    return r
  }

  const exportCsv = () =>
    downloadFile('leads/export', filters, `leads-${new Date().toISOString().slice(0, 10)}.csv`).catch(() => undefined)

  return (
    <div>
      <PageHeader
        icon={<Target />}
        title="Leads"
        description="Capture, qualify and route every opportunity."
        actions={
          <>
            <Segmented
              value={view}
              onChange={(v) => update({ view: v === 'table' ? null : v })}
              options={[{ value: 'table', label: 'Table', icon: <List /> }, { value: 'board', label: 'Board', icon: <KanbanSquare /> }]}
            />
            <Link to="/leads/queue"><Button variant="secondary" size="sm" icon={<Inbox className="size-4" />}>Queue</Button></Link>
            {manager && <Link to="/leads/trash"><Button variant="ghost" size="sm" icon={<Recycle className="size-4" />} aria-label="Recycle bin" /></Link>}
            {write && <Button variant="secondary" size="sm" icon={<Upload className="size-4" />} onClick={() => setShowImport(true)}>Import</Button>}
            <Button variant="secondary" size="sm" icon={<Download className="size-4" />} onClick={exportCsv}>Export</Button>
            {write && <Button size="sm" icon={<Plus className="size-4" />} onClick={() => setShowForm(true)}>New lead</Button>}
          </>
        }
      />

      {/* Toolbar */}
      <div className="card mb-4 p-3">
        <div className="flex flex-wrap items-center gap-2">
          <div className="min-w-56 flex-1">
            <Input
              icon={<Search className="size-4" />}
              placeholder="Search name, email, phone or company…"
              defaultValue={filters.search}
              onKeyDown={(e) => e.key === 'Enter' && update({ search: (e.target as HTMLInputElement).value })}
              onBlur={(e) => e.target.value !== filters.search && update({ search: e.target.value })}
            />
          </div>
          <Select className="w-auto" value={filters.owner_id} onChange={(e) => update({ owner_id: e.target.value })}>
            <option value="">All owners</option>
            <option value="me">My leads</option>
            {manager && <option value="unassigned">Unassigned</option>}
            {manager && meta?.users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </Select>
          <Button variant={showFilters ? 'subtle' : 'secondary'} size="md" icon={<Filter className="size-4" />} onClick={() => setShowFilters((s) => !s)}>
            Filters{activeFilters.length ? ` · ${activeFilters.length}` : ''}
          </Button>
          <Menu
            trigger={({ toggle }) => <Button variant="secondary" icon={<Bookmark className="size-4" />} onClick={toggle}>Views</Button>}
            width="w-64"
          >
            {(close) => (
              <>
                {savedViews?.length ? savedViews.map((v) => (
                  <div key={v.id} className="group flex items-center">
                    <MenuItem onClick={() => { setParams(new URLSearchParams(v.filters), { replace: true }); close() }}>
                      <span className="truncate">{v.name}</span>
                      {v.is_shared && <span className="ml-1 text-[10px] text-slate-400">shared</span>}
                    </MenuItem>
                    <button onClick={() => deleteView(v.id)} className="p-2 text-slate-300 opacity-0 group-hover:opacity-100 hover:text-rose-500" aria-label="Delete view"><Trash2 className="size-3.5" /></button>
                  </div>
                )) : <p className="px-3 py-3 text-xs text-slate-500">No saved views yet.</p>}
                <div className="mt-1 border-t border-slate-100 pt-1 dark:border-slate-800">
                  <MenuItem icon={<Plus />} onClick={() => { setSaveViewOpen(true); close() }}>Save current filters…</MenuItem>
                </div>
              </>
            )}
          </Menu>
        </div>

        {showFilters && (
          <div className="mt-3 grid animate-fade-in gap-2 border-t border-slate-100 pt-3 sm:grid-cols-3 lg:grid-cols-6 dark:border-slate-800">
            <Select value={filters.status_id} onChange={(e) => update({ status_id: e.target.value })} placeholder="Any status">
              {meta?.statuses.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </Select>
            <Select value={filters.source_id} onChange={(e) => update({ source_id: e.target.value })} placeholder="Any source">
              {meta?.sources.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </Select>
            <Select value={filters.priority} onChange={(e) => update({ priority: e.target.value })} placeholder="Any priority">
              {meta?.enums.priorities.map((p) => <option key={p} value={p}>{humanize(p)}</option>)}
            </Select>
            <Select value={filters.rating} onChange={(e) => update({ rating: e.target.value })} placeholder="Any rating">
              {meta?.enums.ratings.map((r) => <option key={r} value={r}>{humanize(r)}</option>)}
            </Select>
            <Select value={filters.tag_id} onChange={(e) => update({ tag_id: e.target.value })} placeholder="Any tag">
              {meta?.tags.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </Select>
            <Select value={filters.follow_up} onChange={(e) => update({ follow_up: e.target.value })} placeholder="Any follow-up">
              <option value="overdue">Overdue</option>
              <option value="today">Due today</option>
              <option value="upcoming">Upcoming</option>
              <option value="none">Not scheduled</option>
            </Select>
            <Select value={filters.campaign_id} onChange={(e) => update({ campaign_id: e.target.value })} placeholder="Any campaign">
              {meta?.campaigns.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
            <Select value={filters.team_id} onChange={(e) => update({ team_id: e.target.value })} placeholder="Any team">
              {meta?.teams.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </Select>
            <Select value={filters.converted} onChange={(e) => update({ converted: e.target.value })} placeholder="Open & converted">
              <option value="0">Not converted</option>
              <option value="1">Converted</option>
            </Select>
            <Select value={filters.min_score} onChange={(e) => update({ min_score: e.target.value })} placeholder="Any score">
              {[30, 50, 60, 80].map((n) => <option key={n} value={n}>Score ≥ {n}</option>)}
            </Select>
            <Input type="date" title="Created from" value={filters.created_from} onChange={(e) => update({ created_from: e.target.value })} />
            <Input type="date" title="Created to" value={filters.created_to} onChange={(e) => update({ created_to: e.target.value })} />
            <div className="sm:col-span-3 lg:col-span-6">
              <button onClick={() => setShowSegment((v) => !v)} className="flex items-center gap-1.5 text-xs font-semibold text-brand-600 hover:text-brand-700">
                <Sparkles className="size-3.5" /> {showSegment ? 'Hide' : 'Advanced'} segment builder{segment.length ? ` · ${segment.length} condition${segment.length > 1 ? 's' : ''}` : ''}
              </button>
              {showSegment && (
                <div className="mt-2 animate-fade-in rounded-2xl border border-brand-200/60 bg-brand-50/30 p-3 dark:border-brand-500/20 dark:bg-brand-500/5">
                  <ConditionBuilder value={draftSegment} onChange={setDraftSegment} emptyLabel="Combine any lead fields, e.g. country is India AND budget > 10000 AND tag is VIP." />
                  <div className="mt-3 flex gap-2">
                    <Button size="xs" onClick={() => update({ conditions: draftSegment.length ? JSON.stringify(draftSegment) : null })}>Apply segment</Button>
                    {segment.length > 0 && <Button size="xs" variant="ghost" onClick={() => { setDraftSegment([]); update({ conditions: null }) }}>Clear</Button>}
                    <span className="self-center text-[11px] text-slate-500">Save it with Views → “Save current filters”.</span>
                  </div>
                </div>
              )}
            </div>
            {activeFilters.length > 0 && (
              <button onClick={clearFilters} className="flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-rose-600 sm:col-span-3 lg:col-span-6">
                <X className="size-3.5" /> Clear all filters
              </button>
            )}
          </div>
        )}
      </div>

      {/* Bulk actions */}
      {selected.length > 0 && (
        <div className="sticky top-20 z-20 mb-4 flex animate-slide-up flex-wrap items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-white shadow-xl dark:bg-brand-600">
          <span className="mr-2 text-sm font-medium">{selected.length} selected</span>
          <select className="rounded-lg bg-white/10 px-2 py-1.5 text-sm outline-none" value="" onChange={(e) => e.target.value && doBulk({ action: 'status', lead_status_id: Number(e.target.value) })}>
            <option value="">Change status…</option>
            {meta?.statuses.map((s) => <option key={s.id} value={s.id} className="text-slate-900">{s.name}</option>)}
          </select>
          {manager && (
            <select className="rounded-lg bg-white/10 px-2 py-1.5 text-sm outline-none" value="" onChange={(e) => e.target.value && doBulk({ action: 'assign', owner_id: e.target.value === 'none' ? null : Number(e.target.value) })}>
              <option value="">Assign to…</option>
              <option value="none" className="text-slate-900">Unassigned</option>
              {meta?.users.filter((u) => u.role !== 'viewer').map((u) => <option key={u.id} value={u.id} className="text-slate-900">{u.name}</option>)}
            </select>
          )}
          <select className="rounded-lg bg-white/10 px-2 py-1.5 text-sm outline-none" value="" onChange={(e) => e.target.value && doBulk({ action: 'tag', tag_id: Number(e.target.value) })}>
            <option value="">Add tag…</option>
            {meta?.tags.map((t) => <option key={t.id} value={t.id} className="text-slate-900">{t.name}</option>)}
          </select>
          <select className="rounded-lg bg-white/10 px-2 py-1.5 text-sm outline-none" value="" onChange={(e) => e.target.value && doBulk({ action: 'priority', priority: e.target.value })}>
            <option value="">Set priority…</option>
            {meta?.enums.priorities.map((p) => <option key={p} value={p} className="text-slate-900">{humanize(p)}</option>)}
          </select>
          {manager && <button onClick={() => setConfirmDelete(true)} className="flex items-center gap-1 rounded-lg px-2 py-1.5 text-sm text-rose-300 hover:bg-white/10"><Trash2 className="size-4" /> Delete</button>}
          <button onClick={() => setSelected([])} className="ml-auto rounded-lg p-1.5 hover:bg-white/10" aria-label="Clear selection"><X className="size-4" /></button>
        </div>
      )}

      {view === 'table' ? (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[960px]">
              <thead className="border-b border-slate-100 bg-slate-50/70 dark:border-slate-800 dark:bg-slate-800/30">
                <tr>
                  <th className="w-10 pl-4">
                    <Checkbox checked={allSelected} indeterminate={selected.length > 0 && !allSelected} onChange={(v) => setSelected(v ? leads.map((l) => l.id) : [])} label="Select all" />
                  </th>
                  {[
                    ['first_name', 'Lead'], ['status', 'Status'], ['score', 'Score'], ['owner', 'Owner'], ['priority', 'Priority'],
                    ['expected_value', 'Value'], ['next_follow_up_at', 'Follow-up'], ['created_at', 'Created'],
                  ].map(([key, label]) => (
                    <th key={key} className="table-head">
                      {['first_name', 'score', 'priority', 'expected_value', 'next_follow_up_at', 'created_at'].includes(key) ? (
                        <button onClick={() => toggleSort(key)} className={clsx('inline-flex items-center gap-1 uppercase hover:text-slate-800 dark:hover:text-white', sort === key && 'text-brand-600')}>
                          {label}<ArrowDownUp className="size-3" />
                        </button>
                      ) : label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {list.isLoading && Array.from({ length: 8 }).map((_, i) => (
                  <tr key={i}><td colSpan={9} className="px-4 py-3"><Skeleton className="h-8" /></td></tr>
                ))}
                {leads.map((lead) => (
                  <tr key={lead.id} onClick={() => navigate(`/leads/${lead.id}`)} className={clsx('cursor-pointer transition hover:bg-slate-50 dark:hover:bg-slate-800/40', selected.includes(lead.id) && 'bg-brand-50/60 dark:bg-brand-500/5')}>
                    <td className="pl-4" onClick={(e) => e.stopPropagation()}>
                      <Checkbox checked={selected.includes(lead.id)} onChange={(v) => setSelected((s) => (v ? [...s, lead.id] : s.filter((x) => x !== lead.id)))} label={`Select ${lead.full_name}`} />
                    </td>
                    <td className="table-cell">
                      <div className="flex items-center gap-3">
                        <Avatar name={lead.full_name} color={lead.status?.color} size="sm" />
                        <div className="min-w-0">
                          <p className="truncate font-medium text-slate-900 dark:text-white">{lead.full_name}</p>
                          <p className="truncate text-xs text-slate-500">{[lead.company, lead.email].filter(Boolean).join(' · ') || '—'}</p>
                          {!!lead.tags?.length && (
                            <div className="mt-1 flex gap-1">
                              {lead.tags.slice(0, 3).map((t) => <span key={t.id} className="rounded px-1.5 text-[10px] font-medium" style={{ backgroundColor: `${t.color}1a`, color: t.color }}>{t.name}</span>)}
                            </div>
                          )}
                        </div>
                      </div>
                    </td>
                    <td className="table-cell"><StatusBadge status={lead.status} /></td>
                    <td className="table-cell"><div className="space-y-1"><ScoreBar score={lead.score} /><RatingBadge rating={lead.rating} /></div></td>
                    <td className="table-cell"><Owner user={lead.owner} /></td>
                    <td className="table-cell"><PriorityBadge priority={lead.priority} /></td>
                    <td className="table-cell font-medium tabular-nums">{lead.expected_value ? money(lead.expected_value, currency) : '—'}</td>
                    <td className="table-cell">
                      {lead.next_follow_up_at ? (
                        <span className={clsx('inline-flex items-center gap-1 text-xs', new Date(lead.next_follow_up_at) < new Date() && !lead.converted_at ? 'font-medium text-rose-600' : 'text-slate-600 dark:text-slate-400')}>
                          <CalendarClock className="size-3.5" />{friendlyDue(lead.next_follow_up_at)}
                        </span>
                      ) : <span className="text-xs text-slate-400">—</span>}
                    </td>
                    <td className="table-cell text-xs text-slate-500">{ago(lead.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!list.isLoading && leads.length === 0 && (
            <EmptyState
              icon={<Target />}
              title={activeFilters.length || filters.search ? 'No leads match your filters' : 'No leads yet'}
              description={activeFilters.length || filters.search ? 'Try adjusting or clearing the filters.' : 'Create your first lead or import a CSV to get started.'}
              action={activeFilters.length || filters.search ? <Button variant="secondary" onClick={clearFilters}>Clear filters</Button> : write && <Button icon={<Plus className="size-4" />} onClick={() => setShowForm(true)}>New lead</Button>}
            />
          )}
          {list.data && <Pagination meta={list.data} onPage={(p) => update({ page: String(p) })} />}
        </div>
      ) : (
        <LeadBoard columns={board.data} loading={board.isLoading} currency={currency} canWrite={write} />
      )}

      <LeadFormModal open={showForm} onClose={() => { setShowForm(false); if (params.get('new')) update({}) }} />
      <ImportModal open={showImport} onClose={() => setShowImport(false)} />
      <ConfirmDialog
        open={confirmDelete}
        onClose={() => setConfirmDelete(false)}
        loading={bulkState.isLoading}
        title={`Delete ${selected.length} leads?`}
        message="Deleted leads are removed from lists and reports. This action is logged."
        onConfirm={async () => { await doBulk({ action: 'delete' }); setConfirmDelete(false) }}
      />
      <Modal
        open={saveViewOpen}
        onClose={() => setSaveViewOpen(false)}
        size="sm"
        title="Save view"
        footer={<><Button variant="secondary" onClick={() => setSaveViewOpen(false)}>Cancel</Button><Button disabled={!viewName} onClick={async () => {
          const f = Object.fromEntries([...params.entries()].filter(([k]) => k !== 'page'))
          await run(createView({ name: viewName, entity: 'lead', filters: f }), 'View saved')
          setSaveViewOpen(false)
          setViewName('')
        }}>Save</Button></>}
      >
        <Input autoFocus placeholder="e.g. Hot inbound leads" value={viewName} onChange={(e) => setViewName(e.target.value)} />
        <p className="mt-2 text-xs text-slate-500">Saves the current filters, sort and view mode.</p>
      </Modal>
    </div>
  )
}

function LeadBoard({ columns, loading, currency, canWrite }: { columns?: import('@/services/api').BoardColumn[]; loading: boolean; currency: string; canWrite: boolean }) {
  const [changeStatus] = useChangeLeadStatusMutation()
  const run = useAction()
  const [dragOver, setDragOver] = useState<number | null>(null)

  if (loading || !columns) {
    return <div className="flex gap-4 overflow-hidden">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-96 w-72 shrink-0" />)}</div>
  }

  const onDrop = (e: DragEvent, statusId: number) => {
    e.preventDefault()
    setDragOver(null)
    const lead = JSON.parse(e.dataTransfer.getData('application/json')) as Lead
    if (lead.lead_status_id !== statusId) run(changeStatus({ id: lead.id, lead_status_id: statusId }), 'Status updated')
  }

  return (
    <div className="-mx-4 flex gap-4 overflow-x-auto px-4 pb-4 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
      {columns.map((col) => (
        <div
          key={col.status.id}
          onDragOver={(e) => { if (canWrite) { e.preventDefault(); setDragOver(col.status.id) } }}
          onDragLeave={() => setDragOver(null)}
          onDrop={(e) => onDrop(e, col.status.id)}
          className={clsx('flex w-72 shrink-0 flex-col rounded-2xl bg-slate-100/70 transition dark:bg-slate-900/60', dragOver === col.status.id && 'bg-brand-50 ring-2 ring-brand-400 dark:bg-brand-500/10')}
        >
          <div className="flex items-center justify-between px-3 pt-3 pb-2">
            <div className="flex items-center gap-2">
              <span className="size-2.5 rounded-full" style={{ backgroundColor: col.status.color }} />
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-200">{col.status.name}</span>
              <span className="rounded-full bg-white px-1.5 text-xs font-medium text-slate-500 dark:bg-slate-800">{col.total}</span>
            </div>
            <span className="text-xs text-slate-500">{money(col.value, currency, true)}</span>
          </div>
          <div className="h-1 mx-3 mb-2 rounded-full" style={{ backgroundColor: `${col.status.color}55` }} />
          <div className="flex max-h-[calc(100vh-320px)] min-h-32 flex-col gap-2 overflow-y-auto px-2 pb-3">
            {col.leads.map((lead) => (
              <Link
                key={lead.id}
                to={`/leads/${lead.id}`}
                draggable={canWrite}
                onDragStart={(e) => e.dataTransfer.setData('application/json', JSON.stringify({ id: lead.id, lead_status_id: lead.lead_status_id }))}
                className="group rounded-xl border border-slate-200/80 bg-white p-3 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-md dark:border-slate-700 dark:bg-slate-800"
              >
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{lead.full_name}</p>
                    <p className="truncate text-xs text-slate-500">{lead.company ?? lead.email ?? '—'}</p>
                  </div>
                  <RatingBadge rating={lead.rating} compact />
                </div>
                {!!lead.tags?.length && (
                  <div className="mt-2 flex flex-wrap gap-1">
                    {lead.tags.map((t) => <span key={t.id} className="flex items-center gap-0.5 rounded px-1.5 text-[10px] font-medium" style={{ backgroundColor: `${t.color}1a`, color: t.color }}><TagIcon className="size-2.5" />{t.name}</span>)}
                  </div>
                )}
                <div className="mt-3 flex items-center justify-between">
                  <ScoreBar score={lead.score} />
                  <div className="flex items-center gap-2">
                    {lead.expected_value && <span className="text-xs font-medium text-slate-700 dark:text-slate-300">{money(lead.expected_value, currency, true)}</span>}
                    {lead.owner ? <Avatar name={lead.owner.name} color={lead.owner.avatar_color} size="xs" /> : <UserPlus className="size-4 text-slate-300" />}
                  </div>
                </div>
              </Link>
            ))}
            {col.leads.length === 0 && <p className="py-6 text-center text-xs text-slate-400">Drop leads here</p>}
            {col.total > col.leads.length && <p className="py-1 text-center text-xs text-slate-400">+{col.total - col.leads.length} more — use table view</p>}
          </div>
        </div>
      ))}
    </div>
  )
}
