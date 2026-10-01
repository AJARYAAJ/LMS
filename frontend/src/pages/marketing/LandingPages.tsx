import { useMemo, useState } from 'react'
import clsx from 'clsx'
import { ArrowDown, ArrowUp, Copy, ExternalLink, Eye, FileText, Plus, Trash2 } from 'lucide-react'
import { useAction, useToast } from '@/app/hooks'
import {
  resources, useDeleteLandingPageMutation, useLandingPagesQuery, useLazySuggestLandingSlugQuery, useSaveLandingPageMutation, useSettings,
} from '@/services/api'
import { Badge, Button, ConfirmDialog, EmptyState, Field, Input, Modal, PageLoader, Select, Textarea, Toggle } from '@/components/ui'
import { LandingView } from '@/components/marketing/LandingBlocks'
import { useAppSelector } from '@/app/hooks'
import { percent } from '@/lib/format'
import type { LandingBlock, LandingPageRow } from '@/types'

const BLOCK_LABELS: Record<LandingBlock['type'], string> = { hero: 'Hero', text: 'Text', features: 'Features', testimonial: 'Testimonial', form: 'Lead form', cta: 'Call to action' }

const NEW_BLOCK: Record<LandingBlock['type'], LandingBlock> = {
  hero: { type: 'hero', heading: 'A clear promise in one line', subheading: 'One or two sentences on who it’s for and why it matters.', button_label: 'Get started' },
  text: { type: 'text', heading: 'Heading', body: 'Tell the story.' },
  features: { type: 'features', heading: 'Why teams choose us', items: [{ title: 'Benefit one', body: 'Short explanation.' }, { title: 'Benefit two', body: 'Short explanation.' }, { title: 'Benefit three', body: 'Short explanation.' }] },
  testimonial: { type: 'testimonial', quote: 'This changed how our team works.', author: 'Customer name', role: 'Job title, Company' },
  form: { type: 'form', heading: 'Talk to us', body: 'Leave your details and we’ll be in touch within a day.' },
  cta: { type: 'cta', heading: 'Ready when you are', body: '', button_label: 'Book a demo' },
}

const STARTER: LandingBlock[] = [NEW_BLOCK.hero, NEW_BLOCK.features, NEW_BLOCK.testimonial, NEW_BLOCK.form]

type Draft = Omit<LandingPageRow, 'id' | 'views' | 'submissions' | 'url' | 'campaign' | 'form'> & { id?: number }

function BlockEditor({ block, onChange }: { block: LandingBlock; onChange: (b: LandingBlock) => void }) {
  const set = (k: keyof LandingBlock, v: unknown) => onChange({ ...block, [k]: v })
  const text = (k: 'heading' | 'subheading' | 'button_label' | 'image_url' | 'quote' | 'author' | 'role', label: string) =>
    <Field label={label}><Input value={(block[k] as string) ?? ''} onChange={(e) => set(k, e.target.value)} /></Field>
  return (
    <div className="space-y-3">
      {['hero', 'text', 'features', 'form', 'cta'].includes(block.type) && text('heading', 'Heading')}
      {block.type === 'hero' && <>{text('subheading', 'Subheading')}{text('button_label', 'Button')}{text('image_url', 'Image URL (https)')}</>}
      {['text', 'form', 'cta'].includes(block.type) && <Field label="Text"><Textarea rows={3} value={block.body ?? ''} onChange={(e) => set('body', e.target.value)} /></Field>}
      {block.type === 'cta' && text('button_label', 'Button')}
      {block.type === 'testimonial' && <><Field label="Quote"><Textarea rows={3} value={block.quote ?? ''} onChange={(e) => set('quote', e.target.value)} /></Field>{text('author', 'Name')}{text('role', 'Role')}</>}
      {block.type === 'features' && (
        <div className="space-y-2">
          {(block.items ?? []).map((it, i) => (
            <div key={i} className="flex gap-2">
              <Input value={it.title} aria-label={`Feature ${i + 1} title`} onChange={(e) => set('items', block.items!.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)))} />
              <Input value={it.body ?? ''} aria-label={`Feature ${i + 1} text`} onChange={(e) => set('items', block.items!.map((x, j) => (j === i ? { ...x, body: e.target.value } : x)))} />
              <button className="px-1 text-slate-400 hover:text-rose-600" aria-label="Remove feature" onClick={() => set('items', block.items!.filter((_, j) => j !== i))}><Trash2 className="size-4" /></button>
            </div>
          ))}
          {(block.items?.length ?? 0) < 6 && <Button size="xs" variant="subtle" icon={<Plus className="size-3.5" />} onClick={() => set('items', [...(block.items ?? []), { title: 'New benefit', body: '' }])}>Add feature</Button>}
        </div>
      )}
    </div>
  )
}

function Editor({ initial, onClose }: { initial: Draft; onClose: () => void }) {
  const run = useAction()
  const org = useAppSelector((s) => s.auth.user?.organization?.name ?? '')
  const { data: campaigns } = useSettings(resources.campaigns)
  const { data: forms } = useSettings(resources.webForms)
  const [save, state] = useSaveLandingPageMutation()
  const [suggest] = useLazySuggestLandingSlugQuery()
  const [page, setPage] = useState<Draft>(initial)
  const [open, setOpen] = useState(0)
  const set = <K extends keyof Draft>(k: K, v: Draft[K]) => setPage((p) => ({ ...p, [k]: v }))
  const move = (i: number, d: number) => { const b = [...page.blocks]; [b[i], b[i + d]] = [b[i + d], b[i]]; set('blocks', b); setOpen(i + d) }
  const form = forms?.find((f) => f.id === page.web_form_id)
  const preview = useMemo(() => ({
    name: page.name, blocks: page.blocks, accent_color: page.accent_color, organization: org,
    form: form ? { fields: form.fields, submit_label: form.submit_label, success_message: form.success_message, redirect_url: null } : null,
  }), [page, form, org])

  const submit = async (publish?: boolean) => {
    const body = { ...page, is_published: publish ?? page.is_published }
    if (await run(save(body), publish ? 'Page published' : 'Page saved')) onClose()
  }

  return (
    <Modal open onClose={onClose} size="xl" title={page.id ? `Edit ${page.name}` : 'New landing page'}
      footer={<>
        <Button variant="secondary" onClick={() => submit()} loading={state.isLoading} disabled={!page.name || page.slug.length < 3}>Save{page.is_published ? '' : ' draft'}</Button>
        {!page.is_published && <Button onClick={() => submit(true)} loading={state.isLoading} disabled={!page.name || page.slug.length < 3}>Publish</Button>}
      </>}>
      <div className="grid gap-6 lg:grid-cols-[360px_1fr]">
        <div className="space-y-4">
          <Field label="Name" required><Input value={page.name} onChange={(e) => set('name', e.target.value)}
            onBlur={async () => { if (!page.slug && page.name) { const r = await suggest(page.name).unwrap().catch(() => null); if (r) set('slug', r.slug) } }} /></Field>
          <Field label="Address" hint={`/p/${page.slug || '…'}`}><Input value={page.slug} onChange={(e) => set('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-'))} /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Campaign"><Select value={page.campaign_id ?? ''} onChange={(e) => set('campaign_id', e.target.value ? Number(e.target.value) : null)} placeholder="None">{campaigns?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</Select></Field>
            <Field label="Lead form"><Select value={page.web_form_id ?? ''} onChange={(e) => set('web_form_id', e.target.value ? Number(e.target.value) : null)} placeholder="None">{forms?.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}</Select></Field>
          </div>
          <div className="flex items-end gap-3">
            <Field label="Colour"><input type="color" value={page.accent_color} onChange={(e) => set('accent_color', e.target.value)} className="h-10 w-16 cursor-pointer rounded-lg border border-slate-200" aria-label="Accent colour" /></Field>
            {page.id && <Toggle checked={page.is_published} onChange={(v) => set('is_published', v)} label="Published" />}
          </div>
          <Field label="Search title"><Input value={page.seo_title ?? ''} onChange={(e) => set('seo_title', e.target.value)} placeholder={page.name} /></Field>
          <Field label="Search description"><Textarea rows={2} value={page.seo_description ?? ''} onChange={(e) => set('seo_description', e.target.value)} /></Field>

          <div>
            <p className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Sections</p>
            <div className="space-y-2">
              {page.blocks.map((b, i) => (
                <div key={i} className="rounded-2xl border border-slate-200/80 dark:border-white/10">
                  <div className="flex items-center gap-1 px-3 py-2">
                    <button className="flex-1 text-left text-sm font-medium" onClick={() => setOpen(open === i ? -1 : i)} aria-expanded={open === i}>{BLOCK_LABELS[b.type]}<span className="ml-2 text-xs font-normal text-slate-500">{b.heading ?? b.quote ?? ''}</span></button>
                    <button disabled={i === 0} onClick={() => move(i, -1)} className="p-1 text-slate-400 hover:text-brand-600 disabled:opacity-30" aria-label="Move up"><ArrowUp className="size-4" /></button>
                    <button disabled={i === page.blocks.length - 1} onClick={() => move(i, 1)} className="p-1 text-slate-400 hover:text-brand-600 disabled:opacity-30" aria-label="Move down"><ArrowDown className="size-4" /></button>
                    <button onClick={() => set('blocks', page.blocks.filter((_, j) => j !== i))} className="p-1 text-slate-400 hover:text-rose-600" aria-label="Remove section"><Trash2 className="size-4" /></button>
                  </div>
                  {open === i && <div className="border-t border-slate-200/80 p-3 dark:border-white/10"><BlockEditor block={b} onChange={(nb) => set('blocks', page.blocks.map((x, j) => (j === i ? nb : x)))} /></div>}
                </div>
              ))}
            </div>
            <Select className="mt-2" value="" aria-label="Add a section" onChange={(e) => { const t = e.target.value as LandingBlock['type']; if (t) { set('blocks', [...page.blocks, NEW_BLOCK[t]]); setOpen(page.blocks.length) } }}>
              <option value="">+ Add a section…</option>
              {Object.entries(BLOCK_LABELS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </Select>
          </div>
        </div>
        <div className="min-h-[480px] overflow-hidden rounded-2xl border border-slate-200 dark:border-white/10" aria-label="Preview">
          <div className="flex items-center gap-1.5 border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500"><Eye className="size-3.5" />Preview</div>
          <div className="max-h-[70vh] overflow-y-auto"><LandingView page={preview} slug={page.slug} preview /></div>
        </div>
      </div>
    </Modal>
  )
}

/** Landing pages: list with views, submissions and conversion rate. */
export function LandingPages() {
  const run = useAction()
  const toast = useToast()
  const { data, isLoading } = useLandingPagesQuery()
  const [remove] = useDeleteLandingPageMutation()
  const [editing, setEditing] = useState<Draft | null>(null)
  const [deleting, setDeleting] = useState<LandingPageRow | null>(null)
  const blank: Draft = { name: '', slug: '', campaign_id: null, web_form_id: null, is_published: false, blocks: STARTER, accent_color: '#7c3aed', seo_title: null, seo_description: null }

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-2xl text-sm text-slate-500">Publish a page for a campaign in minutes. People who fill in its form become leads tagged with the campaign and their UTM source.</p>
        <Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing(blank)}>New landing page</Button>
      </div>
      {isLoading ? <PageLoader /> : !data?.length ? <div className="card"><EmptyState icon={<FileText />} title="No landing pages yet" action={<Button size="sm" onClick={() => setEditing(blank)}>Create one</Button>} /></div> : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {data.map((p) => (
            <div key={p.id} className="card p-5">
              <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                  <button className="text-left font-semibold text-slate-900 hover:text-brand-600 dark:text-white" onClick={() => setEditing({ ...p })}>{p.name}</button>
                  <p className="truncate text-xs text-slate-500">/p/{p.slug}{p.campaign ? ` · ${p.campaign.name}` : ''}</p>
                </div>
                <Badge color={p.is_published ? '#059669' : '#64748b'} dot>{p.is_published ? 'Live' : 'Draft'}</Badge>
              </div>
              <div className="mt-4 grid grid-cols-3 gap-2 text-center">
                {[['Views', p.views], ['Leads', p.submissions], ['Conversion', percent(p.views ? (p.submissions / p.views) * 100 : 0)]].map(([l, v]) => (
                  <div key={l as string} className="rounded-2xl bg-slate-900/[0.03] py-2 dark:bg-white/[0.04]"><p className="font-display text-lg font-bold">{v}</p><p className="text-[11px] text-slate-500">{l}</p></div>
                ))}
              </div>
              <div className={clsx('mt-3 flex gap-1', !p.is_published && 'opacity-50')}>
                <Button size="xs" variant="subtle" icon={<Copy className="size-3.5" />} disabled={!p.is_published} onClick={() => { navigator.clipboard?.writeText(p.url); toast('info', 'Link copied') }}>Copy link</Button>
                <a href={`/p/${p.slug}`} target="_blank" rel="noreferrer" className={clsx('inline-flex items-center gap-1 rounded-lg px-2 text-xs text-slate-500 hover:text-brand-600', !p.is_published && 'pointer-events-none')}><ExternalLink className="size-3.5" />Open</a>
                <button className="ml-auto p-1 text-slate-400 hover:text-rose-600" onClick={() => setDeleting(p)} aria-label={`Delete ${p.name}`}><Trash2 className="size-4" /></button>
              </div>
            </div>
          ))}
        </div>
      )}
      {editing && <Editor initial={editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} title={`Delete ${deleting?.name}?`} message="The page goes offline. Leads it captured stay."
        onConfirm={async () => { if (deleting) await run({ unwrap: () => remove(deleting.id).unwrap() }, 'Page deleted'); setDeleting(null) }} />
    </div>
  )
}
