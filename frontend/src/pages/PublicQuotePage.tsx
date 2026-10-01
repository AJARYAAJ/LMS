import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { CheckCircle2, FileSignature, Printer, XCircle } from 'lucide-react'
import { usePublicQuoteQuery, useRespondQuoteMutation, errorMessage } from '@/services/api'
import { Button, Checkbox, EmptyState, Field, Input, PageLoader, Textarea } from '@/components/ui'
import { QuoteDocument } from '@/components/crm/Quotes'

/** Public page a customer opens from the quote email: read, then accept (typed signature) or decline. */
export function PublicQuotePage() {
  const token = useParams().token ?? ''
  const { data, isLoading, isError } = usePublicQuoteQuery(token)
  const [respond, state] = useRespondQuoteMutation()
  const [name, setName] = useState('')
  const [agree, setAgree] = useState(false)
  const [declining, setDeclining] = useState(false)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  const answer = async (accept: boolean) => {
    setError(null)
    try {
      await respond({ token, accept, name: accept ? name : undefined, agree: accept ? agree : undefined, reason: accept ? undefined : reason || undefined }).unwrap()
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  if (isLoading) return <div className="min-h-screen bg-[#f6f5fb]"><PageLoader /></div>
  if (isError || !data) return <div className="flex min-h-screen items-center justify-center bg-[#f6f5fb] p-6"><EmptyState title="Quote not found" description="The link may be wrong or the quote was withdrawn." /></div>

  const result = state.data ?? data
  const open = result.status === 'sent'

  return (
    <div className="min-h-screen bg-[#f6f5fb] px-4 py-10 text-slate-800 print:bg-white print:p-0">
      <div className="mx-auto max-w-3xl space-y-6">
        <div className="print-area"><QuoteDocument quote={result} organization={result.organization} preparedBy={result.prepared_by} customer={result.customer} /></div>

        {result.status === 'accepted' && (
          <div className="flex items-center gap-3 rounded-3xl bg-emerald-50 p-5 text-emerald-800 ring-1 ring-emerald-200 print:hidden"><CheckCircle2 className="size-6 shrink-0" /><div><p className="font-semibold">Accepted — thank you{result.signed_name ? `, ${result.signed_name}` : ''}!</p><p className="text-sm">{result.organization.name} has been notified and will be in touch about next steps.</p></div></div>
        )}
        {result.status === 'declined' && (
          <div className="flex items-center gap-3 rounded-3xl bg-slate-100 p-5 text-slate-700 ring-1 ring-slate-200 print:hidden"><XCircle className="size-6 shrink-0" /><p>You declined this quote. {result.organization.name} has been told and may follow up with an updated offer.</p></div>
        )}
        {result.status === 'expired' && (
          <div className="rounded-3xl bg-amber-50 p-5 text-amber-800 ring-1 ring-amber-200 print:hidden">This quote has expired. Ask {result.prepared_by?.name ?? result.organization.name} for an updated one.</div>
        )}

        {open && !declining && (
          <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200 print:hidden" aria-labelledby="sign-heading">
            <h2 id="sign-heading" className="flex items-center gap-2 font-semibold text-slate-900"><FileSignature className="size-5 text-brand-600" />Accept this quote</h2>
            <p className="mt-1 text-sm text-slate-500">Type your full name to sign electronically.</p>
            <div className="mt-4 grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
              <Field label="Full name"><Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Your name" autoComplete="name" /></Field>
              {name.trim().length > 1 && <p className="pb-2 font-[cursive] text-2xl text-slate-900" aria-hidden>{name}</p>}
            </div>
            <div className="mt-3"><Checkbox checked={agree} onChange={setAgree} label={`I agree to the terms of quote ${result.number} on behalf of my organization.`} /></div>
            {error && <p className="mt-3 text-sm text-rose-600" role="alert">{error}</p>}
            <div className="mt-5 flex flex-wrap gap-2">
              <Button onClick={() => answer(true)} loading={state.isLoading} disabled={name.trim().length < 2 || !agree} icon={<CheckCircle2 className="size-4" />}>Accept & sign</Button>
              <Button variant="ghost" onClick={() => setDeclining(true)}>Decline</Button>
              <Button variant="ghost" className="ml-auto" icon={<Printer className="size-4" />} onClick={() => window.print()}>Print / PDF</Button>
            </div>
          </section>
        )}

        {open && declining && (
          <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200 print:hidden">
            <h2 className="font-semibold text-slate-900">Decline this quote</h2>
            <Field label="Anything we should know? (optional)" className="mt-3"><Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Price, timing, scope…" /></Field>
            {error && <p className="mt-3 text-sm text-rose-600" role="alert">{error}</p>}
            <div className="mt-4 flex gap-2"><Button variant="danger" onClick={() => answer(false)} loading={state.isLoading}>Decline quote</Button><Button variant="ghost" onClick={() => setDeclining(false)}>Back</Button></div>
          </section>
        )}
        <p className="text-center text-xs text-slate-400 print:hidden">Sent with LeadFlow</p>
      </div>
    </div>
  )
}
