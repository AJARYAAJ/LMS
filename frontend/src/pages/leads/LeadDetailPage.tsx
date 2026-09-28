import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import clsx from 'clsx'
import {
  ArrowLeft, BadgeCheck, Building2, CalendarClock, Check, CheckCircle2, ChevronRight, Circle, Copy, Globe, GitMerge, Lightbulb, Mail,
  MapPin, MessageCircle, MoreHorizontal, Pencil, Phone, Pin, PinOff, Plus, Repeat, Send, Sparkles, Trash2, TrendingDown, TrendingUp, TriangleAlert,
  UserPlus, Workflow, Zap,
} from 'lucide-react'
import { useAction, useAppSelector, useCurrentUser, usePermissions, useToast } from '@/app/hooks'
import {
  errorMessage, useActivitiesQuery, useAddNoteMutation, useAdjustScoreMutation, useChangeLeadStatusMutation, useClaimLeadMutation,
  useDeleteActivityMutation, useDeleteLeadMutation, useDeleteNoteMutation, useEnrollmentsQuery, useInsightsQuery, useLeadHistoryQuery,
  useLeadQuery, useLeadScoreQuery, useMetaQuery, useNotesQuery, useStopEnrollmentMutation, useTasksQuery, useToggleTaskMutation,
  useUpdateNoteMutation, useUpdateQualificationMutation,
} from '@/services/api'
import {
  Avatar, Badge, Button, Card, ConfirmDialog, DescriptionList, EmptyState, Input, Menu, MenuItem, Modal, PageLoader, ScoreRing, Tabs, Textarea,
} from '@/components/ui'
import { Owner, PriorityBadge, RatingBadge } from '@/components/crm/Badges'
import { Timeline } from '@/components/crm/Timeline'
import { TaskFormModal } from '@/components/crm/TaskFormModal'
import { ActivityModal } from '@/components/crm/ActivityModal'
import { ago, date, dateTime, friendlyDue, humanize, money } from '@/lib/format'
import { RATING_META } from '@/lib/constants'
import type { LeadStatus } from '@/types'
import { LeadFormModal } from './LeadFormModal'
import { AiBriefPanel, AssignModal, ConvertModal, EmailComposerModal, EnrollModal, LostModal, MergeModal, MessageModal } from './LeadModals'

type Tab = 'timeline' | 'notes' | 'tasks' | 'sequences' | 'history'

export function LeadDetailPage() {
  const id = Number(useParams().id)
  const navigate = useNavigate()
  const run = useAction()
  const toast = useToast()
  const me = useCurrentUser()
  const { write, manager } = usePermissions()
  const currency = useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
  const { data: meta } = useMetaQuery()
  const { data: lead, isLoading, isError } = useLeadQuery(id)
  const { data: insights } = useInsightsQuery(id)
  const [changeStatus] = useChangeLeadStatusMutation()
  const [claim] = useClaimLeadMutation()
  const [deleteLead, deleteState] = useDeleteLeadMutation()

  const [tab, setTab] = useState<Tab>('timeline')
  const [modal, setModal] = useState<null | 'message' | 'edit' | 'activity' | 'email' | 'task' | 'convert' | 'assign' | 'merge' | 'enroll' | 'delete' | 'lost'>(null)
  const [activityType, setActivityType] = useState('call')
  const [lostStatus, setLostStatus] = useState<LeadStatus | null>(null)

  const journey = useMemo(() => (meta?.statuses ?? []).filter((s) => s.is_active && s.category !== 'lost'), [meta])
  const lostStatuses = useMemo(() => (meta?.statuses ?? []).filter((s) => s.is_active && s.category === 'lost'), [meta])

  if (isLoading) return <PageLoader />
  if (isError || !lead) return <EmptyState title="Lead not found" description="It may have been deleted or you may not have access." action={<Button onClick={() => navigate('/leads')}>Back to leads</Button>} />

  const statusColor = lead.status?.color ?? '#8b5cf6'
  const currentIndex = journey.findIndex((s) => s.id === lead.lead_status_id)
  const converted = !!lead.converted_at

  const moveTo = async (status: LeadStatus) => {
    if (status.id === lead.lead_status_id || !write) return
    if (status.category === 'lost') { setLostStatus(status); setModal('lost'); return }
    if (status.category === 'converted') { setModal('convert'); return }
    try {
      await changeStatus({ id: lead.id, lead_status_id: status.id }).unwrap()
      toast('success', `Moved to ${status.name}`)
    } catch (e) {
      toast('error', 'Blueprint check', errorMessage(e))
    }
  }

  const quickAction = (type: string) => {
    if (type === 'call' || type === 'email' && !lead.email) { setActivityType(type === 'email' ? 'email' : 'call'); setModal('activity') }
    else if (type === 'email') setModal('email')
    else if (type === 'assign') setModal('assign')
    else if (type === 'convert' || type === 'deal') type === 'deal' && lead.converted_deal ? navigate(`/deals/${lead.converted_deal.id}`) : setModal('convert')
    else if (type === 'sequence') setModal('enroll')
    else if (type === 'qualify') document.getElementById('qualification')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    else setModal('task')
  }

  const copy = (text: string) => { navigator.clipboard?.writeText(text); toast('info', 'Copied to clipboard') }

  return (
    <div className="space-y-6">
      <Link to="/leads" className="inline-flex items-center gap-1.5 text-sm text-slate-500 transition hover:gap-2.5 hover:text-brand-600"><ArrowLeft className="size-4" /> All leads</Link>

      {/* ---------- Hero ---------- */}
      <section className="card overflow-hidden">
        <div className="absolute inset-x-0 top-0 -z-10 h-40 opacity-60" style={{ background: `radial-gradient(80% 120% at 10% 0%, ${statusColor}55, transparent 60%), radial-gradient(60% 100% at 90% 0%, #d946ef33, transparent 60%)` }} />
        <div className="flex flex-col gap-6 p-6 lg:flex-row lg:items-start lg:justify-between">
          <div className="flex min-w-0 items-start gap-5">
            <div className="relative">
              <span className="absolute -inset-1 rounded-full opacity-60 blur-md" style={{ backgroundColor: statusColor }} />
              <Avatar name={lead.full_name} color={statusColor} size="xl" className="relative" />
            </div>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">{lead.full_name}</h1>
                {converted && <Badge color="#059669"><BadgeCheck className="size-3.5" /> Converted</Badge>}
                <PriorityBadge priority={lead.priority} />
              </div>
              <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
                {[lead.job_title, lead.company].filter(Boolean).join(' at ') || 'No company yet'}
              </p>
              <div className="mt-3 flex flex-wrap gap-2">
                {lead.email && <span className="chip"><Mail className="size-3.5 text-brand-500" /><a href={`mailto:${lead.email}`} className="hover:text-brand-600">{lead.email}</a><button onClick={() => copy(lead.email!)} aria-label="Copy email"><Copy className="size-3 text-slate-400 hover:text-brand-600" /></button></span>}
                {lead.phone && <span className="chip"><Phone className="size-3.5 text-emerald-500" /><a href={`tel:${lead.phone}`} className="hover:text-brand-600">{lead.phone}</a></span>}
                {lead.website && <span className="chip"><Globe className="size-3.5 text-cyan-500" /><a href={lead.website.startsWith('http') ? lead.website : `https://${lead.website}`} target="_blank" rel="noreferrer" className="hover:text-brand-600">{lead.website}</a></span>}
                {(lead.city || lead.country) && <span className="chip"><MapPin className="size-3.5 text-rose-500" />{[lead.city, lead.country].filter(Boolean).join(', ')}</span>}
                {lead.tags?.map((t) => <Badge key={t.id} color={t.color}>#{t.name}</Badge>)}
              </div>
            </div>
          </div>

          <div className="flex shrink-0 items-center gap-5">
            <div className="text-center">
              <ScoreRing score={lead.score} size={84} />
              <div className="mt-1.5"><RatingBadge rating={lead.rating} /></div>
            </div>
            <div className="hidden h-16 w-px bg-slate-200/70 sm:block dark:bg-white/10" />
            <div className="space-y-1.5 text-sm">
              <p className="text-xs text-slate-500">Owner</p>
              {lead.owner ? <Owner user={lead.owner} /> : write && !manager ? (
                <Button size="xs" variant="subtle" icon={<UserPlus className="size-3.5" />} onClick={() => run(claim(lead.id), 'Lead claimed')}>Claim</Button>
              ) : <Owner user={null} />}
              <p className="pt-1 text-xs text-slate-500">Expected value</p>
              <p className="font-display font-semibold text-slate-900 dark:text-white">{lead.expected_value ? money(lead.expected_value, currency) : '—'}</p>
            </div>
          </div>
        </div>

        {/* action bar */}
        {write && (
          <div className="flex flex-wrap items-center gap-2 border-t border-slate-200/60 px-6 py-3 dark:border-white/[0.06]">
            <Button size="sm" variant="secondary" icon={<Phone className="size-4" />} onClick={() => { setActivityType('call'); setModal('activity') }}>Log call</Button>
            <Button size="sm" variant="secondary" icon={<Send className="size-4" />} onClick={() => setModal('email')} disabled={!lead.email}>Email</Button>
            <Button size="sm" variant="secondary" icon={<CalendarClock className="size-4" />} onClick={() => setModal('task')}>Task</Button>
            <Button size="sm" variant="secondary" icon={<MessageCircle className="size-4" />} onClick={() => setModal('message')} disabled={!lead.phone}>SMS / WhatsApp</Button>
            <Button size="sm" variant="secondary" icon={<Repeat className="size-4" />} onClick={() => setModal('enroll')}>Sequence</Button>
            <div className="ml-auto flex items-center gap-2">
              {!converted && <Button size="sm" icon={<Sparkles className="size-4" />} onClick={() => setModal('convert')}>Convert</Button>}
              <Menu trigger={({ toggle }) => <Button size="sm" variant="ghost" onClick={toggle} aria-label="More"><MoreHorizontal className="size-4" /></Button>}>
                {(close) => (
                  <>
                    <MenuItem icon={<Pencil />} onClick={() => { setModal('edit'); close() }}>Edit details</MenuItem>
                    {manager && <MenuItem icon={<UserPlus />} onClick={() => { setModal('assign'); close() }}>Assign owner</MenuItem>}
                    {manager && <MenuItem icon={<GitMerge />} onClick={() => { setModal('merge'); close() }}>Merge duplicate…</MenuItem>}
                    {lostStatuses.map((s) => <MenuItem key={s.id} icon={<TrendingDown />} onClick={() => { setLostStatus(s); setModal('lost'); close() }}>Mark as {s.name}</MenuItem>)}
                    {manager && <MenuItem icon={<Trash2 />} danger onClick={() => { setModal('delete'); close() }}>Delete lead</MenuItem>}
                  </>
                )}
              </Menu>
            </div>
          </div>
        )}
      </section>

      {/* ---------- Journey (blueprint-aware stepper) ---------- */}
      <section className="card p-2">
        <ol className="flex gap-1 overflow-x-auto">
          {journey.map((s, i) => {
            const done = currentIndex > -1 && i < currentIndex
            const current = s.id === lead.lead_status_id
            return (
              <li key={s.id} className="min-w-32 flex-1">
                <button
                  onClick={() => moveTo(s)}
                  disabled={!write || converted}
                  title={s.required_fields?.length ? `Requires: ${s.required_fields.map(humanize).join(', ')}` : undefined}
                  className={clsx(
                    'group relative flex w-full items-center gap-2 rounded-2xl px-3 py-3 text-left text-sm font-medium transition-all duration-300 disabled:cursor-default',
                    current ? 'text-white shadow-[0_10px_30px_-10px_var(--c)]' : done ? 'text-slate-700 dark:text-slate-200' : 'text-slate-400 hover:bg-slate-900/[0.04] hover:text-slate-700 dark:hover:bg-white/[0.05] dark:hover:text-slate-200',
                  )}
                  style={{ ['--c' as string]: s.color, ...(current ? { background: `linear-gradient(135deg, ${s.color}, ${s.color}bb)` } : {}) }}
                >
                  <span className={clsx('flex size-6 shrink-0 items-center justify-center rounded-full text-[11px]', current ? 'bg-white/25' : done ? 'text-white' : 'border border-current')} style={done ? { backgroundColor: s.color } : undefined}>
                    {done ? <Check className="size-3.5" /> : i + 1}
                  </span>
                  <span className="truncate">{s.name}</span>
                  {!!s.required_fields?.length && !current && <span className="ml-auto size-1.5 shrink-0 rounded-full bg-amber-400" title="Has required fields" />}
                  {i < journey.length - 1 && !current && <ChevronRight className="absolute -right-2 size-4 text-slate-300 dark:text-slate-600" />}
                </button>
              </li>
            )
          })}
        </ol>
        {lead.status?.category === 'lost' && (
          <p className="flex items-center gap-2 px-3 pt-2 pb-1 text-sm text-rose-600"><TriangleAlert className="size-4" /> {lead.status.name}{lead.lost_reason && ` — ${lead.lost_reason}`}</p>
        )}
      </section>

      <div className="grid gap-6 xl:grid-cols-[1fr_380px]">
        <div className="min-w-0 space-y-6">
          {/* ---------- Insights ---------- */}
          {insights && (
            <section className="card gradient-border overflow-hidden p-6">
              <div className="absolute -top-24 -right-24 -z-10 size-72 rounded-full bg-[radial-gradient(circle,rgba(217,70,239,0.22)_0%,transparent_65%)]" />
              <div className="flex items-center gap-2 text-xs font-semibold tracking-[0.12em] text-brand-600 uppercase dark:text-brand-300"><Lightbulb className="size-4" /> Smart insights</div>
              <p className="mt-3 text-[15px] leading-relaxed text-slate-700 dark:text-slate-300">{insights.summary}</p>
              <div className="mt-5 flex flex-col gap-4 lg:flex-row lg:items-center">
                <div className="flex-1 rounded-2xl bg-gradient-to-r from-brand-600 via-fuchsia-600 to-pink-600 p-[1px]">
                  <div className="flex items-center gap-4 rounded-[15px] bg-white/95 px-4 py-3 dark:bg-ink-900/95">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-fuchsia-500 text-white shadow-lg shadow-fuchsia-500/30"><Zap className="size-5" /></span>
                    <div className="min-w-0 flex-1">
                      <p className="text-[11px] font-semibold tracking-wider text-slate-500 uppercase">Next best action</p>
                      <p className="font-display font-semibold text-slate-900 dark:text-white">{insights.next_action.title}</p>
                      <p className="text-xs text-slate-500">{insights.next_action.reason}</p>
                    </div>
                    {write && <Button size="sm" onClick={() => quickAction(insights.next_action.type)}>Do it</Button>}
                  </div>
                </div>
              </div>
              <AiBriefPanel leadId={lead.id} />
              {!!insights.signals.length && (
                <ul className="mt-4 flex flex-wrap gap-2">
                  {insights.signals.map((s, i) => (
                    <li key={i} className={clsx('inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium',
                      s.tone === 'positive' && 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
                      s.tone === 'warning' && 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
                      s.tone === 'negative' && 'bg-rose-500/10 text-rose-700 dark:text-rose-300')}>
                      {s.tone === 'positive' ? <TrendingUp className="size-3.5" /> : s.tone === 'negative' ? <TrendingDown className="size-3.5" /> : <TriangleAlert className="size-3.5" />}
                      {s.text}
                    </li>
                  ))}
                </ul>
              )}
            </section>
          )}

          <Tabs<Tab>
            value={tab}
            onChange={setTab}
            tabs={[
              { value: 'timeline', label: 'Timeline', count: lead.activities_count },
              { value: 'notes', label: 'Notes', count: lead.notes_count },
              { value: 'tasks', label: 'Tasks', count: lead.tasks_count },
              { value: 'sequences', label: 'Sequences' },
              { value: 'history', label: 'Stage history' },
            ]}
          />
          <div className="animate-fade-in">
            {tab === 'timeline' && <TimelineTab leadId={lead.id} canWrite={write} onLog={(t) => { setActivityType(t); setModal('activity') }} />}
            {tab === 'notes' && <NotesTab leadId={lead.id} canWrite={write} myId={me?.id} />}
            {tab === 'tasks' && <TasksTab leadId={lead.id} onNew={() => setModal('task')} canWrite={write} />}
            {tab === 'sequences' && <SequencesTab leadId={lead.id} onEnroll={() => setModal('enroll')} canWrite={write} />}
            {tab === 'history' && <HistoryTab leadId={lead.id} />}
          </div>
        </div>

        {/* ---------- Side column ---------- */}
        <div className="space-y-6">
          <QualificationCard leadId={lead.id} answers={lead.qualification ?? {}} canWrite={write} progress={insights?.qualification.percent ?? 0} />

          {converted && (
            <Card title="Converted to">
              <div className="space-y-2 text-sm">
                {lead.converted_contact && <Link to={`/contacts/${lead.converted_contact.id}`} className="flex items-center justify-between rounded-xl px-2 py-1.5 hover:bg-brand-50 dark:hover:bg-white/5"><span>👤 {lead.converted_contact.first_name} {lead.converted_contact.last_name}</span><ChevronRight className="size-4 text-slate-400" /></Link>}
                {lead.converted_account && <Link to={`/accounts/${lead.converted_account.id}`} className="flex items-center justify-between rounded-xl px-2 py-1.5 hover:bg-brand-50 dark:hover:bg-white/5"><span>🏢 {lead.converted_account.name}</span><ChevronRight className="size-4 text-slate-400" /></Link>}
                {lead.converted_deal && <Link to={`/deals/${lead.converted_deal.id}`} className="flex items-center justify-between rounded-xl px-2 py-1.5 hover:bg-brand-50 dark:hover:bg-white/5"><span>🤝 {lead.converted_deal.name} · {money(lead.converted_deal.amount, currency)}</span><ChevronRight className="size-4 text-slate-400" /></Link>}
              </div>
            </Card>
          )}

          <Card title="Details" action={write && <button onClick={() => setModal('edit')} className="text-xs font-medium text-brand-600 hover:text-brand-700">Edit</button>}>
            <DescriptionList items={[
              { label: 'Source', value: lead.source && <Badge color={lead.source.color}>{lead.source.name}</Badge> },
              { label: 'Campaign', value: lead.campaign?.name },
              { label: 'Team', value: lead.team?.name },
              { label: 'Industry', value: lead.industry },
              { label: 'Company size', value: lead.company_size },
              { label: 'Budget', value: lead.budget && money(lead.budget, currency) },
              { label: 'Timeline', value: lead.timeline },
              { label: 'Next follow-up', value: lead.next_follow_up_at && <span className={clsx(new Date(lead.next_follow_up_at) < new Date() && !converted && 'text-rose-600')}>{friendlyDue(lead.next_follow_up_at)}</span> },
              { label: 'Last contacted', value: lead.last_contacted_at && ago(lead.last_contacted_at) },
              { label: 'Created', value: `${date(lead.created_at)} · ${lead.creator?.name ?? 'system'}` },
              ...(meta?.custom_fields.filter((f) => f.entity === 'lead').map((f) => ({ label: f.label, value: String(lead.custom_fields?.[f.key] ?? '') })) ?? []),
            ]} />
            {lead.requirements && (
              <div className="mt-4 rounded-2xl bg-slate-900/[0.03] p-4 dark:bg-white/[0.03]">
                <p className="label">Requirements</p>
                <p className="text-sm whitespace-pre-line text-slate-700 dark:text-slate-300">{lead.requirements}</p>
              </div>
            )}
          </Card>

          <ScoreCard leadId={lead.id} canWrite={write} />
        </div>
      </div>

      <LeadFormModal open={modal === 'edit'} onClose={() => setModal(null)} lead={lead} />
      <ActivityModal open={modal === 'activity'} onClose={() => setModal(null)} subjectType="leads" subjectId={lead.id} initialType={activityType} />
      <EmailComposerModal open={modal === 'email'} onClose={() => setModal(null)} lead={lead} />
      <MessageModal open={modal === 'message'} onClose={() => setModal(null)} lead={lead} />
      <TaskFormModal open={modal === 'task'} onClose={() => setModal(null)} subject={{ type: 'lead', id: lead.id, name: lead.full_name }} />
      <ConvertModal open={modal === 'convert'} onClose={() => setModal(null)} lead={lead} />
      <AssignModal open={modal === 'assign'} onClose={() => setModal(null)} lead={lead} />
      <MergeModal open={modal === 'merge'} onClose={() => setModal(null)} lead={lead} />
      <EnrollModal open={modal === 'enroll'} onClose={() => setModal(null)} lead={lead} />
      <LostModal open={modal === 'lost'} onClose={() => setModal(null)} lead={lead} status={lostStatus} />
      <ConfirmDialog
        open={modal === 'delete'}
        onClose={() => setModal(null)}
        title={`Delete ${lead.full_name}?`}
        message="The lead moves to the recycle bin and can be restored by an admin or manager."
        loading={deleteState.isLoading}
        onConfirm={async () => { if (await run(deleteLead(lead.id), 'Lead moved to recycle bin') !== undefined) navigate('/leads') }}
      />
    </div>
  )
}

function QualificationCard({ leadId, answers, canWrite, progress }: { leadId: number; answers: Record<string, boolean>; canWrite: boolean; progress: number }) {
  const { data: meta } = useMetaQuery()
  const [update, { isLoading }] = useUpdateQualificationMutation()
  const criteria = meta?.qualification_criteria ?? []

  return (
    <section id="qualification" className="card p-5">
      <div className="flex items-center justify-between">
        <h3 className="text-[15px] font-semibold text-slate-900 dark:text-white">Qualification</h3>
        <span className="font-display text-sm font-bold gradient-text">{progress}%</span>
      </div>
      <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-200/70 dark:bg-white/10">
        <div className="h-full rounded-full bg-gradient-to-r from-brand-500 via-fuchsia-500 to-cyan-400 transition-all duration-700" style={{ width: `${progress}%` }} />
      </div>
      <ul className="mt-4 space-y-1.5">
        {criteria.map((c) => {
          const on = !!answers[c.key]
          return (
            <li key={c.key}>
              <button
                disabled={!canWrite || isLoading}
                onClick={() => update({ id: leadId, answers: { [c.key]: !on } })}
                className={clsx('flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left text-sm transition', on ? 'bg-emerald-500/10 text-emerald-800 dark:text-emerald-200' : 'text-slate-600 hover:bg-slate-900/[0.04] dark:text-slate-300 dark:hover:bg-white/[0.05]')}
              >
                {on ? <CheckCircle2 className="size-5 text-emerald-500" /> : <Circle className="size-5 text-slate-300 dark:text-slate-600" />}
                {c.label}
              </button>
            </li>
          )
        })}
      </ul>
    </section>
  )
}

function ScoreCard({ leadId, canWrite }: { leadId: number; canWrite: boolean }) {
  const run = useAction()
  const { data } = useLeadScoreQuery(leadId)
  const [adjust, { isLoading }] = useAdjustScoreMutation()
  const [open, setOpen] = useState(false)
  const [points, setPoints] = useState('10')
  const [reason, setReason] = useState('')

  return (
    <Card title="Score breakdown" subtitle={data ? `${RATING_META[data.rating as keyof typeof RATING_META]?.label ?? ''} · explainable, rule-based` : undefined}
      action={canWrite && <button onClick={() => setOpen(true)} className="text-xs font-medium text-brand-600 hover:text-brand-700">Adjust</button>}>
      <ul className="space-y-2">
        {data?.events.map((e) => (
          <li key={e.id} className="flex items-center justify-between gap-3 text-sm">
            <span className="flex items-center gap-2 text-slate-600 dark:text-slate-400">
              <span className={clsx('size-1.5 rounded-full', e.scoring_rule_id ? 'bg-brand-400' : 'bg-fuchsia-400')} />
              {e.reason}
            </span>
            <span className={clsx('font-display font-semibold tabular-nums', e.points >= 0 ? 'text-emerald-600' : 'text-rose-600')}>{e.points > 0 ? '+' : ''}{e.points}</span>
          </li>
        ))}
        {!data?.events.length && <li className="text-sm text-slate-500">No scoring signals yet.</li>}
      </ul>
      <Modal open={open} onClose={() => setOpen(false)} size="sm" title="Adjust score" footer={<><Button variant="secondary" onClick={() => setOpen(false)}>Cancel</Button><Button disabled={!reason} loading={isLoading} onClick={async () => { if (await run(adjust({ id: leadId, points: Number(points), reason }), 'Score adjusted') !== undefined) setOpen(false) }}>Apply</Button></>}>
        <div className="space-y-3">
          <Input type="number" value={points} onChange={(e) => setPoints(e.target.value)} min={-100} max={100} />
          <Input placeholder="Reason, e.g. Requested demo" value={reason} onChange={(e) => setReason(e.target.value)} />
        </div>
      </Modal>
    </Card>
  )
}

function TimelineTab({ leadId, canWrite, onLog }: { leadId: number; canWrite: boolean; onLog: (type: string) => void }) {
  const [filter, setFilter] = useState('')
  const { data, isLoading } = useActivitiesQuery({ type: 'leads', id: leadId, filter: filter || undefined, per_page: 50 })
  const [remove] = useDeleteActivityMutation()
  const filters = [['', 'All'], ['call', 'Calls'], ['email', 'Emails'], ['meeting', 'Meetings'], ['note', 'Notes'], ['task', 'Tasks'], ['system', 'System']]

  return (
    <div className="card p-5">
      <div className="mb-5 flex flex-wrap items-center gap-2">
        {filters.map(([v, l]) => (
          <button key={v} onClick={() => setFilter(v)} className={clsx('chip transition', filter === v && 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>{l}</button>
        ))}
        {canWrite && (
          <div className="ml-auto flex gap-1.5">
            {[['call', Phone], ['meeting', CalendarClock], ['email', Mail]].map(([t, Icon]) => {
              const I = Icon as typeof Phone
              return <Button key={t as string} size="xs" variant="subtle" icon={<I className="size-3.5" />} onClick={() => onLog(t as string)}>{humanize(t as string)}</Button>
            })}
          </div>
        )}
      </div>
      {isLoading ? <PageLoader /> : data?.data.length ? <Timeline items={data.data} onDelete={canWrite ? (a) => remove(a.id) : undefined} /> : <EmptyState icon={<Workflow />} title="Nothing here yet" description="Log a call, meeting or email to start the story." />}
    </div>
  )
}

function NotesTab({ leadId, canWrite, myId }: { leadId: number; canWrite: boolean; myId?: number }) {
  const run = useAction()
  const { data } = useNotesQuery({ type: 'leads', id: leadId })
  const [add, { isLoading }] = useAddNoteMutation()
  const [update] = useUpdateNoteMutation()
  const [remove] = useDeleteNoteMutation()
  const [body, setBody] = useState('')

  return (
    <div className="space-y-4">
      {canWrite && (
        <div className="card p-4">
          <Textarea rows={3} placeholder="Write a note… (⌘/Ctrl + Enter to save)" value={body} onChange={(e) => setBody(e.target.value)}
            onKeyDown={async (e) => { if ((e.metaKey || e.ctrlKey) && e.key === 'Enter' && body.trim()) { if (await run(add({ type: 'leads', id: leadId, body }))) setBody('') } }} />
          <div className="mt-2 flex justify-end"><Button size="sm" disabled={!body.trim()} loading={isLoading} onClick={async () => { if (await run(add({ type: 'leads', id: leadId, body }), 'Note added')) setBody('') }}>Add note</Button></div>
        </div>
      )}
      {data?.map((n) => (
        <article key={n.id} className={clsx('card p-5', n.is_pinned && 'gradient-border')}>
          <div className="flex items-center gap-2">
            <Avatar name={n.user?.name} color={n.user?.avatar_color} size="sm" />
            <span className="text-sm font-medium text-slate-900 dark:text-white">{n.user?.name ?? 'System'}</span>
            <span className="text-xs text-slate-400" title={dateTime(n.created_at)}>{ago(n.created_at)}</span>
            {n.is_pinned && <Badge color="#d946ef"><Pin className="size-3" /> Pinned</Badge>}
            {canWrite && (
              <div className="ml-auto flex gap-1">
                <button onClick={() => update({ id: n.id, is_pinned: !n.is_pinned })} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-900/5 hover:text-brand-600" aria-label="Pin">{n.is_pinned ? <PinOff className="size-4" /> : <Pin className="size-4" />}</button>
                {n.user_id === myId && <button onClick={() => remove(n.id)} className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label="Delete"><Trash2 className="size-4" /></button>}
              </div>
            )}
          </div>
          <p className="mt-3 text-sm whitespace-pre-line text-slate-700 dark:text-slate-300">{n.body}</p>
        </article>
      ))}
      {!data?.length && <div className="card"><EmptyState title="No notes yet" description="Capture context your team should know." /></div>}
    </div>
  )
}

function TasksTab({ leadId, onNew, canWrite }: { leadId: number; onNew: () => void; canWrite: boolean }) {
  const { data } = useTasksQuery({ taskable_type: 'lead', taskable_id: leadId, view: 'all', per_page: 100 })
  const [toggle] = useToggleTaskMutation()

  return (
    <div className="card p-2">
      {canWrite && <div className="flex justify-end p-3"><Button size="sm" variant="subtle" icon={<Plus className="size-4" />} onClick={onNew}>New task</Button></div>}
      <ul className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
        {data?.data.map((t) => (
          <li key={t.id} className="flex items-center gap-3 px-4 py-3">
            <button onClick={() => toggle(t.id)} disabled={!canWrite} aria-label="Toggle task">
              {t.completed_at ? <CheckCircle2 className="size-5 text-emerald-500" /> : <Circle className="size-5 text-slate-300 hover:text-brand-500" />}
            </button>
            <div className="min-w-0 flex-1">
              <p className={clsx('truncate text-sm font-medium', t.completed_at ? 'text-slate-400 line-through' : 'text-slate-900 dark:text-white')}>{t.title}</p>
              <p className={clsx('text-xs', t.is_overdue ? 'font-medium text-rose-600' : 'text-slate-500')}>{humanize(t.type)} · {friendlyDue(t.due_at)} {t.assignee && `· ${t.assignee.name}`}</p>
            </div>
            <PriorityBadge priority={t.priority} />
          </li>
        ))}
      </ul>
      {!data?.data.length && <EmptyState title="No tasks" description="Plan the next step so nothing slips." />}
    </div>
  )
}

function SequencesTab({ leadId, onEnroll, canWrite }: { leadId: number; onEnroll: () => void; canWrite: boolean }) {
  const run = useAction()
  const { data } = useEnrollmentsQuery(leadId)
  const [stop] = useStopEnrollmentMutation()

  return (
    <div className="space-y-3">
      {canWrite && <div className="flex justify-end"><Button size="sm" variant="subtle" icon={<Repeat className="size-4" />} onClick={onEnroll}>Enroll in sequence</Button></div>}
      {data?.map((e) => {
        const pct = e.tasks_count ? Math.round((e.completed_tasks_count / e.tasks_count) * 100) : 0
        return (
          <div key={e.id} className="card p-5">
            <div className="flex items-center gap-3">
              <span className="flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-cyan-400 to-brand-500 text-white"><Repeat className="size-5" /></span>
              <div className="min-w-0 flex-1">
                <p className="font-medium text-slate-900 dark:text-white">{e.sequence.name}</p>
                <p className="text-xs text-slate-500">Enrolled {ago(e.created_at)} · {e.completed_tasks_count}/{e.tasks_count} steps done</p>
              </div>
              <Badge color={e.status === 'active' ? '#8b5cf6' : e.status === 'completed' ? '#10b981' : '#94a3b8'}>{humanize(e.status)}</Badge>
              {canWrite && e.status === 'active' && <Button size="xs" variant="ghost" onClick={() => run(stop(e.id), 'Sequence stopped')}>Stop</Button>}
            </div>
            <div className="mt-4 h-1.5 overflow-hidden rounded-full bg-slate-200/70 dark:bg-white/10"><div className="h-full rounded-full bg-gradient-to-r from-cyan-400 to-brand-500" style={{ width: `${pct}%` }} /></div>
          </div>
        )
      })}
      {!data?.length && <div className="card"><EmptyState icon={<Repeat />} title="Not in any sequence" description="Sequences schedule a multi-step follow-up plan in one click." /></div>}
    </div>
  )
}

function HistoryTab({ leadId }: { leadId: number }) {
  const { data } = useLeadHistoryQuery(leadId)
  return (
    <div className="card p-5">
      <ol className="space-y-4">
        {data?.map((h) => (
          <li key={h.id} className="flex items-center gap-3 text-sm">
            <span className="w-32 shrink-0 text-xs text-slate-500">{dateTime(h.created_at)}</span>
            {h.from_status ? <Badge color={h.from_status.color}>{h.from_status.name}</Badge> : <Badge>Start</Badge>}
            <ChevronRight className="size-4 text-slate-300" />
            {h.to_status && <Badge color={h.to_status.color} dot>{h.to_status.name}</Badge>}
            <span className="truncate text-xs text-slate-500">{h.user?.name}{h.note && ` — ${h.note}`}</span>
          </li>
        ))}
      </ol>
      {!data?.length && <EmptyState title="No stage changes yet" />}
      <p className="mt-6 flex items-center gap-1.5 text-xs text-slate-400"><Building2 className="size-3.5" /> Full field-level history is in the audit log.</p>
    </div>
  )
}
