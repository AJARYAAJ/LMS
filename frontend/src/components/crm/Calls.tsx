import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import clsx from 'clsx'
import { Bot, CircleStop, Clock, PhoneCall, PhoneOutgoing, Sparkles } from 'lucide-react'
import { useAction, useAppDispatch, usePermissions, useToast } from '@/app/hooks'
import { api, resources, useCallLeadMutation, useCallQuery, useCancelCallMutation, useSettings } from '@/services/api'
import { Badge, Button, Drawer, EmptyState, Field, Modal, PageLoader, Select } from '@/components/ui'
import { dateTime, humanize } from '@/lib/format'
import type { Call, CallStatus } from '@/types'

export const FINAL_STATUSES: CallStatus[] = ['completed', 'no_answer', 'voicemail', 'failed', 'canceled']

export const OUTCOMES: Record<string, { label: string; color: string }> = {
  meeting_booked: { label: 'Meeting booked', color: '#10b981' },
  interested: { label: 'Interested', color: '#6366f1' },
  callback: { label: 'Callback', color: '#0ea5e9' },
  not_interested: { label: 'Not interested', color: '#f43f5e' },
  voicemail: { label: 'Voicemail', color: '#f59e0b' },
  no_answer: { label: 'No answer', color: '#94a3b8' },
  wrong_number: { label: 'Wrong number', color: '#64748b' },
}

const STATUS_COLOR: Record<CallStatus, string> = {
  queued: '#94a3b8', ringing: '#0ea5e9', in_progress: '#8b5cf6', completed: '#10b981',
  no_answer: '#94a3b8', voicemail: '#f59e0b', failed: '#f43f5e', canceled: '#64748b',
}

export const isLive = (c?: Pick<Call, 'status'> | null) => !!c && !FINAL_STATUSES.includes(c.status)

export function duration(seconds?: number | null) {
  if (!seconds) return '—'
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

export function CallStatusBadge({ status }: { status: CallStatus }) {
  return (
    <Badge color={STATUS_COLOR[status]} dot>
      {isLive({ status }) && <span className="mr-0.5 inline-block size-1.5 animate-ping rounded-full bg-current" />}
      {humanize(status)}
    </Badge>
  )
}

export function OutcomeBadge({ outcome }: { outcome?: string | null }) {
  if (!outcome) return <span className="text-xs text-slate-400">—</span>
  const o = OUTCOMES[outcome] ?? { label: humanize(outcome), color: '#64748b' }
  return <Badge color={o.color}>{o.label}</Badge>
}

const SENTIMENT: Record<string, string> = { positive: '😊 Positive', neutral: '😐 Neutral', negative: '🙁 Negative' }

/** Full call record: transcript bubbles, AI summary, captured answers and recording. */
export function CallDrawer({ callId, onClose }: { callId: number | null; onClose: () => void }) {
  const run = useAction()
  const { data: call, isLoading } = useCallQuery(callId ?? 0, { skip: !callId, pollingInterval: 3000, skipPollingIfUnfocused: true })
  const [cancel, cancelState] = useCancelCallMutation()
  const live = isLive(call)
  const leadName = call?.lead ? [call.lead.first_name, call.lead.last_name].filter(Boolean).join(' ') : 'Unknown lead'

  return (
    <Drawer open={!!callId} onClose={onClose} width="max-w-2xl"
      title={<span className="flex items-center gap-2"><PhoneCall className="size-5 text-brand-500" />AI call {call ? `· ${leadName}` : ''}</span>}
      footer={live && call ? <Button variant="danger" size="sm" icon={<CircleStop className="size-4" />} loading={cancelState.isLoading} onClick={() => run(cancel(call.id), 'Call canceled')}>Cancel call</Button> : undefined}>
      {isLoading || !call ? <PageLoader /> : (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center gap-2">
            <CallStatusBadge status={call.status} />
            <OutcomeBadge outcome={call.outcome} />
            {call.sentiment && <span className="text-xs text-slate-500">{SENTIMENT[call.sentiment]}</span>}
            <span className="ml-auto flex items-center gap-1 text-xs text-slate-500"><Clock className="size-3.5" />{duration(call.duration_seconds)}</span>
          </div>
          <div className="grid gap-3 rounded-2xl bg-slate-900/[0.03] p-4 text-sm sm:grid-cols-2 dark:bg-white/[0.04]">
            <p><span className="text-slate-500">Lead: </span>{call.lead ? <Link className="font-medium text-brand-600 hover:underline" to={`/leads/${call.lead.id}`}>{leadName}</Link> : '—'}</p>
            <p><span className="text-slate-500">Agent: </span><span className="font-medium">{call.agent?.name ?? '—'}</span></p>
            <p><span className="text-slate-500">Number: </span>{call.to_number}</p>
            <p><span className="text-slate-500">Provider: </span>{humanize(call.provider)}</p>
            <p><span className="text-slate-500">Started: </span>{dateTime(call.started_at ?? call.created_at)}</p>
            <p><span className="text-slate-500">By: </span>{call.user?.name ?? (call.campaign_key ? 'Campaign' : 'Automation')}</p>
          </div>
          {call.error && <p className="rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{call.error}</p>}
          {live && (
            <div className="flex items-center gap-3 rounded-2xl border border-dashed border-brand-300/60 p-4 text-sm text-slate-600 dark:border-brand-400/30 dark:text-slate-300">
              <span className="relative flex size-3"><span className="absolute inline-flex size-full animate-ping rounded-full bg-brand-400 opacity-75" /><span className="relative inline-flex size-3 rounded-full bg-brand-500" /></span>
              The agent is on the call. The transcript and summary appear here when it ends.
            </div>
          )}
          {call.summary && (
            <div className="rounded-2xl bg-gradient-to-br from-brand-500/10 to-fuchsia-500/10 p-4">
              <p className="mb-1 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-brand-700 uppercase dark:text-brand-300"><Sparkles className="size-3.5" />Summary</p>
              <p className="text-sm text-slate-700 dark:text-slate-200">{call.summary}</p>
              {call.extracted?.next_step && <p className="mt-2 text-sm"><span className="font-medium">Next step:</span> {call.extracted.next_step}</p>}
              {call.extracted?.follow_up_at && <p className="mt-1 text-sm"><span className="font-medium">Follow-up:</span> {dateTime(call.extracted.follow_up_at)}</p>}
            </div>
          )}
          {!!call.agent?.questions?.length && !live && (
            <div>
              <p className="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Qualification questions</p>
              <ul className="space-y-1.5">
                {call.agent.questions.map((q) => {
                  const ok = call.extracted?.confirmed?.includes(q.key)
                  return (
                    <li key={q.key} className="flex items-start gap-2 text-sm">
                      <span className={clsx('mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full text-[10px] font-bold', ok ? 'bg-emerald-500 text-white' : 'bg-slate-200 text-slate-500 dark:bg-slate-700')}>{ok ? '✓' : '·'}</span>
                      <span className={ok ? '' : 'text-slate-500'}>{q.question}</span>
                    </li>
                  )
                })}
              </ul>
            </div>
          )}
          {call.recording_url && <audio controls src={call.recording_url} className="w-full" />}
          <div>
            <p className="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Transcript</p>
            {!call.transcript?.length ? <p className="text-sm text-slate-400">{live ? 'Waiting for the call to finish…' : 'No transcript for this call.'}</p> : (
              <div className="space-y-2">
                {call.transcript.map((t, i) => (
                  <div key={i} className={clsx('flex', t.role === 'lead' ? 'justify-end' : 'justify-start')}>
                    <div className={clsx('max-w-[85%] rounded-2xl px-3.5 py-2 text-sm', t.role === 'lead'
                      ? 'rounded-br-md bg-brand-600 text-white'
                      : 'rounded-bl-md bg-slate-100 text-slate-800 dark:bg-white/[0.06] dark:text-slate-100')}>
                      <p className={clsx('mb-0.5 text-[10px] font-semibold tracking-wide uppercase', t.role === 'lead' ? 'text-white/70' : 'text-slate-400')}>{t.role === 'lead' ? leadName : call.agent?.name ?? 'Agent'}</p>
                      {t.text}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </Drawer>
  )
}

/** "AI call" button on a lead: pick an agent, dial, and follow the call live. */
export function AiCallButton({ leadId, phone }: { leadId: number; phone: string | null }) {
  const run = useAction()
  const toast = useToast()
  const dispatch = useAppDispatch()
  const { write } = usePermissions()
  const { data: agents } = useSettings(resources.aiAgents)
  const [callLead, callState] = useCallLeadMutation()
  const [open, setOpen] = useState(false)
  const [agentId, setAgentId] = useState('')
  const [callId, setCallId] = useState<number | null>(null)
  const [viewing, setViewing] = useState<number | null>(null)
  const active = agents?.filter((a) => a.is_active) ?? []
  const { data: call } = useCallQuery(callId ?? 0, { skip: !callId, pollingInterval: 2500 })

  useEffect(() => {
    if (!agentId && active[0]) setAgentId(String(active[0].id))
  }, [active, agentId])

  useEffect(() => {
    if (call && callId && !isLive(call)) {
      toast(call.status === 'completed' ? 'success' : 'info', `AI call ended — ${OUTCOMES[call.outcome ?? '']?.label ?? humanize(call.status)}`)
      dispatch(api.util.invalidateTags(['Lead', 'Activity', 'Task', 'Score', 'Insights']))
      setCallId(null)
    }
  }, [call, callId, dispatch, toast])

  if (!write) return null

  const start = async () => {
    const c = await run(callLead({ leadId, ai_agent_id: Number(agentId) }), 'Call started — the agent is dialing')
    if (c) { setCallId(c.id); setOpen(false) }
  }

  return (
    <>
      {callId ? (
        <Button size="sm" variant="secondary" icon={<span className="relative flex size-2.5"><span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400" /><span className="relative inline-flex size-2.5 rounded-full bg-emerald-500" /></span>} onClick={() => setViewing(callId)}>
          {call ? humanize(call.status) : 'Dialing'}…
        </Button>
      ) : (
        <Button size="sm" variant="secondary" icon={<Bot className="size-4" />} onClick={() => setOpen(true)} disabled={!phone}>AI call</Button>
      )}
      <Modal open={open} onClose={() => setOpen(false)} size="sm" title="Call with an AI agent" description="The agent calls the lead, asks your qualification questions and logs the outcome on the timeline."
        footer={<><Button variant="secondary" onClick={() => setOpen(false)}>Cancel</Button><Button icon={<PhoneOutgoing className="size-4" />} disabled={!agentId} loading={callState.isLoading} onClick={start}>Start call</Button></>}>
        {!active.length ? (
          <EmptyState icon={<Bot />} title="No active AI agents" description="An admin or manager can create one on the AI calls page." action={<Link to="/calls?tab=agents" className="text-sm font-medium text-brand-600">Set up an agent →</Link>} />
        ) : (
          <div className="space-y-4">
            <Field label="Agent"><Select value={agentId} onChange={(e) => setAgentId(e.target.value)}>{active.map((a) => <option key={a.id} value={a.id}>{a.name} · {humanize(a.integration?.provider ?? 'default')}</option>)}</Select></Field>
            <p className="text-sm text-slate-500">Dialing <span className="font-medium text-slate-800 dark:text-slate-200">{phone}</span></p>
          </div>
        )}
      </Modal>
      <CallDrawer callId={viewing} onClose={() => setViewing(null)} />
    </>
  )
}
