import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import clsx from 'clsx'
import { Inbox, Mail, MessageCircle, MessageSquare, Search, Send } from 'lucide-react'
import { useAction, usePermissions } from '@/app/hooks'
import { useConversationQuery, useInboxQuery, useSendEmailMutation, useSendMessageMutation } from '@/services/api'
import { Avatar, Badge, Button, EmptyState, Input, PageHeader, PageLoader, Segmented, Select, Textarea } from '@/components/ui'
import { ago, dateTime } from '@/lib/format'
import type { InboxThread } from '@/types'

const CHANNEL = {
  sms: { label: 'SMS', icon: MessageSquare, color: '#0d9488' },
  whatsapp: { label: 'WhatsApp', icon: MessageCircle, color: '#16a34a' },
  email: { label: 'Email', icon: Mail, color: '#0284c7' },
} as const

const fullName = (l: { first_name: string; last_name: string | null }) => [l.first_name, l.last_name].filter(Boolean).join(' ')

function ThreadRow({ t, active, onOpen }: { t: InboxThread; active: boolean; onOpen: () => void }) {
  const ch = t.last ? CHANNEL[t.last.type] : null
  return (
    <button onClick={onOpen} className={clsx('flex w-full gap-3 rounded-2xl px-3 py-3 text-left transition', active ? 'bg-brand-500/10' : 'hover:bg-slate-900/[0.03] dark:hover:bg-white/[0.04]')}>
      <Avatar name={fullName(t.lead)} size="sm" />
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-2">
          <span className={clsx('truncate text-sm', t.unread ? 'font-bold text-slate-900 dark:text-white' : 'font-medium text-slate-700 dark:text-slate-200')}>{fullName(t.lead)}</span>
          <span className="ml-auto shrink-0 text-[11px] text-slate-400">{t.last ? ago(t.last.occurred_at) : ''}</span>
        </span>
        <span className="flex items-center gap-1.5 text-xs text-slate-500">
          {ch && <ch.icon className="size-3 shrink-0" style={{ color: ch.color }} aria-label={ch.label} />}
          <span className="truncate">{t.last?.direction === 'outbound' ? 'You: ' : ''}{t.last?.description || t.last?.title}</span>
          {t.unread > 0 && <span className="ml-auto flex min-w-5 shrink-0 items-center justify-center rounded-full bg-brand-600 px-1.5 text-[10px] font-bold text-white">{t.unread}</span>}
        </span>
      </span>
    </button>
  )
}

function Conversation({ leadId }: { leadId: number }) {
  const run = useAction()
  const { write } = usePermissions()
  const { data, isLoading } = useConversationQuery(leadId, { pollingInterval: 20_000, skipPollingIfUnfocused: true })
  const [sendMessage, msgState] = useSendMessageMutation()
  const [sendEmail, emailState] = useSendEmailMutation()
  const [channel, setChannel] = useState<'sms' | 'whatsapp' | 'email'>('whatsapp')
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const end = useRef<HTMLDivElement>(null)

  // Reply on the channel the lead last used (or one we can actually reach).
  useEffect(() => {
    if (!data) return
    const lastIn = [...data.messages].reverse().find((m) => m.direction === 'inbound')
    setChannel((lastIn?.type ?? (data.lead.phone ? 'whatsapp' : 'email')) as typeof channel)
  }, [data?.lead.id]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { end.current?.scrollIntoView({ block: 'end' }) }, [data?.messages.length])

  if (isLoading || !data) return <PageLoader />
  const lead = data.lead
  const canReach = channel === 'email' ? !!lead.email : !!lead.phone

  const send = async () => {
    const r = channel === 'email'
      ? await run(sendEmail({ id: lead.id, subject: subject || `Re: ${lead.company ?? fullName(lead)}`, body }), 'Email sent')
      : await run(sendMessage({ id: lead.id, channel, body }), `${CHANNEL[channel].label} sent`)
    if (r) { setBody(''); setSubject('') }
  }

  return (
    <div className="flex h-full flex-col">
      <div className="flex flex-wrap items-center gap-3 border-b border-slate-200/60 p-4 dark:border-white/[0.06]">
        <Avatar name={fullName(lead)} />
        <div className="min-w-0 flex-1">
          <Link to={`/leads/${lead.id}`} className="font-semibold text-slate-900 hover:text-brand-600 dark:text-white">{fullName(lead)}</Link>
          <p className="truncate text-xs text-slate-500">{[lead.company, lead.phone, lead.email].filter(Boolean).join(' · ')}</p>
        </div>
        {lead.status && <Badge color={lead.status.color} dot>{lead.status.name}</Badge>}
        {lead.owner && <span className="text-xs text-slate-500">Owner: {lead.owner.name}</span>}
      </div>
      <div className="flex-1 space-y-3 overflow-y-auto p-4">
        {data.messages.map((m) => {
          const ch = CHANNEL[m.type]
          const mine = m.direction !== 'inbound'
          return (
            <div key={m.id} className={clsx('flex', mine ? 'justify-end' : 'justify-start')}>
              <div className={clsx('max-w-[78%] rounded-2xl px-3.5 py-2.5 text-sm', mine ? 'rounded-br-md bg-brand-600 text-white' : 'rounded-bl-md bg-slate-100 text-slate-800 dark:bg-white/[0.07] dark:text-slate-100')}>
                {m.type === 'email' && <p className={clsx('mb-0.5 text-xs font-semibold', mine ? 'text-white/90' : 'text-slate-600 dark:text-slate-300')}>{m.title}</p>}
                <p className="whitespace-pre-line">{m.description || m.title}</p>
                <p className={clsx('mt-1 flex items-center gap-1 text-[10px]', mine ? 'text-white/70' : 'text-slate-400')}>
                  <ch.icon className="size-3" aria-hidden />{ch.label} · {dateTime(m.occurred_at)}{mine && m.user ? ` · ${m.user.name}` : ''}
                </p>
              </div>
            </div>
          )
        })}
        <div ref={end} />
      </div>
      {write && (
        <div className="border-t border-slate-200/60 p-3 dark:border-white/[0.06]">
          <div className="mb-2 flex items-center gap-2">
            <Segmented value={channel} onChange={setChannel} options={(Object.keys(CHANNEL) as (keyof typeof CHANNEL)[]).map((k) => ({ value: k, label: CHANNEL[k].label }))} />
            {!canReach && <span className="text-xs text-amber-600">No {channel === 'email' ? 'email address' : 'phone number'} on this lead.</span>}
          </div>
          {channel === 'email' && <Input className="mb-2" value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="Subject" aria-label="Email subject" />}
          <div className="flex items-end gap-2">
            <Textarea rows={2} value={body} onChange={(e) => setBody(e.target.value)} placeholder={`Reply by ${CHANNEL[channel].label}… ({first_name} works)`} aria-label="Reply"
              onKeyDown={(e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey) && body.trim() && canReach) send() }} />
            <Button icon={<Send className="size-4" />} onClick={send} disabled={!body.trim() || !canReach} loading={msgState.isLoading || emailState.isLoading}>Send</Button>
          </div>
        </div>
      )}
    </div>
  )
}

export function InboxPage() {
  const [params, setParams] = useSearchParams()
  const [filter, setFilter] = useState<'all' | 'unread' | 'awaiting' | 'mine'>('all')
  const [channel, setChannel] = useState('')
  const [search, setSearch] = useState('')
  const { data, isLoading } = useInboxQuery({ filter, channel, search }, { pollingInterval: 20_000, skipPollingIfUnfocused: true })
  const open = Number(params.get('lead')) || data?.data[0]?.lead.id

  return (
    <div>
      <PageHeader icon={<Inbox />} title="Inbox" description="Every SMS, WhatsApp and email conversation with your leads in one place." />
      <div className="card grid h-[calc(100vh-220px)] min-h-[520px] overflow-hidden md:grid-cols-[340px_1fr]">
        <aside className="flex min-h-0 flex-col border-b border-slate-200/60 md:border-r md:border-b-0 dark:border-white/[0.06]">
          <div className="space-y-2 p-3">
            <Input icon={<Search className="size-4" />} value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search people or companies" aria-label="Search conversations" />
            <div className="flex gap-2">
              <Select value={filter} onChange={(e) => setFilter(e.target.value as typeof filter)} aria-label="Filter conversations">
                <option value="all">All conversations</option>
                <option value="unread">Unread{data?.unread_threads ? ` (${data.unread_threads})` : ''}</option>
                <option value="awaiting">Waiting for our reply</option>
                <option value="mine">My leads</option>
              </Select>
              <Select value={channel} onChange={(e) => setChannel(e.target.value)} aria-label="Channel">
                <option value="">All channels</option><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option><option value="email">Email</option>
              </Select>
            </div>
          </div>
          <div className="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 pb-2">
            {isLoading ? <PageLoader /> : !data?.data.length ? <EmptyState icon={<Inbox />} title="No conversations" description="Messages you send and replies leads send back appear here." className="py-10" />
              : data.data.map((t) => <ThreadRow key={t.lead.id} t={t} active={t.lead.id === open} onOpen={() => setParams({ lead: String(t.lead.id) })} />)}
          </div>
        </aside>
        <section className="min-h-0">
          {open ? <Conversation key={open} leadId={open} /> : <EmptyState icon={<MessageCircle />} title="Pick a conversation" className="h-full" />}
        </section>
      </div>
    </div>
  )
}
