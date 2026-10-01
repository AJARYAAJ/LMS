import { Link } from 'react-router-dom'
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { format, parseISO } from 'date-fns'
import clsx from 'clsx'
import {
  AlarmClock, ArrowRight, CalendarCheck, CheckCircle2, CircleDollarSign, Clock, Flame, Inbox, LayoutDashboard, Percent, Target, Trophy, Users,
} from 'lucide-react'
import { useAppSelector } from '@/app/hooks'
import { useActivityFeedQuery, useDashboardQuery, useGoalsQuery, useToggleTaskMutation } from '@/services/api'
import { Avatar, Card, EmptyState, PageHeader, ScoreRing, Skeleton, StatCard } from '@/components/ui'
import { StatusBadge } from '@/components/crm/Badges'
import { Timeline } from '@/components/crm/Timeline'
import { PinnedReports } from '@/components/reports/PinnedReports'
import { GoalsWidget } from '@/pages/reports/GoalsView'
import { ChartTooltip } from '@/components/crm/ChartTooltip'
import { friendlyDue, money, number, percent } from '@/lib/format'
import { RATING_META } from '@/lib/constants'
import type { Rating } from '@/types'

function greeting() {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening'
}

export function DashboardPage() {
  const user = useAppSelector((s) => s.auth.user)
  const currency = user?.organization?.currency ?? 'USD'
  const { data, isLoading } = useDashboardQuery(undefined, { pollingInterval: 60_000 })
  const { data: feed } = useActivityFeedQuery(12)
  const { data: goals } = useGoalsQuery({ mine: true })
  const [toggleTask] = useToggleTaskMutation()

  if (isLoading || !data) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-12 w-72" />
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-32" />)}</div>
        <div className="grid gap-4 lg:grid-cols-3"><Skeleton className="h-80 lg:col-span-2" /><Skeleton className="h-80" /></div>
      </div>
    )
  }

  const { kpis, my } = data
  const trend = data.trend.map((d) => ({ ...d, label: format(parseISO(d.date), 'MMM d') }))

  return (
    <div className="space-y-6">
      <PageHeader
        icon={<LayoutDashboard />}
        title={`${greeting()}, ${user?.name.split(' ')[0]} 👋`}
        description="Here's what's happening with your pipeline today."
      />

      {/* My day */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        {[
          { label: 'My open leads', value: my.open_leads, icon: Target, to: '/leads?owner_id=me', tone: 'text-brand-600 bg-brand-50 dark:bg-brand-500/10' },
          { label: 'Follow-ups today', value: my.follow_ups_today, icon: CalendarCheck, to: '/leads?owner_id=me&follow_up=today', tone: 'text-sky-600 bg-sky-50 dark:bg-sky-500/10' },
          { label: 'Overdue follow-ups', value: my.overdue_follow_ups, icon: AlarmClock, to: '/leads?owner_id=me&follow_up=overdue', tone: 'text-rose-600 bg-rose-50 dark:bg-rose-500/10' },
          { label: 'Tasks due today', value: my.tasks_today, icon: Clock, to: '/tasks?view=today', tone: 'text-amber-600 bg-amber-50 dark:bg-amber-500/10' },
          { label: 'Overdue tasks', value: my.overdue_tasks, icon: AlarmClock, to: '/tasks?view=overdue', tone: 'text-rose-600 bg-rose-50 dark:bg-rose-500/10' },
        ].map((item) => (
          <Link key={item.label} to={item.to} className="card group flex items-center gap-3 p-4 transition hover:-translate-y-0.5 hover:shadow-md">
            <span className={clsx('flex size-10 items-center justify-center rounded-xl', item.tone)}><item.icon className="size-5" /></span>
            <span className="min-w-0 flex-1">
              <span className="block text-xl font-semibold text-slate-900 dark:text-white">{item.value}</span>
              <span className="block text-xs leading-tight text-slate-500">{item.label}</span>
            </span>
            <ArrowRight className="size-4 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-brand-500" />
          </Link>
        ))}
      </div>

      {/* KPIs */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Total leads" value={number(kpis.total_leads)} icon={<Users />} delta={kpis.new_growth} hint={`${kpis.new_this_month} new this month`} />
        <StatCard label="Conversion rate" value={percent(kpis.conversion_rate)} icon={<Percent />} accent="#10b981" hint={`${kpis.converted} converted · ${kpis.qualified} qualified`} />
        <StatCard label="Open pipeline" value={money(kpis.pipeline_value, currency, true)} icon={<CircleDollarSign />} accent="#0ea5e9" hint={`${money(kpis.weighted_pipeline, currency, true)} weighted`} />
        <StatCard label="Won this month" value={money(kpis.won_this_month, currency, true)} icon={<Trophy />} accent="#f59e0b" hint={`Avg. lead age ${kpis.avg_lead_age_days}d`} />
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2" title="Lead flow" subtitle="New vs converted leads, last 30 days">
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={trend} margin={{ left: -20, right: 8, top: 8 }}>
                <defs>
                  <linearGradient id="gCreated" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#6366f1" stopOpacity={0.35} />
                    <stop offset="100%" stopColor="#6366f1" stopOpacity={0} />
                  </linearGradient>
                  <linearGradient id="gConverted" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#10b981" stopOpacity={0.35} />
                    <stop offset="100%" stopColor="#10b981" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="currentColor" className="text-slate-200 dark:text-slate-800" />
                <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={11} interval={4} stroke="#94a3b8" />
                <YAxis allowDecimals={false} tickLine={false} axisLine={false} fontSize={11} stroke="#94a3b8" />
                <Tooltip content={<ChartTooltip />} />
                <Area type="monotone" dataKey="created" name="New" stroke="#6366f1" strokeWidth={2.5} fill="url(#gCreated)" />
                <Area type="monotone" dataKey="converted" name="Converted" stroke="#10b981" strokeWidth={2.5} fill="url(#gConverted)" />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card title="Lead sources" subtitle="Where your leads come from">
          {data.by_source.length ? (
            <>
              <div className="relative h-48">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie data={data.by_source} dataKey="count" nameKey="name" innerRadius={58} outerRadius={82} paddingAngle={3} strokeWidth={0}>
                      {data.by_source.map((s) => <Cell key={s.id} fill={s.color} />)}
                    </Pie>
                    <Tooltip content={<ChartTooltip />} />
                  </PieChart>
                </ResponsiveContainer>
                <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                  <span className="text-2xl font-semibold text-slate-900 dark:text-white">{kpis.total_leads}</span>
                  <span className="text-xs text-slate-500">leads</span>
                </div>
              </div>
              <ul className="mt-4 space-y-2">
                {data.by_source.slice(0, 5).map((s) => (
                  <li key={s.id} className="flex items-center gap-2 text-sm">
                    <span className="size-2.5 rounded-full" style={{ backgroundColor: s.color }} />
                    <span className="flex-1 text-slate-600 dark:text-slate-400">{s.name}</span>
                    <span className="font-medium text-slate-900 dark:text-white">{s.count}</span>
                  </li>
                ))}
              </ul>
            </>
          ) : (
            <EmptyState title="No leads yet" description="Sources will appear once leads come in." />
          )}
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card title="Leads by stage" className="lg:col-span-2" action={<Link to="/leads?view=board" className="text-xs font-medium text-brand-600 hover:text-brand-700">Open board →</Link>}>
          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data.by_status} margin={{ left: -20, right: 8 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="currentColor" className="text-slate-200 dark:text-slate-800" />
                <XAxis dataKey="name" tickLine={false} axisLine={false} fontSize={11} stroke="#94a3b8" />
                <YAxis allowDecimals={false} tickLine={false} axisLine={false} fontSize={11} stroke="#94a3b8" />
                <Tooltip content={<ChartTooltip />} cursor={{ fill: 'rgba(99,102,241,0.06)' }} />
                <Bar dataKey="count" name="Leads" radius={[6, 6, 0, 0]} maxBarSize={44}>
                  {data.by_status.map((s) => <Cell key={s.id} fill={s.color} />)}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card title="Lead temperature" subtitle="Open leads by rating">
          <div className="space-y-4">
            {data.by_rating.map((r) => {
              const m = RATING_META[r.rating as Rating]
              const total = data.by_rating.reduce((s, x) => s + x.count, 0) || 1
              return (
                <div key={r.rating}>
                  <div className="mb-1.5 flex justify-between text-sm">
                    <span className="text-slate-600 dark:text-slate-400">{m.emoji} {m.label}</span>
                    <span className="font-medium text-slate-900 dark:text-white">{r.count}</span>
                  </div>
                  <div className="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div className="h-full rounded-full transition-all duration-700" style={{ width: `${(r.count / total) * 100}%`, backgroundColor: m.color }} />
                  </div>
                </div>
              )
            })}
            {kpis.unassigned !== null && (
              <Link to="/leads?owner_id=unassigned" className="mt-2 flex items-center justify-between rounded-xl bg-amber-50 px-3 py-2.5 text-sm text-amber-800 transition hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-300">
                <span className="flex items-center gap-2"><Inbox className="size-4" /> Unassigned queue</span>
                <span className="font-semibold">{kpis.unassigned}</span>
              </Link>
            )}
          </div>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card title={<span className="flex items-center gap-2"><Flame className="size-4 text-orange-500" /> Hottest leads</span>} padded={false}>
          {data.hot_leads.length ? (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {data.hot_leads.map((l) => (
                <li key={l.id}>
                  <Link to={`/leads/${l.id}`} className="flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                    <ScoreRing score={l.score} size={38} />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm font-medium text-slate-900 dark:text-white">{l.full_name}</span>
                      <span className="block truncate text-xs text-slate-500">{l.company ?? '—'}</span>
                    </span>
                    <StatusBadge status={l.status} />
                  </Link>
                </li>
              ))}
            </ul>
          ) : <EmptyState title="No open leads" />}
        </Card>

        <Card title="Upcoming tasks" padded={false} action={<Link to="/tasks" className="text-xs font-medium text-brand-600">View all →</Link>}>
          {data.upcoming_tasks.length ? (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {data.upcoming_tasks.map((t) => (
                <li key={t.id} className="flex items-start gap-3 px-5 py-3">
                  <button onClick={() => toggleTask(t.id)} className="mt-0.5 text-slate-300 transition hover:text-emerald-500" aria-label="Complete task">
                    <CheckCircle2 className="size-5" />
                  </button>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-slate-900 dark:text-white">{t.title}</p>
                    <p className={clsx('text-xs', t.is_overdue ? 'font-medium text-rose-600' : 'text-slate-500')}>
                      {friendlyDue(t.due_at)}
                      {t.taskable && <> · <Link to={`/${t.taskable.type}s/${t.taskable.id}`} className="text-brand-600 hover:underline">{t.taskable.name}</Link></>}
                    </p>
                  </div>
                </li>
              ))}
            </ul>
          ) : <EmptyState icon={<CheckCircle2 />} title="Nothing due" description="You're all caught up." />}
        </Card>

        <Card title="Recent activity">
          {feed?.length ? <Timeline items={feed.slice(0, 7)} showSubject /> : <EmptyState title="No activity yet" />}
        </Card>
      </div>

      {goals?.length ? (
        <div className="grid gap-6 lg:grid-cols-3">
          <div className="lg:col-span-2"><ActivityHeatmap days={data.heatmap} /></div>
          <GoalsWidget />
        </div>
      ) : <ActivityHeatmap days={data.heatmap} />}

      <PinnedReports />

      <p className="flex items-center justify-center gap-2 pt-2 text-xs text-slate-400">
        <Avatar name={user?.organization?.name} size="xs" /> {user?.organization?.name} · data refreshes every minute
      </p>
    </div>
  )
}

function ActivityHeatmap({ days }: { days: { date: string; count: number }[] }) {
  const max = Math.max(1, ...days.map((d) => d.count))
  const weeks: { date: string; count: number }[][] = []
  days.forEach((d, i) => { if (i % 7 === 0) weeks.push([]); weeks[weeks.length - 1].push(d) })
  const total = days.reduce((s, d) => s + d.count, 0)
  const level = (c: number) => (c === 0 ? 0 : Math.ceil((c / max) * 4))
  const shades = ['bg-slate-200/70 dark:bg-white/[0.05]', 'bg-violet-200 dark:bg-violet-900', 'bg-violet-400 dark:bg-violet-700', 'bg-fuchsia-500 dark:bg-fuchsia-500', 'bg-gradient-to-br from-fuchsia-500 to-cyan-400 shadow-[0_0_8px_rgba(217,70,239,0.7)]']

  return (
    <Card title="Engagement heatmap" subtitle={`${total} calls, emails and meetings in the last 12 weeks`}>
      <div className="flex gap-1 overflow-x-auto pb-1">
        {weeks.map((w, i) => (
          <div key={i} className="flex flex-col gap-1">
            {w.map((d) => <div key={d.date} title={`${d.date}: ${d.count}`} className={clsx('size-3.5 rounded-[4px] transition hover:scale-125', shades[level(d.count)])} />)}
          </div>
        ))}
      </div>
      <div className="mt-3 flex items-center justify-end gap-1 text-[11px] text-slate-400">Less {shades.map((s, i) => <span key={i} className={clsx('size-3 rounded-[3px]', s)} />)} More</div>
    </Card>
  )
}
