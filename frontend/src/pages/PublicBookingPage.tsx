import { useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import clsx from 'clsx'
import { CalendarCheck, CalendarDays, CheckCircle2, Clock, Globe } from 'lucide-react'
import { errorMessage, useBookMeetingMutation, usePublicBookingQuery } from '@/services/api'
import { Avatar, Button, EmptyState, Field, Input, PageLoader, Textarea } from '@/components/ui'

const browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone

/** Public "book a meeting" page: pick a day and time, leave your details, done. */
export function PublicBookingPage() {
  const slug = useParams().slug ?? ''
  const { data, isLoading, isError, refetch } = usePublicBookingQuery(slug)
  const [book, state] = useBookMeetingMutation()
  const [day, setDay] = useState<string | null>(null)
  const [slot, setSlot] = useState<string | null>(null)
  const [form, setForm] = useState({ name: '', email: '', phone: '', company: '', notes: '' })
  const [error, setError] = useState<string | null>(null)

  const days = useMemo(() => Object.keys(data?.slots ?? {}), [data])
  const activeDay = day ?? days[0] ?? null
  const time = (iso: string) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  const dayLabel = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })

  if (isLoading) return <div className="min-h-screen bg-[#f6f5fb]"><PageLoader /></div>
  if (isError || !data) return <div className="flex min-h-screen items-center justify-center bg-[#f6f5fb] p-6"><EmptyState icon={<CalendarDays />} title="Booking page not found" description="The link may be wrong or bookings are paused." /></div>

  if (state.data) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-[#f6f5fb] p-6">
        <div className="w-full max-w-md rounded-3xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
          <CheckCircle2 className="mx-auto size-12 text-emerald-500" />
          <h1 className="mt-4 text-xl font-bold text-slate-900">You're booked!</h1>
          <p className="mt-2 text-slate-600">{state.data.title} with {state.data.host}</p>
          <p className="mt-1 font-semibold text-slate-900">{new Date(state.data.start).toLocaleString([], { weekday: 'long', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</p>
          <p className="mt-4 text-sm text-slate-500">{data.organization} will be in touch with the details.</p>
        </div>
      </div>
    )
  }

  const submit = async () => {
    if (!slot) return
    setError(null)
    try {
      await book({ slug, ...form, start: slot }).unwrap()
    } catch (e) {
      setError(errorMessage(e))
      setSlot(null)
      refetch()
    }
  }

  return (
    <div className="min-h-screen bg-[#f6f5fb] px-4 py-10 text-slate-800">
      <div className="mx-auto grid max-w-4xl overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-slate-200 md:grid-cols-[280px_1fr]">
        <aside className="border-b border-slate-200 p-6 md:border-r md:border-b-0">
          <Avatar name={data.host.name} color={data.host.avatar_color} size="lg" />
          <p className="mt-3 text-sm text-slate-500">{data.host.name}{data.host.job_title ? ` · ${data.host.job_title}` : ''}</p>
          <h1 className="mt-1 text-xl font-bold text-slate-900">{data.title}</h1>
          <p className="mt-1 text-sm text-slate-500">{data.organization}</p>
          <ul className="mt-4 space-y-2 text-sm text-slate-600">
            <li className="flex items-center gap-2"><Clock className="size-4 text-brand-600" />{data.duration_minutes} minutes</li>
            <li className="flex items-center gap-2"><Globe className="size-4 text-brand-600" />Times shown in {browserTz.replace('_', ' ')}</li>
          </ul>
          {data.description && <p className="mt-4 text-sm whitespace-pre-line text-slate-600">{data.description}</p>}
        </aside>
        <main className="p-6">
          {!days.length ? <EmptyState icon={<CalendarDays />} title="No free times right now" description="Please check back later." /> : !slot ? (
            <>
              <h2 className="mb-3 flex items-center gap-2 font-semibold text-slate-900"><CalendarCheck className="size-5 text-brand-600" />Pick a time</h2>
              {error && <p className="mb-3 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{error}</p>}
              <div className="mb-4 flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Days">
                {days.map((d) => (
                  <button key={d} role="tab" aria-selected={d === activeDay} onClick={() => setDay(d)}
                    className={clsx('shrink-0 rounded-2xl border px-3 py-2 text-sm transition', d === activeDay ? 'border-brand-500 bg-brand-50 font-semibold text-brand-700' : 'border-slate-200 text-slate-600 hover:border-brand-300')}>
                    {dayLabel(d)}<span className="block text-[11px] font-normal text-slate-400">{data.slots[d].length} times</span>
                  </button>
                ))}
              </div>
              <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                {(activeDay ? data.slots[activeDay] : []).map((s) => (
                  <button key={s} onClick={() => setSlot(s)} className="rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-brand-700 transition hover:border-brand-500 hover:bg-brand-50">{time(s)}</button>
                ))}
              </div>
            </>
          ) : (
            <form onSubmit={(e) => { e.preventDefault(); submit() }} className="space-y-4">
              <div className="flex items-center justify-between rounded-2xl bg-brand-50 px-4 py-3 text-sm">
                <span className="font-semibold text-brand-800">{new Date(slot).toLocaleString([], { weekday: 'long', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</span>
                <button type="button" onClick={() => setSlot(null)} className="text-brand-700 underline">Change</button>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Your name" required><Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} autoComplete="name" /></Field>
                <Field label="Work email" required><Input type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} autoComplete="email" /></Field>
                <Field label="Phone"><Input value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} autoComplete="tel" /></Field>
                <Field label="Company"><Input value={form.company} onChange={(e) => setForm((f) => ({ ...f, company: e.target.value }))} autoComplete="organization" /></Field>
              </div>
              <Field label="What would you like to cover?"><Textarea rows={3} value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} /></Field>
              <Button type="submit" loading={state.isLoading} disabled={!form.name.trim() || !form.email.includes('@')} icon={<CalendarCheck className="size-4" />}>Confirm booking</Button>
            </form>
          )}
        </main>
      </div>
      <p className="mt-6 text-center text-xs text-slate-400">Scheduling by LeadFlow</p>
    </div>
  )
}
