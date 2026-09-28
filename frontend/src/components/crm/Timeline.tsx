import clsx from 'clsx'
import { Calendar, CheckSquare, Mail, MessageCircle, MessageSquare, Phone, StickyNote, Trash2, Zap } from 'lucide-react'
import { ago, dateTime, humanize } from '@/lib/format'
import type { Activity } from '@/types'

const meta: Record<Activity['type'], { icon: typeof Phone; className: string }> = {
  call: { icon: Phone, className: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300' },
  email: { icon: Mail, className: 'bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300' },
  meeting: { icon: Calendar, className: 'bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300' },
  sms: { icon: MessageSquare, className: 'bg-teal-100 text-teal-600 dark:bg-teal-500/15 dark:text-teal-300' },
  whatsapp: { icon: MessageCircle, className: 'bg-green-100 text-green-600 dark:bg-green-500/15 dark:text-green-300' },
  note: { icon: StickyNote, className: 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300' },
  task: { icon: CheckSquare, className: 'bg-brand-100 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300' },
  system: { icon: Zap, className: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' },
}

export function ActivityIcon({ type, className }: { type: Activity['type']; className?: string }) {
  const m = meta[type] ?? meta.system
  return (
    <span className={clsx('flex size-8 shrink-0 items-center justify-center rounded-full ring-4 ring-white dark:ring-slate-900', m.className, className)}>
      <m.icon className="size-3.5" />
    </span>
  )
}

export function Timeline({ items, onDelete, showSubject }: { items: Activity[]; onDelete?: (a: Activity) => void; showSubject?: boolean }) {
  return (
    <ol className="relative">
      {items.map((a, i) => (
        <li key={a.id} className="group relative flex gap-3 pb-5">
          {i < items.length - 1 && <span className="absolute top-8 bottom-0 left-4 w-px bg-slate-200 dark:bg-slate-800" />}
          <ActivityIcon type={a.type} />
          <div className="min-w-0 flex-1 pt-1">
            <div className="flex flex-wrap items-baseline gap-x-2">
              <p className="text-sm font-medium text-slate-900 dark:text-slate-100">{a.title}</p>
              {showSubject && a.subject && <span className="text-xs text-brand-600">· {a.subject.name}</span>}
              <span className="text-xs text-slate-400" title={dateTime(a.occurred_at)}>{ago(a.occurred_at)}</span>
              {onDelete && a.type !== 'system' && (
                <button onClick={() => onDelete(a)} className="ml-auto text-slate-300 opacity-0 transition group-hover:opacity-100 hover:text-rose-500" aria-label="Delete activity">
                  <Trash2 className="size-3.5" />
                </button>
              )}
            </div>
            {a.description && <p className="mt-0.5 text-sm whitespace-pre-line text-slate-600 dark:text-slate-400">{a.description}</p>}
            <div className="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-500">
              {a.user && <span>by {a.user.name}</span>}
              {a.outcome && <span className="font-medium text-slate-600 dark:text-slate-300">Outcome: {a.outcome}</span>}
              {a.duration_minutes ? <span>{a.duration_minutes} min</span> : null}
              {a.direction && <span>{humanize(a.direction)}</span>}
            </div>
          </div>
        </li>
      ))}
    </ol>
  )
}
