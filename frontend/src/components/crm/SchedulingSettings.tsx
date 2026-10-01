import { useEffect, useState } from 'react'
import clsx from 'clsx'
import { CalendarDays, Copy, ExternalLink, RefreshCw } from 'lucide-react'
import { useAction, useToast } from '@/app/hooks'
import { useBookingPageQuery, useCalendarFeedQuery, useLazySuggestBookingSlugQuery, useResetCalendarFeedMutation, useSaveBookingPageMutation } from '@/services/api'
import { Button, Card, Field, Input, PageLoader, Select, Textarea, Toggle } from '@/components/ui'
import type { BookingPageSettings } from '@/types'

const WEEKDAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']] as const

/** "Book a meeting with me" page settings. */
export function BookingPageCard() {
  const run = useAction()
  const toast = useToast()
  const { data, isLoading } = useBookingPageQuery()
  const [suggest] = useLazySuggestBookingSlugQuery()
  const [save, state] = useSaveBookingPageMutation()
  const [form, setForm] = useState<Omit<BookingPageSettings, 'id' | 'url'> | null>(null)

  useEffect(() => {
    if (isLoading) return
    if (data) { setForm(data); return }
    suggest().unwrap().then(({ slug }) => setForm({
      slug, title: 'Intro call', description: '', duration_minutes: 30, buffer_minutes: 10, notice_hours: 4, days_ahead: 14,
      weekdays: [1, 2, 3, 4, 5], start_time: '09:00', end_time: '17:00', timezone: Intl.DateTimeFormat().resolvedOptions().timeZone, is_active: true,
    })).catch(() => undefined)
  }, [data, isLoading, suggest])

  if (!form) return <Card title="Booking page"><PageLoader /></Card>
  const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) => setForm((f) => (f ? { ...f, [k]: v } : f))
  const url = data?.url

  return (
    <Card title={<span className="flex items-center gap-2"><CalendarDays className="size-4 text-brand-500" />Booking page</span>} subtitle="Share a link; people pick a free time and it lands on your calendar as a meeting with the lead.">
      {url && (
        <div className="mb-4 flex items-center gap-2 rounded-2xl bg-slate-900/[0.03] p-2 pl-3 text-sm dark:bg-white/[0.04]">
          <code className="min-w-0 flex-1 truncate font-mono text-xs">{url}</code>
          <button onClick={() => { navigator.clipboard?.writeText(url); toast('info', 'Link copied') }} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label="Copy booking link"><Copy className="size-4" /></button>
          <a href={`/book/${data?.slug}`} target="_blank" rel="noreferrer" className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label="Open booking page"><ExternalLink className="size-4" /></a>
        </div>
      )}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Link name" hint="Lowercase letters, numbers and dashes."><Input value={form.slug} onChange={(e) => set('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-'))} /></Field>
        <Field label="Meeting title"><Input value={form.title} onChange={(e) => set('title', e.target.value)} /></Field>
        <Field label="Length"><Select value={form.duration_minutes} onChange={(e) => set('duration_minutes', Number(e.target.value))}>{[15, 20, 30, 45, 60, 90].map((m) => <option key={m} value={m}>{m} minutes</option>)}</Select></Field>
        <Field label="Time zone"><Select value={form.timezone} onChange={(e) => set('timezone', e.target.value)}>{(Intl.supportedValuesOf?.('timeZone') ?? ['UTC']).map((t) => <option key={t}>{t}</option>)}</Select></Field>
        <Field label="From"><Input type="time" value={form.start_time} onChange={(e) => set('start_time', e.target.value)} /></Field>
        <Field label="Until"><Input type="time" value={form.end_time} onChange={(e) => set('end_time', e.target.value)} /></Field>
        <div className="sm:col-span-2">
          <p className="mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">Days</p>
          <div className="flex flex-wrap gap-1.5">
            {WEEKDAYS.map(([n, l]) => {
              const on = form.weekdays.includes(n)
              return <button key={n} type="button" aria-pressed={on} onClick={() => set('weekdays', on ? form.weekdays.filter((d) => d !== n) : [...form.weekdays, n].sort())}
                className={clsx('chip transition', on && 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>{l}</button>
            })}
          </div>
        </div>
        <Field label="Gap between meetings (min)"><Input type="number" min={0} max={60} value={form.buffer_minutes} onChange={(e) => set('buffer_minutes', Number(e.target.value))} /></Field>
        <Field label="Minimum notice (hours)"><Input type="number" min={0} max={168} value={form.notice_hours} onChange={(e) => set('notice_hours', Number(e.target.value))} /></Field>
        <Field label="Description" className="sm:col-span-2"><Textarea rows={2} value={form.description ?? ''} onChange={(e) => set('description', e.target.value)} placeholder="What the meeting is about" /></Field>
        <div className="flex items-center justify-between sm:col-span-2">
          <Toggle checked={form.is_active} onChange={(v) => set('is_active', v)} label="Accept bookings" />
          <Button loading={state.isLoading} disabled={!form.weekdays.length || form.slug.length < 3} onClick={() => run(save(form), 'Booking page saved')}>Save</Button>
        </div>
      </div>
    </Card>
  )
}

/** Subscribe-able calendar feed of the user's tasks and meetings. */
export function CalendarFeedCard() {
  const run = useAction()
  const toast = useToast()
  const { data } = useCalendarFeedQuery()
  const [reset, state] = useResetCalendarFeedMutation()
  return (
    <Card title="Calendar feed" subtitle="Subscribe in Google Calendar, Outlook or Apple Calendar to see your LeadFlow tasks and meetings there.">
      {data ? (
        <div className="space-y-3">
          <div className="flex items-center gap-2 rounded-2xl bg-slate-900/[0.03] p-2 pl-3 dark:bg-white/[0.04]">
            <code className="min-w-0 flex-1 truncate font-mono text-xs">{data.url}</code>
            <button onClick={() => { navigator.clipboard?.writeText(data.url); toast('info', 'Feed link copied') }} className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" aria-label="Copy calendar feed link"><Copy className="size-4" /></button>
          </div>
          <p className="text-xs text-slate-500">Keep this link private — anyone with it can see your schedule.</p>
          <Button size="sm" variant="ghost" icon={<RefreshCw className="size-4" />} loading={state.isLoading} onClick={() => run(reset(), 'New link created; the old one stopped working')}>Create a new link</Button>
        </div>
      ) : <PageLoader />}
    </Card>
  )
}
