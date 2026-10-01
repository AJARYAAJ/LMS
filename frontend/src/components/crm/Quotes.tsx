import { useEffect, useMemo, useState } from 'react'
import { Copy, ExternalLink, FileText, Mail, Pencil, Plus, Printer, Send, Trash2, X } from 'lucide-react'
import { useAction, usePermissions, useToast } from '@/app/hooks'
import {
  useDeleteQuoteMutation, useDuplicateQuoteMutation, useMetaQuery, useQuotesQuery, useSaveQuoteMutation, useSendQuoteMutation,
} from '@/services/api'
import { Badge, Button, Card, ConfirmDialog, EmptyState, Field, Input, Modal, Select, Textarea } from '@/components/ui'
import { date, moneyExact as money } from '@/lib/format'
import type { Deal, PublicQuote, Quote, QuoteItem } from '@/types'

export const QUOTE_STATUS: Record<Quote['status'], { label: string; color: string }> = {
  draft: { label: 'Draft', color: '#64748b' },
  sent: { label: 'Sent', color: '#0ea5e9' },
  accepted: { label: 'Accepted', color: '#10b981' },
  declined: { label: 'Declined', color: '#f43f5e' },
  expired: { label: 'Expired', color: '#f59e0b' },
}

const lineTotal = (i: QuoteItem) => Math.round(i.quantity * i.unit_price * (1 - (i.discount_percent || 0) / 100) * 100) / 100

export function quoteTotals(items: QuoteItem[], discount: number, tax: number) {
  const subtotal = items.reduce((s, i) => s + lineTotal(i), 0)
  const afterDiscount = subtotal * (1 - discount / 100)
  return { subtotal, discount: subtotal - afterDiscount, tax: afterDiscount * (tax / 100), total: Math.round(afterDiscount * (1 + tax / 100) * 100) / 100 }
}

/** The quote as the customer sees it: header, line items, totals, signature. Print-friendly. */
export function QuoteDocument({ quote, organization, preparedBy, customer }: {
  quote: Pick<PublicQuote, 'number' | 'title' | 'currency' | 'discount_percent' | 'tax_percent' | 'valid_until' | 'notes' | 'signed_name' | 'responded_at' | 'created_at' | 'status'> & { items: QuoteItem[] }
  organization: { name: string; website?: string | null; phone?: string | null }
  preparedBy?: { name: string; email?: string } | null
  customer?: string | null
}) {
  const t = quoteTotals(quote.items, quote.discount_percent, quote.tax_percent)
  const m = (v: number) => money(v, quote.currency)
  return (
    <article className="quote-document rounded-3xl bg-white p-8 text-slate-800 shadow-sm ring-1 ring-slate-200 sm:p-10 print:rounded-none print:p-0 print:shadow-none print:ring-0">
      <header className="flex flex-wrap items-start justify-between gap-6 border-b border-slate-200 pb-6">
        <div>
          <p className="font-display text-2xl font-bold text-slate-900">{organization.name}</p>
          <p className="text-sm text-slate-500">{[organization.website, organization.phone].filter(Boolean).join(' · ')}</p>
        </div>
        <div className="text-right text-sm">
          <p className="font-display text-xl font-bold text-brand-700">Quote {quote.number}</p>
          <p className="text-slate-500">Issued {date(quote.created_at)}{quote.valid_until ? ` · valid until ${date(quote.valid_until)}` : ''}</p>
        </div>
      </header>
      <div className="mt-6 flex flex-wrap justify-between gap-4 text-sm">
        <div><p className="text-xs font-semibold tracking-wide text-slate-400 uppercase">For</p><p className="font-medium">{customer || '—'}</p></div>
        {preparedBy && <div className="text-right"><p className="text-xs font-semibold tracking-wide text-slate-400 uppercase">Prepared by</p><p className="font-medium">{preparedBy.name}</p>{preparedBy.email && <p className="text-slate-500">{preparedBy.email}</p>}</div>}
      </div>
      <h1 className="mt-6 text-lg font-semibold text-slate-900">{quote.title}</h1>
      <table className="mt-4 w-full text-sm">
        <thead><tr className="border-b border-slate-200 text-left text-xs tracking-wide text-slate-500 uppercase">
          <th className="py-2 font-semibold">Item</th><th className="py-2 text-right font-semibold">Qty</th><th className="py-2 text-right font-semibold">Price</th><th className="py-2 text-right font-semibold">Disc.</th><th className="py-2 text-right font-semibold">Total</th>
        </tr></thead>
        <tbody className="divide-y divide-slate-100">
          {quote.items.map((i, k) => (
            <tr key={k}>
              <td className="py-3"><p className="font-medium text-slate-900">{i.name}</p>{i.description && <p className="text-xs text-slate-500">{i.description}</p>}</td>
              <td className="py-3 text-right tabular-nums">{i.quantity}</td>
              <td className="py-3 text-right tabular-nums">{m(i.unit_price)}</td>
              <td className="py-3 text-right tabular-nums">{i.discount_percent ? `${i.discount_percent}%` : '—'}</td>
              <td className="py-3 text-right font-medium tabular-nums">{m(lineTotal(i))}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <dl className="mt-4 ml-auto w-full max-w-xs space-y-1.5 text-sm">
        <div className="flex justify-between"><dt className="text-slate-500">Subtotal</dt><dd className="tabular-nums">{m(t.subtotal)}</dd></div>
        {quote.discount_percent > 0 && <div className="flex justify-between"><dt className="text-slate-500">Discount ({quote.discount_percent}%)</dt><dd className="tabular-nums">−{m(t.discount)}</dd></div>}
        {quote.tax_percent > 0 && <div className="flex justify-between"><dt className="text-slate-500">Tax ({quote.tax_percent}%)</dt><dd className="tabular-nums">{m(t.tax)}</dd></div>}
        <div className="flex justify-between border-t border-slate-200 pt-2 text-base font-bold text-slate-900"><dt>Total</dt><dd className="tabular-nums">{m(t.total)}</dd></div>
      </dl>
      {quote.notes && <div className="mt-6 rounded-2xl bg-slate-50 p-4 text-sm whitespace-pre-line text-slate-600">{quote.notes}</div>}
      {quote.status === 'accepted' && quote.signed_name && (
        <div className="mt-8 border-t border-slate-200 pt-4">
          <p className="text-xs font-semibold tracking-wide text-slate-400 uppercase">Accepted and signed electronically</p>
          <p className="mt-1 font-[cursive] text-2xl text-slate-900">{quote.signed_name}</p>
          <p className="text-xs text-slate-500">{quote.responded_at ? new Date(quote.responded_at).toLocaleString() : ''}</p>
        </div>
      )}
    </article>
  )
}

function QuoteBuilder({ deal, quote, onClose }: { deal: Deal; quote: Partial<Quote> | null; onClose: () => void }) {
  const run = useAction()
  const { data: meta } = useMetaQuery()
  const [save, state] = useSaveQuoteMutation()
  const [title, setTitle] = useState('')
  const [validUntil, setValidUntil] = useState('')
  const [discount, setDiscount] = useState(0)
  const [tax, setTax] = useState(0)
  const [notes, setNotes] = useState('')
  const [items, setItems] = useState<QuoteItem[]>([])

  useEffect(() => {
    if (!quote) return
    setTitle(quote.title ?? `${deal.name} — proposal`)
    setValidUntil((quote.valid_until ?? new Date(Date.now() + 30 * 86_400_000).toISOString()).slice(0, 10))
    setDiscount(quote.discount_percent ?? 0)
    setTax(quote.tax_percent ?? 0)
    setNotes(quote.notes ?? '')
    setItems(quote.items?.map((i) => ({ ...i })) ?? [])
  }, [quote, deal.name])

  const products = meta?.products ?? []
  const totals = useMemo(() => quoteTotals(items, discount, tax), [items, discount, tax])
  const setItem = (k: number, patch: Partial<QuoteItem>) => setItems((list) => list.map((i, j) => (j === k ? { ...i, ...patch } : i)))
  const addProduct = (id: string) => {
    const p = products.find((x) => String(x.id) === id)
    setItems((list) => [...list, p
      ? { product_id: p.id, name: p.name, description: p.description, quantity: 1, unit_price: Number(p.unit_price), discount_percent: 0 }
      : { name: '', quantity: 1, unit_price: 0, discount_percent: 0 }])
  }

  const submit = async () => {
    const body = { title, valid_until: validUntil || null, discount_percent: discount, tax_percent: tax, notes: notes || null, items: items.map(({ product_id, name, description, quantity, unit_price, discount_percent }) => ({ product_id: product_id ?? null, name, description: description ?? null, quantity, unit_price, discount_percent })) }
    if (await run(save({ id: quote?.id, dealId: deal.id, body }), quote?.id ? 'Quote updated' : 'Quote created')) onClose()
  }

  return (
    <Modal open={!!quote} onClose={onClose} size="xl" title={quote?.id ? `Edit quote ${quote.number}` : 'New quote'}
      footer={<>
        <span className="mr-auto text-sm text-slate-500">Total <span className="font-display text-lg font-bold text-slate-900 dark:text-white">{money(totals.total, deal.currency)}</span></span>
        <Button variant="secondary" onClick={onClose}>Cancel</Button>
        <Button onClick={submit} loading={state.isLoading} disabled={!items.length || items.some((i) => !i.name.trim() || i.quantity <= 0)}>Save quote</Button>
      </>}>
      <div className="space-y-5">
        <div className="grid gap-4 sm:grid-cols-[1fr_180px]">
          <Field label="Title"><Input value={title} onChange={(e) => setTitle(e.target.value)} /></Field>
          <Field label="Valid until"><Input type="date" value={validUntil} onChange={(e) => setValidUntil(e.target.value)} /></Field>
        </div>
        <div>
          <div className="mb-2 flex items-center justify-between">
            <p className="text-sm font-medium text-slate-700 dark:text-slate-200">Line items</p>
            <Select className="w-auto" value="" onChange={(e) => addProduct(e.target.value)} aria-label="Add line item">
              <option value="">+ Add line…</option>
              <option value="custom">Custom item</option>
              {products.map((p) => <option key={p.id} value={p.id}>{p.name} · {money(Number(p.unit_price), deal.currency)}{p.billing !== 'one_time' ? ` / ${p.billing === 'monthly' ? 'mo' : 'yr'}` : ''}</option>)}
            </Select>
          </div>
          {!items.length ? <p className="rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-white/10">Add products from the catalog or a custom item.</p> : (
            <div className="space-y-2">
              <div className="hidden grid-cols-[1fr_80px_110px_80px_110px_32px] gap-2 px-1 text-[11px] font-semibold tracking-wide text-slate-500 uppercase sm:grid">
                <span>Item</span><span>Qty</span><span>Unit price</span><span>Disc. %</span><span className="text-right">Total</span><span />
              </div>
              {items.map((i, k) => (
                <div key={k} className="grid grid-cols-2 items-center gap-2 rounded-2xl bg-slate-900/[0.03] p-2 sm:grid-cols-[1fr_80px_110px_80px_110px_32px] sm:bg-transparent sm:p-0 dark:bg-white/[0.03] sm:dark:bg-transparent">
                  <Input className="col-span-2 sm:col-span-1" value={i.name} onChange={(e) => setItem(k, { name: e.target.value })} placeholder="Item name" aria-label={`Item ${k + 1} name`} />
                  <Input type="number" min={0} step="any" value={i.quantity} onChange={(e) => setItem(k, { quantity: Number(e.target.value) })} aria-label={`Item ${k + 1} quantity`} />
                  <Input type="number" min={0} step="any" value={i.unit_price} onChange={(e) => setItem(k, { unit_price: Number(e.target.value) })} aria-label={`Item ${k + 1} unit price`} />
                  <Input type="number" min={0} max={100} value={i.discount_percent} onChange={(e) => setItem(k, { discount_percent: Number(e.target.value) })} aria-label={`Item ${k + 1} discount`} />
                  <span className="text-right text-sm font-medium tabular-nums">{money(lineTotal(i), deal.currency)}</span>
                  <button onClick={() => setItems((list) => list.filter((_, j) => j !== k))} className="justify-self-end rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label={`Remove item ${k + 1}`}><X className="size-4" /></button>
                </div>
              ))}
            </div>
          )}
        </div>
        <div className="grid gap-4 sm:grid-cols-[1fr_140px_140px]">
          <Field label="Notes & terms"><Textarea rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Payment terms, delivery, what's included…" /></Field>
          <Field label="Quote discount %"><Input type="number" min={0} max={100} value={discount} onChange={(e) => setDiscount(Number(e.target.value))} /></Field>
          <Field label="Tax %"><Input type="number" min={0} max={100} value={tax} onChange={(e) => setTax(Number(e.target.value))} /></Field>
        </div>
        <dl className="ml-auto w-full max-w-xs space-y-1 text-sm">
          <div className="flex justify-between"><dt className="text-slate-500">Subtotal</dt><dd>{money(totals.subtotal, deal.currency)}</dd></div>
          {discount > 0 && <div className="flex justify-between"><dt className="text-slate-500">Discount</dt><dd>−{money(totals.discount, deal.currency)}</dd></div>}
          {tax > 0 && <div className="flex justify-between"><dt className="text-slate-500">Tax</dt><dd>{money(totals.tax, deal.currency)}</dd></div>}
        </dl>
      </div>
    </Modal>
  )
}

/** Quotes on a deal: list with status, build/edit, send, preview/print, duplicate, delete. */
export function QuotesPanel({ deal, organizationName }: { deal: Deal; organizationName: string }) {
  const run = useAction()
  const toast = useToast()
  const { write } = usePermissions()
  const { data: quotes } = useQuotesQuery(deal.id)
  const [send, sendState] = useSendQuoteMutation()
  const [duplicate] = useDuplicateQuoteMutation()
  const [remove] = useDeleteQuoteMutation()
  const [editing, setEditing] = useState<Partial<Quote> | null>(null)
  const [preview, setPreview] = useState<Quote | null>(null)
  const [sending, setSending] = useState<Quote | null>(null)
  const [to, setTo] = useState('')
  const [deleting, setDeleting] = useState<Quote | null>(null)

  const customer = deal.contact ? [deal.contact.first_name, deal.contact.last_name].filter(Boolean).join(' ') : null
  const publicUrl = (q: Quote) => `${window.location.origin}/q/${(q.public_url ?? '').split('/q/')[1] ?? ''}`

  return (
    <Card title={<span className="flex items-center gap-2"><FileText className="size-4 text-brand-500" />Quotes</span>}
      action={write && <Button size="xs" variant="subtle" icon={<Plus className="size-3.5" />} onClick={() => setEditing({})}>New quote</Button>}>
      {!quotes?.length ? <EmptyState icon={<FileText />} title="No quotes yet" description="Build one from your product catalog and send it for a one-click signature." className="py-6" /> : (
        <ul className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
          {quotes.map((q) => (
            <li key={q.id} className="group flex flex-wrap items-center gap-3 py-3">
              <button onClick={() => setPreview(q)} className="min-w-0 flex-1 text-left">
                <p className="truncate text-sm font-medium text-slate-900 dark:text-white">{q.number} · {q.title}</p>
                <p className="text-xs text-slate-500">
                  {money(q.total, q.currency)}{q.valid_until ? ` · valid until ${date(q.valid_until)}` : ''}
                  {q.viewed_at && q.status === 'sent' ? ' · opened by customer' : ''}{q.signed_name ? ` · signed by ${q.signed_name}` : ''}{q.decline_reason ? ` · “${q.decline_reason}”` : ''}
                </p>
              </button>
              <Badge color={QUOTE_STATUS[q.status].color} dot>{QUOTE_STATUS[q.status].label}</Badge>
              {write && (
                <div className="flex opacity-60 transition group-hover:opacity-100">
                  {['draft', 'sent'].includes(q.status) && <button onClick={() => setEditing(q)} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label={`Edit ${q.number}`}><Pencil className="size-4" /></button>}
                  {['draft', 'sent'].includes(q.status) && <button onClick={() => { setSending(q); setTo('') }} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label={`Send ${q.number}`}><Send className="size-4" /></button>}
                  <button onClick={() => run(duplicate(q.id), 'Quote duplicated')} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label={`Duplicate ${q.number}`}><Copy className="size-4" /></button>
                  {q.status !== 'accepted' && <button onClick={() => setDeleting(q)} className="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label={`Delete ${q.number}`}><Trash2 className="size-4" /></button>}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      <QuoteBuilder deal={deal} quote={editing} onClose={() => setEditing(null)} />

      <Modal open={!!preview} onClose={() => setPreview(null)} size="xl" title={preview ? `Quote ${preview.number}` : ''}
        footer={preview && <>
          {preview.status !== 'draft' && <Button variant="ghost" icon={<ExternalLink className="size-4" />} onClick={() => window.open(publicUrl(preview), '_blank')}>Customer view</Button>}
          {preview.status !== 'draft' && <Button variant="ghost" icon={<Copy className="size-4" />} onClick={() => { navigator.clipboard?.writeText(publicUrl(preview)); toast('info', 'Link copied') }}>Copy link</Button>}
          <Button variant="secondary" icon={<Printer className="size-4" />} onClick={() => window.print()}>Print / PDF</Button>
        </>}>
        {preview && <div className="print-area"><QuoteDocument quote={preview} organization={{ name: organizationName }} preparedBy={deal.owner ? { name: deal.owner.name } : null} customer={customer} /></div>}
      </Modal>

      <Modal open={!!sending} onClose={() => setSending(null)} size="sm" title={`Send ${sending?.number}`} description="The customer gets an email with a secure link to view and accept the quote."
        footer={<><Button variant="secondary" onClick={() => setSending(null)}>Cancel</Button><Button icon={<Mail className="size-4" />} loading={sendState.isLoading} onClick={async () => {
          if (!sending) return
          const r = await run(send({ id: sending.id, to: to || undefined }))
          if (r) { toast('success', r.message); setSending(null) }
        }}>Send</Button></>}>
        <Field label="Email" hint={deal.contact ? 'Leave blank to use the deal contact’s email.' : 'This deal has no contact; enter the customer’s email.'}>
          <Input type="email" value={to} onChange={(e) => setTo(e.target.value)} placeholder="buyer@company.com" />
        </Field>
      </Modal>

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} title={`Delete ${deleting?.number}?`} message="The customer link stops working."
        onConfirm={async () => { if (deleting) await run(remove(deleting.id), 'Quote deleted'); setDeleting(null) }} />
    </Card>
  )
}
