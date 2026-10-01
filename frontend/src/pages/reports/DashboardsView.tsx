import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import clsx from 'clsx'
import {
  ArrowLeft, ArrowRight, Check, Columns2, Flag, Gauge, LayoutDashboard, Pencil, Plus, Share2, Sparkles, Square, Trash2, Wand2,
} from 'lucide-react'
import { useAction, useCurrentUser } from '@/app/hooks'
import {
  useAskReportMutation, useDashboardsQuery, useDeleteDashboardMutation, useGoalsQuery, useReportCatalogQuery, useRunReportQuery,
  useRunSavedReportQuery, useSaveDashboardMutation, useSavedReportsQuery, useTypeReportQuery,
} from '@/services/api'
import { Button, ConfirmDialog, EmptyState, Field, Input, Modal, PageLoader, Select, Tabs, Toggle } from '@/components/ui'
import { ReportCard, ReportChart } from '@/components/reports/ReportChart'
import { GoalCard } from '@/pages/reports/GoalsView'
import { Kpi } from '@/pages/reports/TypeReportView'
import type { AskAnswer, CustomDashboard, DashboardTile } from '@/types'

const newId = () => Math.random().toString(36).slice(2, 10)

// ------------------------------------------------------------------ tiles

function TileFrame({ tile, editing, children, onMove, onSpan, onRemove }: {
  tile: DashboardTile
  editing: boolean
  children: React.ReactNode
  onMove: (dir: -1 | 1) => void
  onSpan: () => void
  onRemove: () => void
}) {
  return (
    <div className={clsx('relative min-w-0', tile.span === 2 && 'lg:col-span-2', editing && 'rounded-[26px] ring-2 ring-brand-400/40 ring-dashed')}>
      {editing && (
        <div className="absolute -top-3 right-4 z-10 flex gap-1 rounded-full border border-slate-200 bg-white p-1 shadow-md dark:border-white/10 dark:bg-ink-900">
          <button onClick={() => onMove(-1)} className="rounded-full p-1 text-slate-500 hover:text-brand-600" aria-label="Move tile earlier"><ArrowLeft className="size-3.5" /></button>
          <button onClick={() => onMove(1)} className="rounded-full p-1 text-slate-500 hover:text-brand-600" aria-label="Move tile later"><ArrowRight className="size-3.5" /></button>
          <button onClick={onSpan} className="rounded-full p-1 text-slate-500 hover:text-brand-600" aria-label={tile.span === 2 ? 'Make tile half width' : 'Make tile full width'}>{tile.span === 2 ? <Square className="size-3.5" /> : <Columns2 className="size-3.5" />}</button>
          <button onClick={onRemove} className="rounded-full p-1 text-slate-500 hover:text-rose-600" aria-label="Remove tile"><Trash2 className="size-3.5" /></button>
        </div>
      )}
      {children}
    </div>
  )
}

function SavedReportTile({ tile }: { tile: DashboardTile }) {
  const { data, isError } = useRunSavedReportQuery({ id: tile.report_id ?? 0 }, { skip: !tile.report_id })
  if (isError) return <div className="card p-6 text-sm text-slate-500">This report was deleted or is no longer shared.</div>
  return <ReportCard title={tile.title || data?.report?.name || 'Report'} subtitle={data?.report?.description ?? undefined} result={data} height={tile.span === 2 ? 280 : 230} />
}

function SpecTile({ tile }: { tile: DashboardTile }) {
  const { data } = useRunReportQuery(tile.spec!, { skip: !tile.spec })
  return <ReportCard title={tile.title || 'Chart'} result={data} height={tile.span === 2 ? 280 : 230} />
}

function KpiTile({ tile }: { tile: DashboardTile }) {
  const { data } = useTypeReportQuery({ type: tile.type ?? 'leads', range: 'last_30' })
  return (
    <div className="card p-5">
      <h3 className="mb-3 font-semibold text-slate-900 dark:text-white">{tile.title || `${data?.title ?? ''} · last 30 days`}</h3>
      <div className={clsx('grid gap-3', tile.span === 2 ? 'sm:grid-cols-3 xl:grid-cols-5' : 'sm:grid-cols-2')}>
        {data ? data.kpis.slice(0, tile.span === 2 ? 5 : 4).map((k) => <Kpi key={k.label} kpi={k} />) : <div className="h-24 animate-pulse rounded-2xl bg-slate-900/[0.04]" />}
      </div>
    </div>
  )
}

function GoalsTile({ tile }: { tile: DashboardTile }) {
  const { data } = useGoalsQuery({ mine: true })
  return (
    <div className="card p-5">
      <h3 className="mb-1 flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><Flag className="size-4 text-brand-500" />{tile.title || 'Goals'}</h3>
      {!data?.length ? <p className="py-6 text-sm text-slate-500">No goals for you or the team yet.</p>
        : <div className={clsx('divide-y divide-slate-200/60 dark:divide-white/[0.06]', tile.span === 2 && 'grid gap-x-6 divide-y-0 sm:grid-cols-2')}>{data.map((g) => <GoalCard key={g.id} goal={g} compact />)}</div>}
    </div>
  )
}

function Tile({ tile }: { tile: DashboardTile }) {
  switch (tile.kind) {
    case 'report': return <SavedReportTile tile={tile} />
    case 'spec': return <SpecTile tile={tile} />
    case 'kpis': return <KpiTile tile={tile} />
    default: return <GoalsTile tile={tile} />
  }
}

// ------------------------------------------------------------------ add tile

function AddTileModal({ open, onClose, onAdd }: { open: boolean; onClose: () => void; onAdd: (tile: DashboardTile) => void }) {
  const [kind, setKind] = useState<'ask' | 'report' | 'kpis' | 'goals'>('ask')
  const { data: reports } = useSavedReportsQuery()
  const { data: catalog } = useReportCatalogQuery()
  const [ask, askState] = useAskReportMutation()
  const run = useAction()
  const [question, setQuestion] = useState('')
  const [answer, setAnswer] = useState<AskAnswer | null>(null)
  const [reportId, setReportId] = useState('')
  const [type, setType] = useState('pipeline')
  const [span, setSpan] = useState<1 | 2>(1)

  useEffect(() => { if (open) { setAnswer(null); setQuestion('') } }, [open])

  const add = () => {
    const base = { id: newId(), span }
    if (kind === 'ask' && answer) onAdd({ ...base, kind: 'spec', title: answer.title, spec: answer.result.spec })
    else if (kind === 'report' && reportId) onAdd({ ...base, kind: 'report', report_id: Number(reportId) })
    else if (kind === 'kpis') onAdd({ ...base, kind: 'kpis', type })
    else if (kind === 'goals') onAdd({ ...base, kind: 'goals' })
    onClose()
  }
  const ready = (kind === 'ask' && !!answer) || (kind === 'report' && !!reportId) || kind === 'kpis' || kind === 'goals'

  return (
    <Modal open={open} onClose={onClose} size="lg" title="Add a tile"
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button disabled={!ready} onClick={add} icon={<Plus className="size-4" />}>Add tile</Button></>}>
      <Tabs className="mb-4" value={kind} onChange={setKind} tabs={[
        { value: 'ask', label: 'Ask a question', icon: <Sparkles /> },
        { value: 'report', label: 'Saved report', icon: <Wand2 /> },
        { value: 'kpis', label: 'KPI row', icon: <Gauge /> },
        { value: 'goals', label: 'Goals', icon: <Flag /> },
      ]} />
      {kind === 'ask' && (
        <div className="space-y-3">
          <form className="flex gap-2" onSubmit={async (e) => { e.preventDefault(); const r = await run(ask(question)); if (r) setAnswer(r) }}>
            <Input value={question} onChange={(e) => setQuestion(e.target.value)} placeholder="e.g. won revenue by rep this quarter" aria-label="Question for the new tile" />
            <Button type="submit" variant="secondary" loading={askState.isLoading} disabled={question.trim().length < 3}>Preview</Button>
          </form>
          {answer && <div className="rounded-2xl border border-slate-200 p-4 dark:border-white/10"><p className="mb-2 text-sm font-semibold">{answer.title}</p><ReportChart result={answer.result} height={200} /></div>}
        </div>
      )}
      {kind === 'report' && (
        <Field label="Report">
          <Select value={reportId} onChange={(e) => setReportId(e.target.value)} placeholder="Choose a saved report">{reports?.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</Select>
        </Field>
      )}
      {kind === 'kpis' && (
        <Field label="Area" hint="Shows that area's headline numbers for the last 30 days.">
          <Select value={type} onChange={(e) => setType(e.target.value)}>{catalog?.types.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}</Select>
        </Field>
      )}
      {kind === 'goals' && <p className="text-sm text-slate-500">Your goals and the team's, with progress against pace.</p>}
      <div className="mt-4"><Toggle checked={span === 2} onChange={(v) => setSpan(v ? 2 : 1)} label="Full width" /></div>
    </Modal>
  )
}

/** Small dialog used from the Ask bar: put an answer on an existing or new dashboard. */
export function AddToDashboardModal({ tile, onClose }: { tile: DashboardTile | null; onClose: () => void }) {
  const run = useAction()
  const me = useCurrentUser()
  const { data } = useDashboardsQuery()
  const [save, state] = useSaveDashboardMutation()
  const [, setParams] = useSearchParams()
  const editable = data?.filter((d) => d.user_id === me?.id || me?.role === 'admin') ?? []
  const [target, setTarget] = useState('')
  const [name, setName] = useState('')
  useEffect(() => { if (tile) { setTarget(editable[0] ? String(editable[0].id) : 'new'); setName('') } }, [tile]) // eslint-disable-line react-hooks/exhaustive-deps

  const submit = async () => {
    if (!tile) return
    const existing = editable.find((d) => String(d.id) === target)
    const body = existing ? { id: existing.id, tiles: [...existing.tiles, tile] } : { name: name || 'My dashboard', tiles: [tile] }
    const r = await run(save(body), 'Added to dashboard')
    if (r) { onClose(); setParams({ tab: 'dashboards', dashboard: String(r.id) }) }
  }

  return (
    <Modal open={!!tile} onClose={onClose} size="sm" title="Add to a dashboard"
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} loading={state.isLoading} disabled={target === 'new' && !name.trim()}>Add</Button></>}>
      <div className="space-y-4">
        <Field label="Dashboard">
          <Select value={target} onChange={(e) => setTarget(e.target.value)}>
            {editable.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            <option value="new">New dashboard…</option>
          </Select>
        </Field>
        {target === 'new' && <Field label="Name"><Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Monday pipeline review" /></Field>}
      </div>
    </Modal>
  )
}

// ------------------------------------------------------------------ view

export function DashboardsView() {
  const run = useAction()
  const me = useCurrentUser()
  const [params, setParams] = useSearchParams()
  const { data: dashboards, isLoading } = useDashboardsQuery()
  const [save, saveState] = useSaveDashboardMutation()
  const [remove] = useDeleteDashboardMutation()
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState<CustomDashboard | null>(null)
  const [adding, setAdding] = useState(false)
  const [creating, setCreating] = useState(false)
  const [newName, setNewName] = useState('')
  const [deleting, setDeleting] = useState<CustomDashboard | null>(null)

  const selectedId = Number(params.get('dashboard')) || dashboards?.[0]?.id
  const selected = dashboards?.find((d) => d.id === selectedId)
  const board = editing && draft ? draft : selected
  const canEdit = !!selected && (selected.user_id === me?.id || me?.role === 'admin')

  useEffect(() => { setEditing(false); setDraft(null) }, [selectedId])

  const select = (id: number) => setParams({ tab: 'dashboards', dashboard: String(id) })
  const startEdit = () => { if (selected) { setDraft(structuredClone(selected)); setEditing(true) } }
  const updateTiles = (fn: (tiles: DashboardTile[]) => DashboardTile[]) => setDraft((d) => (d ? { ...d, tiles: fn(d.tiles) } : d))
  const move = (i: number, dir: -1 | 1) => updateTiles((t) => {
    const j = i + dir
    if (j < 0 || j >= t.length) return t
    const next = [...t];
    [next[i], next[j]] = [next[j], next[i]]
    return next
  })
  const finish = async () => {
    if (!draft) return
    if (await run(save({ id: draft.id, name: draft.name, is_shared: draft.is_shared, tiles: draft.tiles }), 'Dashboard saved')) setEditing(false)
  }
  const create = async () => {
    const r = await run(save({ name: newName.trim(), tiles: [{ id: newId(), kind: 'kpis', type: 'leads', span: 2 }, { id: newId(), kind: 'goals', span: 1 }] }), 'Dashboard created')
    if (r) { setCreating(false); setNewName(''); select(r.id) }
  }

  if (isLoading) return <PageLoader />

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-2">
        {dashboards?.map((d) => (
          <button key={d.id} onClick={() => select(d.id)} className={clsx('chip transition', d.id === selectedId && 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>
            {d.name}{d.is_shared && <Share2 className="ml-1 inline size-3 opacity-60" aria-label="shared" />}
          </button>
        ))}
        <Button size="xs" variant="subtle" icon={<Plus className="size-3.5" />} onClick={() => setCreating(true)}>New dashboard</Button>
        {board && canEdit && (
          <div className="ml-auto flex items-center gap-2">
            {editing ? <>
              <Input value={draft?.name ?? ''} onChange={(e) => setDraft((d) => (d ? { ...d, name: e.target.value } : d))} className="w-48" aria-label="Dashboard name" />
              <Toggle checked={!!draft?.is_shared} onChange={(v) => setDraft((d) => (d ? { ...d, is_shared: v } : d))} label="Shared" />
              <Button size="sm" variant="secondary" icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>Add tile</Button>
              <Button size="sm" variant="ghost" onClick={() => { setEditing(false); setDraft(null) }}>Cancel</Button>
              <Button size="sm" icon={<Check className="size-4" />} loading={saveState.isLoading} onClick={finish}>Done</Button>
            </> : <>
              <Button size="sm" variant="secondary" icon={<Pencil className="size-4" />} onClick={startEdit}>Edit</Button>
              <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" />} onClick={() => setDeleting(selected!)} aria-label="Delete dashboard" />
            </>}
          </div>
        )}
      </div>

      {!board ? (
        <div className="card"><EmptyState icon={<LayoutDashboard />} title="No dashboards yet" description="Create one and fill it with saved reports, questions you ask, KPI rows and goals." action={<Button onClick={() => setCreating(true)}>Create a dashboard</Button>} /></div>
      ) : !board.tiles.length ? (
        <div className="card"><EmptyState icon={<LayoutDashboard />} title="This dashboard is empty" description={canEdit ? 'Edit it to add tiles.' : 'The owner hasn’t added anything yet.'} /></div>
      ) : (
        <div className="grid gap-6 lg:grid-cols-2">
          {board.tiles.map((t, i) => (
            <TileFrame key={t.id} tile={t} editing={editing} onMove={(d) => move(i, d)}
              onSpan={() => updateTiles((tiles) => tiles.map((x) => (x.id === t.id ? { ...x, span: x.span === 2 ? 1 : 2 } : x)))}
              onRemove={() => updateTiles((tiles) => tiles.filter((x) => x.id !== t.id))}>
              <Tile tile={t} />
            </TileFrame>
          ))}
        </div>
      )}
      {board && !canEdit && <p className="text-center text-xs text-slate-400">Shared by {board.user?.name}. Numbers reflect what your own access allows.</p>}

      <AddTileModal open={adding} onClose={() => setAdding(false)} onAdd={(tile) => updateTiles((t) => [...t, tile])} />
      <Modal open={creating} onClose={() => setCreating(false)} size="sm" title="New dashboard"
        footer={<><Button variant="secondary" onClick={() => setCreating(false)}>Cancel</Button><Button disabled={!newName.trim()} onClick={create} loading={saveState.isLoading}>Create</Button></>}>
        <Field label="Name"><Input value={newName} onChange={(e) => setNewName(e.target.value)} placeholder="e.g. Monday pipeline review" autoFocus /></Field>
      </Modal>
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} title={`Delete ${deleting?.name}?`} message="Saved reports on it are kept."
        onConfirm={async () => { if (deleting) await run(remove(deleting.id), 'Dashboard deleted'); setDeleting(null); setParams({ tab: 'dashboards' }) }} />
    </div>
  )
}
