import { useEffect, useState } from 'react'
import clsx from 'clsx'
import { Package, Pencil, Plus, Star, Trash2 } from 'lucide-react'
import { useAction, useAppSelector } from '@/app/hooks'
import { resources, useDeleteSettingMutation, useSaveSettingMutation, useSettings } from '@/services/api'
import { Badge, Button, Card, ColorPicker, ConfirmDialog, EmptyState, Field, Input, Modal, Select, Textarea, Toggle } from '@/components/ui'
import { SectionHeader } from '@/components/crm/ConditionBuilder'
import { money } from '@/lib/format'
import type { Pipeline, PipelineStage, Product } from '@/types'

/** Pipelines and, for the selected one, its stages and win probabilities. */
export function PipelinesSection() {
  const run = useAction()
  const { data: pipelines } = useSettings(resources.pipelines)
  const { data: stages } = useSettings(resources.stages)
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [selected, setSelected] = useState<number | null>(null)
  const [pipelineForm, setPipelineForm] = useState<Partial<Pipeline> | null>(null)
  const [stageForm, setStageForm] = useState<Partial<PipelineStage> | null>(null)
  const [deleting, setDeleting] = useState<{ kind: 'pipeline' | 'stage'; id: number; name: string } | null>(null)

  const current = pipelines?.find((p) => p.id === selected) ?? pipelines?.find((p) => p.is_default) ?? pipelines?.[0]
  const list = (stages ?? []).filter((s) => s.pipeline_id === current?.id).sort((a, b) => a.display_order - b.display_order)

  return (
    <>
      <SectionHeader title="Deal pipelines" description="Separate pipelines for new business, renewals, partners… each with its own stages and win probabilities (used for forecasting)."
        action={<Button size="sm" icon={<Plus className="size-4" />} onClick={() => setPipelineForm({ name: '' })}>New pipeline</Button>} />
      <div className="mb-4 flex flex-wrap items-center gap-2">
        {pipelines?.map((p) => (
          <button key={p.id} onClick={() => setSelected(p.id)} className={clsx('chip transition', p.id === current?.id && 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>
            {p.is_default && <Star className="mr-1 inline size-3 fill-current" aria-label="default" />}{p.name}<span className="ml-1 text-slate-400">· {p.deals_count ?? 0}</span>
          </button>
        ))}
      </div>
      {current && (
        <Card>
          <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
              <h3 className="font-semibold text-slate-900 dark:text-white">{current.name}{current.is_default && <Badge className="ml-2" color="#8b5cf6">Default</Badge>}</h3>
              <p className="text-xs text-slate-500">{current.deals_count ?? 0} deals · {list.length} stages</p>
            </div>
            <div className="flex gap-1">
              {!current.is_default && <Button size="xs" variant="ghost" icon={<Star className="size-3.5" />} onClick={() => run(save({ ...resources.pipelines, id: current.id, body: { is_default: true } }), 'Default pipeline changed')}>Make default</Button>}
              <Button size="xs" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={() => setPipelineForm(current)}>Rename</Button>
              {!current.is_default && <Button size="xs" variant="ghost" icon={<Trash2 className="size-3.5" />} onClick={() => setDeleting({ kind: 'pipeline', id: current.id, name: current.name })}>Delete</Button>}
              <Button size="xs" variant="subtle" icon={<Plus className="size-3.5" />} onClick={() => setStageForm({ pipeline_id: current.id, probability: 10, color: '#8b5cf6' })}>Add stage</Button>
            </div>
          </div>
          <ol className="flex flex-wrap gap-2">
            {list.map((s, i) => (
              <li key={s.id} className="group relative flex min-w-40 flex-1 items-center gap-3 rounded-2xl border border-slate-200 p-3 dark:border-white/10">
                <span className="font-display text-xs font-bold text-slate-400">{i + 1}</span>
                <span className="size-3 rounded-full" style={{ background: s.color }} />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold">{s.name}</p>
                  <p className="text-xs text-slate-500">{s.is_won ? 'Won' : s.is_lost ? 'Lost' : `${s.probability}%`} · {s.deals_count ?? 0} deals</p>
                </div>
                <button onClick={() => setStageForm(s)} className="rounded-lg p-1 text-slate-400 opacity-0 group-hover:opacity-100 hover:text-brand-600" aria-label={`Edit stage ${s.name}`}><Pencil className="size-3.5" /></button>
                <button onClick={() => setDeleting({ kind: 'stage', id: s.id, name: s.name })} className="rounded-lg p-1 text-slate-400 opacity-0 group-hover:opacity-100 hover:text-rose-600" aria-label={`Delete stage ${s.name}`}><Trash2 className="size-3.5" /></button>
              </li>
            ))}
          </ol>
        </Card>
      )}

      <Modal open={!!pipelineForm} onClose={() => setPipelineForm(null)} size="sm" title={pipelineForm?.id ? 'Rename pipeline' : 'New pipeline'} description={pipelineForm?.id ? undefined : 'It starts with five stages you can rename or change.'}
        footer={<><Button variant="secondary" onClick={() => setPipelineForm(null)}>Cancel</Button><Button disabled={!pipelineForm?.name?.trim()} loading={saveState.isLoading} onClick={async () => {
          const r = await run(save({ ...resources.pipelines, id: pipelineForm?.id, body: { name: pipelineForm?.name } }), 'Pipeline saved')
          if (r) { setSelected((r.data as Pipeline).id); setPipelineForm(null) }
        }}>Save</Button></>}>
        <Field label="Name"><Input value={pipelineForm?.name ?? ''} onChange={(e) => setPipelineForm((f) => ({ ...f, name: e.target.value }))} placeholder="e.g. Renewals" autoFocus /></Field>
      </Modal>

      <Modal open={!!stageForm} onClose={() => setStageForm(null)} size="sm" title={stageForm?.id ? 'Edit stage' : 'Add stage'}
        footer={<><Button variant="secondary" onClick={() => setStageForm(null)}>Cancel</Button><Button disabled={!stageForm?.name?.trim()} loading={saveState.isLoading} onClick={async () => {
          const body = { name: stageForm?.name, probability: Number(stageForm?.probability ?? 0), color: stageForm?.color, is_won: !!stageForm?.is_won, is_lost: !!stageForm?.is_lost, ...(stageForm?.id ? {} : { pipeline_id: stageForm?.pipeline_id }) }
          if (await run(save({ ...resources.stages, id: stageForm?.id, body }), 'Stage saved')) setStageForm(null)
        }}>Save</Button></>}>
        <div className="space-y-4">
          <Field label="Name"><Input value={stageForm?.name ?? ''} onChange={(e) => setStageForm((f) => ({ ...f, name: e.target.value }))} autoFocus /></Field>
          <Field label="Win probability %"><Input type="number" min={0} max={100} value={stageForm?.probability ?? 0} onChange={(e) => setStageForm((f) => ({ ...f, probability: Number(e.target.value) }))} /></Field>
          <Field label="Colour"><ColorPicker value={stageForm?.color ?? '#8b5cf6'} onChange={(c) => setStageForm((f) => ({ ...f, color: c }))} /></Field>
          <Toggle checked={!!stageForm?.is_won} onChange={(v) => setStageForm((f) => ({ ...f, is_won: v, is_lost: v ? false : f?.is_lost }))} label="Counts as won" />
          <Toggle checked={!!stageForm?.is_lost} onChange={(v) => setStageForm((f) => ({ ...f, is_lost: v, is_won: v ? false : f?.is_won }))} label="Counts as lost" />
        </div>
      </Modal>

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} title={`Delete ${deleting?.name}?`} message={deleting?.kind === 'pipeline' ? 'Only empty pipelines can be deleted; its stages go with it.' : 'Stages that still have deals can’t be deleted.'}
        onConfirm={async () => {
          if (deleting) await run(remove({ ...(deleting.kind === 'pipeline' ? resources.pipelines : resources.stages), id: deleting.id }), 'Deleted')
          if (deleting?.kind === 'pipeline') setSelected(null)
          setDeleting(null)
        }} />
    </>
  )
}

const BILLING: Record<Product['billing'], string> = { one_time: 'One-time', monthly: 'Per month', yearly: 'Per year' }

/** Product & price catalog used when building quotes. */
export function ProductsSection() {
  const run = useAction()
  const currency = useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
  const { data } = useSettings(resources.products)
  const [save, saveState] = useSaveSettingMutation()
  const [remove] = useDeleteSettingMutation()
  const [editing, setEditing] = useState<Partial<Product> | null>(null)
  const [form, setForm] = useState<Partial<Product>>({})
  useEffect(() => { if (editing) setForm({ billing: 'one_time', is_active: true, unit_price: 0, ...editing }) }, [editing])

  return (
    <>
      <SectionHeader title="Products & prices" description="Your catalog. Pick products when building a quote; prices can still be changed per quote."
        action={<Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing({})}>Add product</Button>} />
      {!data?.length ? <Card><EmptyState icon={<Package />} title="No products yet" description="Add what you sell to build quotes in seconds." /></Card> : (
        <Card padded={false}>
          <table className="w-full">
            <thead><tr>{['Product', 'SKU', 'Price', 'Billing', 'Status', ''].map((h) => <th key={h} className="table-head">{h}</th>)}</tr></thead>
            <tbody className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
              {data.map((p) => (
                <tr key={p.id} className="group">
                  <td className="table-cell"><p className="font-medium text-slate-900 dark:text-white">{p.name}</p>{p.description && <p className="max-w-sm truncate text-xs text-slate-500">{p.description}</p>}</td>
                  <td className="table-cell font-mono text-xs">{p.sku ?? '—'}</td>
                  <td className="table-cell font-display font-semibold">{money(p.unit_price, currency)}</td>
                  <td className="table-cell">{BILLING[p.billing]}</td>
                  <td className="table-cell"><Badge color={p.is_active ? '#10b981' : '#94a3b8'} dot>{p.is_active ? 'Active' : 'Hidden'}</Badge></td>
                  <td className="table-cell text-right">
                    <button onClick={() => setEditing(p)} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label={`Edit ${p.name}`}><Pencil className="size-4" /></button>
                    <button onClick={() => run(remove({ ...resources.products, id: p.id }), 'Product deleted')} className="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label={`Delete ${p.name}`}><Trash2 className="size-4" /></button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
      <Modal open={!!editing} onClose={() => setEditing(null)} title={editing?.id ? 'Edit product' : 'Add product'}
        footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button disabled={!form.name?.trim()} loading={saveState.isLoading} onClick={async () => {
          const body = { name: form.name, sku: form.sku || null, description: form.description || null, unit_price: Number(form.unit_price ?? 0), billing: form.billing, is_active: form.is_active }
          if (await run(save({ ...resources.products, id: editing?.id, body }), 'Product saved')) setEditing(null)
        }}>Save</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Name" required className="sm:col-span-2"><Input value={form.name ?? ''} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} /></Field>
          <Field label="SKU"><Input value={form.sku ?? ''} onChange={(e) => setForm((f) => ({ ...f, sku: e.target.value }))} /></Field>
          <Field label={`Price (${currency})`}><Input type="number" min={0} step="any" value={form.unit_price ?? 0} onChange={(e) => setForm((f) => ({ ...f, unit_price: Number(e.target.value) }))} /></Field>
          <Field label="Billing"><Select value={form.billing ?? 'one_time'} onChange={(e) => setForm((f) => ({ ...f, billing: e.target.value as Product['billing'] }))}>{Object.entries(BILLING).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</Select></Field>
          <div className="flex items-end pb-2"><Toggle checked={form.is_active ?? true} onChange={(v) => setForm((f) => ({ ...f, is_active: v }))} label="Available for quotes" /></div>
          <Field label="Description" className="sm:col-span-2"><Textarea rows={2} value={form.description ?? ''} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} /></Field>
        </div>
      </Modal>
    </>
  )
}
