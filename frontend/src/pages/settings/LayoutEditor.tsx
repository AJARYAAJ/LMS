import { useEffect, useMemo, useState, type DragEvent } from 'react'
import clsx from 'clsx'
import { ArrowDown, ArrowUp, Eye, EyeOff, GripVertical, LayoutTemplate, Lock, Plus, RotateCcw, Trash2 } from 'lucide-react'
import { useAction } from '@/app/hooks'
import { useLayoutsQuery, useMetaQuery, useResetLayoutMutation, useSaveLayoutMutation } from '@/services/api'
import { Badge, Button, ConfirmDialog, EmptyState, PageLoader, Tabs } from '@/components/ui'
import { SectionHeader } from '@/components/crm/ConditionBuilder'
import { fieldDef, LayoutFields } from '@/lib/layouts'
import type { LayoutEntity, Meta, PageLayout } from '@/types'

type Draft = Pick<PageLayout, 'sections' | 'hidden'>
const ENTITIES: { value: LayoutEntity; label: string }[] = [
  { value: 'lead', label: 'Leads' }, { value: 'contact', label: 'Contacts' }, { value: 'account', label: 'Accounts' }, { value: 'deal', label: 'Deals' },
]

/**
 * Visual page-layout editor: drag fields between sections, reorder and
 * rename sections, hide fields, and preview the resulting form live.
 */
export function LayoutEditor() {
  const run = useAction()
  const { data: meta } = useMetaQuery()
  const { data: layouts, isLoading } = useLayoutsQuery()
  const [save, saveState] = useSaveLayoutMutation()
  const [reset, resetState] = useResetLayoutMutation()
  const [entity, setEntity] = useState<LayoutEntity>('lead')
  const [draft, setDraft] = useState<Draft | null>(null)
  const [dragging, setDragging] = useState<string | null>(null)
  const [overTarget, setOverTarget] = useState<string | null>(null)
  const [confirmReset, setConfirmReset] = useState(false)

  const server = layouts?.[entity]
  useEffect(() => { if (server) setDraft({ sections: server.sections, hidden: server.hidden }) }, [server])

  const dirty = useMemo(() => !!server && !!draft && JSON.stringify({ s: server.sections, h: server.hidden }) !== JSON.stringify({ s: draft.sections, h: draft.hidden }), [server, draft])
  const label = (key: string) => fieldDef(entity, key, meta)?.label ?? key
  const required = server?.required ?? []

  /** Move a field into a section (before `beforeKey`) or into the hidden pool. */
  const move = (key: string, target: number | 'hidden', beforeKey?: string) => {
    if (target === 'hidden' && required.includes(key)) return
    setDraft((d) => {
      if (!d) return d
      const sections = d.sections.map((s) => ({ ...s, fields: s.fields.filter((f) => f !== key) }))
      let hidden = d.hidden.filter((f) => f !== key)
      if (target === 'hidden') hidden = [...hidden, key]
      else {
        const fields = sections[target].fields
        const at = beforeKey ? fields.indexOf(beforeKey) : -1
        fields.splice(at < 0 ? fields.length : at, 0, key)
      }
      return { sections, hidden }
    })
  }

  const onDrop = (e: DragEvent, target: number | 'hidden', beforeKey?: string) => {
    e.preventDefault()
    e.stopPropagation()
    const key = e.dataTransfer.getData('text/plain')
    if (key && key !== beforeKey) move(key, target, beforeKey)
    setDragging(null)
    setOverTarget(null)
  }

  const patchSection = (i: number, p: Partial<Draft['sections'][number]>) => setDraft((d) => d && { ...d, sections: d.sections.map((s, j) => (j === i ? { ...s, ...p } : s)) })
  const moveSection = (i: number, dir: -1 | 1) => setDraft((d) => {
    if (!d) return d
    const sections = [...d.sections]
    const j = i + dir
    if (j < 0 || j >= sections.length) return d
    ;[sections[i], sections[j]] = [sections[j], sections[i]]
    return { ...d, sections }
  })
  /** Delete a section: its optional fields become hidden, required ones move to the first remaining section. */
  const removeSection = (i: number) => setDraft((d) => {
    if (!d || d.sections.length === 1) return d
    const removed = d.sections[i].fields
    const sections = d.sections.filter((_, j) => j !== i)
    sections[0] = { ...sections[0], fields: [...sections[0].fields, ...removed.filter((f) => required.includes(f))] }
    return { sections, hidden: [...d.hidden, ...removed.filter((f) => !required.includes(f))] }
  })

  const previewMeta = useMemo<Meta | undefined>(() => meta && draft ? { ...meta, layouts: { ...meta.layouts, [entity]: { ...draft, customized: true } } } : meta, [meta, draft, entity])

  const chip = (key: string, where: number | 'hidden') => {
    const locked = required.includes(key)
    const custom = key.startsWith('custom.')
    return (
      <div
        key={key}
        draggable
        onDragStart={(e) => { e.dataTransfer.setData('text/plain', key); e.dataTransfer.effectAllowed = 'move'; setDragging(key) }}
        onDragEnd={() => { setDragging(null); setOverTarget(null) }}
        onDragOver={(e) => { e.preventDefault(); setOverTarget(`${where}:${key}`) }}
        onDrop={(e) => onDrop(e, where, key)}
        className={clsx(
          'group flex cursor-grab items-center gap-2 rounded-xl border bg-white/90 px-2.5 py-2 text-sm shadow-sm transition active:cursor-grabbing dark:bg-white/[0.04]',
          dragging === key ? 'opacity-40' : 'border-slate-200/80 dark:border-white/10',
          overTarget === `${where}:${key}` && dragging !== key && 'border-brand-400 shadow-[0_-3px_0_0_rgba(139,92,246,0.9)]',
        )}
      >
        <GripVertical className="size-3.5 shrink-0 text-slate-300" />
        <span className="min-w-0 flex-1 truncate font-medium text-slate-800 dark:text-slate-200" title={label(key)}>{label(key)}</span>
        {custom && <Badge color="#d946ef">custom</Badge>}
        {locked ? <Lock className="size-3.5 text-slate-400" aria-label="Required field" /> : where === 'hidden' ? (
          <button type="button" onClick={() => move(key, 0)} className="text-slate-400 hover:text-brand-600" aria-label={`Show ${label(key)}`}><Eye className="size-4" /></button>
        ) : (
          <button type="button" onClick={() => move(key, 'hidden')} className="text-slate-300 opacity-0 transition group-hover:opacity-100 hover:text-rose-500" aria-label={`Hide ${label(key)}`}><EyeOff className="size-4" /></button>
        )}
      </div>
    )
  }

  if (isLoading || !draft || !server) return <PageLoader />

  return (
    <>
      <SectionHeader
        title="Page layouts"
        description="Arrange how records look. Drag fields between sections, rename or reorder sections, and hide fields your team doesn't use. Forms and detail panels update for everyone."
        action={
          <div className="flex gap-2">
            {server.customized && <Button size="sm" variant="ghost" icon={<RotateCcw className="size-4" />} onClick={() => setConfirmReset(true)}>Reset to default</Button>}
            <Button size="sm" variant="secondary" disabled={!dirty} onClick={() => setDraft({ sections: server.sections, hidden: server.hidden })}>Discard</Button>
            <Button size="sm" disabled={!dirty} loading={saveState.isLoading} onClick={() => run(save({ entity, ...draft }), 'Layout saved')}>Save layout</Button>
          </div>
        }
      />
      <Tabs value={entity} onChange={(e) => { if (!dirty || window.confirm('Discard unsaved layout changes?')) setEntity(e) }} className="mb-5 w-fit" tabs={ENTITIES.map((e) => ({ ...e, label: <>{e.label}{layouts?.[e.value].customized && <span className="ml-1 size-1.5 rounded-full bg-fuchsia-500" />}</> }))} />

      <div className="grid gap-6 xl:grid-cols-[1fr_320px]">
        <div className="space-y-4">
          {draft.sections.map((section, i) => (
            <div key={i} className="card p-4">
              <div className="mb-3 flex items-center gap-2">
                <LayoutTemplate className="size-4 text-brand-500" />
                <input
                  value={section.title}
                  onChange={(e) => patchSection(i, { title: e.target.value })}
                  className="font-display min-w-0 flex-1 rounded-lg bg-transparent px-1 py-0.5 text-base font-semibold text-slate-900 outline-none focus:bg-white focus:ring-2 focus:ring-brand-400/40 dark:text-white dark:focus:bg-white/5"
                  aria-label="Section title"
                  maxLength={60}
                />
                <span className="text-xs text-slate-400">{section.fields.length} fields</span>
                <button onClick={() => moveSection(i, -1)} disabled={i === 0} className="rounded-lg p-1 text-slate-400 hover:text-brand-600 disabled:opacity-30" aria-label="Move section up"><ArrowUp className="size-4" /></button>
                <button onClick={() => moveSection(i, 1)} disabled={i === draft.sections.length - 1} className="rounded-lg p-1 text-slate-400 hover:text-brand-600 disabled:opacity-30" aria-label="Move section down"><ArrowDown className="size-4" /></button>
                <button onClick={() => removeSection(i)} disabled={draft.sections.length === 1} className="rounded-lg p-1 text-slate-400 hover:text-rose-600 disabled:opacity-30" aria-label="Delete section"><Trash2 className="size-4" /></button>
              </div>
              <div
                onDragOver={(e) => { e.preventDefault(); setOverTarget(`${i}:end`) }}
                onDragLeave={() => setOverTarget(null)}
                onDrop={(e) => onDrop(e, i)}
                className={clsx('grid min-h-16 gap-2 rounded-2xl border-2 border-dashed p-2 transition sm:grid-cols-2 2xl:grid-cols-3', overTarget?.startsWith(`${i}:`) ? 'border-brand-400 bg-brand-50/50 dark:bg-brand-500/5' : 'border-transparent')}
              >
                {section.fields.map((k) => chip(k, i))}
                {!section.fields.length && <p className="col-span-full py-3 text-center text-xs text-slate-400">Drop fields here</p>}
              </div>
            </div>
          ))}
          <Button variant="subtle" size="sm" icon={<Plus className="size-4" />} onClick={() => setDraft((d) => d && { ...d, sections: [...d.sections, { title: 'New section', fields: [] }] })}>Add section</Button>
        </div>

        <div className="space-y-4">
          <div
            onDragOver={(e) => { e.preventDefault(); setOverTarget('hidden:end') }}
            onDragLeave={() => setOverTarget(null)}
            onDrop={(e) => onDrop(e, 'hidden')}
            className={clsx('card p-4 transition xl:sticky xl:top-24', overTarget?.startsWith('hidden') && 'ring-2 ring-rose-300')}
          >
            <p className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><EyeOff className="size-4 text-slate-400" /> Hidden fields</p>
            <p className="mb-3 text-xs text-slate-500">Drag a field here to hide it from forms and detail panels. Data is kept. Required fields can't be hidden.</p>
            <div className="space-y-2">
              {draft.hidden.map((k) => chip(k, 'hidden'))}
              {!draft.hidden.length && <EmptyState className="py-6" icon={<Eye />} title="Nothing hidden" />}
            </div>
          </div>
        </div>
      </div>

      <div className="mt-8">
        <p className="label mb-3">Live preview — {ENTITIES.find((e) => e.value === entity)?.label} form</p>
        <div className="card pointer-events-none p-6 opacity-95 select-none" aria-hidden>
          <LayoutFields entity={entity} meta={previewMeta} values={{}} required={required} onChange={() => undefined} />
        </div>
      </div>

      <ConfirmDialog open={confirmReset} onClose={() => setConfirmReset(false)} danger={false} confirmLabel="Reset" loading={resetState.isLoading}
        title={`Reset the ${entity} layout?`} message="Sections and hidden fields go back to the default arrangement."
        onConfirm={async () => { await run(reset(entity), 'Layout reset'); setConfirmReset(false) }} />
    </>
  )
}
