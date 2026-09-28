import clsx from 'clsx'
import { Flame } from 'lucide-react'
import { Avatar, Badge } from '@/components/ui'
import { PRIORITY_STYLES, RATING_META } from '@/lib/constants'
import type { LeadStatus, Priority, Rating, UserLite } from '@/types'

export function StatusBadge({ status }: { status?: Pick<LeadStatus, 'name' | 'color'> | null }) {
  if (!status) return <span className="text-xs text-slate-400">—</span>
  return <Badge color={status.color} dot>{status.name}</Badge>
}

export function PriorityBadge({ priority }: { priority: Priority }) {
  return <span className={clsx('inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize', PRIORITY_STYLES[priority])}>{priority}</span>
}

export function RatingBadge({ rating, compact }: { rating: Rating; compact?: boolean }) {
  const meta = RATING_META[rating] ?? RATING_META.cold
  return (
    <span className="inline-flex items-center gap-1 text-xs font-medium" style={{ color: meta.color }}>
      {rating === 'hot' || rating === 'very_high' ? <Flame className="size-3.5" /> : <span className="size-1.5 rounded-full" style={{ backgroundColor: meta.color }} />}
      {!compact && meta.label}
    </span>
  )
}

export function Owner({ user, showName = true }: { user?: UserLite | null; showName?: boolean }) {
  if (!user) return <span className="text-xs text-slate-400 italic">Unassigned</span>
  return (
    <span className="inline-flex min-w-0 items-center gap-2">
      <Avatar name={user.name} color={user.avatar_color} size="xs" />
      {showName && <span className="truncate text-sm text-slate-700 dark:text-slate-300">{user.name}</span>}
    </span>
  )
}

export function ScoreBar({ score }: { score: number }) {
  const color = score > 80 ? 'bg-rose-500' : score > 60 ? 'bg-orange-500' : score > 30 ? 'bg-amber-400' : 'bg-slate-400'
  return (
    <div className="flex items-center gap-2">
      <div className="h-1.5 w-14 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
        <div className={clsx('h-full rounded-full', color)} style={{ width: `${Math.min(score, 100)}%` }} />
      </div>
      <span className="w-6 text-xs font-semibold text-slate-700 tabular-nums dark:text-slate-300">{score}</span>
    </div>
  )
}
