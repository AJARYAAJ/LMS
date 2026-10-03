import type { ReactNode } from 'react'
import clsx from 'clsx'
import { ChevronLeft, ChevronRight, Inbox, TrendingDown, TrendingUp } from 'lucide-react'
import { initials } from '@/lib/format'
import type { Paginated } from '@/types'

export function Card({ children, className, title, action, padded = true, subtitle }: {
  children: ReactNode
  className?: string
  title?: ReactNode
  subtitle?: ReactNode
  action?: ReactNode
  padded?: boolean
}) {
  return (
    <section className={clsx('card', className)}>
      {(title || action) && (
        <header className="flex items-center justify-between gap-3 px-5 pt-4 pb-1">
          <div className="min-w-0">
            {title && <h3 className="truncate text-[15px] font-semibold text-slate-900 dark:text-white">{title}</h3>}
            {subtitle && <p className="truncate text-xs text-slate-500">{subtitle}</p>}
          </div>
          {action}
        </header>
      )}
      <div className={clsx(padded && 'p-5')}>{children}</div>
    </section>
  )
}

export function Badge({ children, color, className, dot }: { children: ReactNode; color?: string | null; className?: string; dot?: boolean }) {
  if (color) {
    return (
      <span
        className={clsx('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap', className)}
        style={{ backgroundColor: `${color}1a`, color }}
      >
        {dot && <span className="size-1.5 rounded-full" style={{ backgroundColor: color }} />}
        {children}
      </span>
    )
  }
  return (
    <span className={clsx('inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-slate-600 dark:bg-slate-800 dark:text-slate-300', className)}>
      {children}
    </span>
  )
}

export function Avatar({ name, color, size = 'md', className }: { name?: string | null; color?: string | null; size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl'; className?: string }) {
  const sizes = { xs: 'size-5 text-[9px]', sm: 'size-7 text-[11px]', md: 'size-9 text-xs', lg: 'size-12 text-base', xl: 'size-16 text-xl' }
  return (
    <span
      title={name ?? undefined}
      className={clsx('inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white ring-2 ring-white dark:ring-slate-900', sizes[size], className)}
      style={{ background: `linear-gradient(135deg, ${color ?? '#6366f1'}, ${color ?? '#6366f1'}cc)` }}
    >
      {initials(name)}
    </span>
  )
}

export function Spinner({ className }: { className?: string }) {
  return <span className={clsx('inline-block size-5 animate-spin rounded-full border-2 border-slate-300 border-t-brand-600', className)} />
}

export function PageLoader() {
  return (
    <div className="flex h-64 items-center justify-center">
      <Spinner className="size-7" />
    </div>
  )
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={clsx('shimmer rounded-2xl bg-slate-200/50 dark:bg-white/[0.04]', className)} />
}

export function EmptyState({ icon, title, description, action, className }: {
  icon?: ReactNode
  title: string
  description?: ReactNode
  action?: ReactNode
  className?: string
}) {
  return (
    <div className={clsx('flex flex-col items-center justify-center px-6 py-14 text-center', className)}>
      <div className="mb-4 flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-50 to-violet-50 text-brand-500 ring-1 ring-brand-100 dark:from-brand-500/10 dark:to-violet-500/10 dark:ring-brand-500/20 [&>svg]:size-6">
        {icon ?? <Inbox />}
      </div>
      <h3 className="text-sm font-semibold text-slate-900 dark:text-white">{title}</h3>
      {description && <p className="mt-1 max-w-sm text-sm text-slate-500">{description}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  )
}

export function PageHeader({ title, description, actions, icon }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; icon?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div className="flex items-center gap-3">
        {icon && (
          <div className="relative hidden size-12 items-center justify-center sm:flex">
            <span className="absolute inset-0 animate-spin-slow rounded-2xl bg-[conic-gradient(from_0deg,#8b5cf6,#d946ef,#06b6d4,#8b5cf6)] p-0.5" />
            <span className="relative m-0.5 flex size-11 items-center justify-center rounded-[14px] bg-white text-brand-600 dark:bg-ink-900 dark:text-brand-300 [&>svg]:size-5">{icon}</span>
          </div>
        )}
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-[28px] dark:text-white">{title}</h1>
          {description && <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{description}</p>}
        </div>
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

export function StatCard({ label, value, icon, delta, hint, accent = '#6366f1' }: {
  label: string
  value: ReactNode
  icon?: ReactNode
  delta?: number | null
  hint?: ReactNode
  accent?: string
}) {
  return (
    <div className="card group overflow-hidden p-5">
      <div className="absolute -top-16 -right-16 -z-10 size-48 rounded-full opacity-40 transition-transform duration-700 group-hover:scale-125" style={{ background: `radial-gradient(circle, ${accent}55 0%, transparent 65%)` }} />
      <div className="flex items-start justify-between">
        <p className="text-[11px] font-semibold tracking-[0.08em] text-slate-500 uppercase dark:text-slate-400">{label}</p>
        {icon && (
          <span className="flex size-9 items-center justify-center rounded-xl shadow-sm [&>svg]:size-4" style={{ background: `linear-gradient(135deg, ${accent}33, ${accent}14)`, color: accent }}>
            {icon}
          </span>
        )}
      </div>
      <p className="font-display mt-3 text-[28px] leading-none font-bold tracking-tight text-slate-900 dark:text-white">{value}</p>
      <div className="mt-1 flex items-center gap-2 text-xs">
        {delta !== undefined && delta !== null && (
          <span className={clsx('inline-flex items-center gap-0.5 font-medium', delta >= 0 ? 'text-emerald-600' : 'text-rose-600')}>
            {delta >= 0 ? <TrendingUp className="size-3.5" /> : <TrendingDown className="size-3.5" />}
            {Math.abs(delta)}%
          </span>
        )}
        {hint && <span className="text-slate-500">{hint}</span>}
      </div>
    </div>
  )
}

export function Tabs<T extends string>({ tabs, value, onChange, className }: {
  tabs: { value: T; label: ReactNode; count?: number; icon?: ReactNode }[]
  value: T
  onChange: (value: T) => void
  className?: string
}) {
  return (
    <div className={clsx('flex gap-1 overflow-x-auto rounded-2xl bg-slate-900/[0.04] p-1 dark:bg-white/[0.04]', className)}>
      {tabs.map((t) => (
        <button
          key={t.value}
          type="button"
          onClick={() => onChange(t.value)}
          className={clsx(
            'flex items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-medium whitespace-nowrap transition-all duration-300 [&>svg]:size-4',
            value === t.value
              ? 'bg-white text-slate-900 shadow-[0_4px_16px_-6px_rgba(76,29,149,0.35)] dark:bg-white/10 dark:text-white'
              : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200',
          )}
        >
          {t.icon}
          {t.label}
          {t.count !== undefined && (
            <span className={clsx('rounded-full px-1.5 py-0.5 text-[10px] font-semibold', value === t.value ? 'bg-brand-100 text-brand-700 dark:bg-brand-500/20 dark:text-brand-200' : 'bg-slate-100 text-slate-500 dark:bg-slate-800')}>
              {t.count}
            </span>
          )}
        </button>
      ))}
    </div>
  )
}

export function Segmented<T extends string>({ options, value, onChange }: {
  options: { value: T; label: ReactNode; icon?: ReactNode }[]
  value: T
  onChange: (value: T) => void
}) {
  return (
    <div className="inline-flex rounded-xl bg-slate-900/[0.05] p-1 dark:bg-white/[0.05]">
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          onClick={() => onChange(o.value)}
          className={clsx(
            'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-all duration-300 [&>svg]:size-3.5',
            value === o.value ? 'bg-white text-slate-900 shadow-[0_4px_12px_-4px_rgba(76,29,149,0.35)] dark:bg-white/10 dark:text-white' : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200',
          )}
        >
          {o.icon}
          {o.label}
        </button>
      ))}
    </div>
  )
}

export function Pagination({ meta, onPage }: { meta: Pick<Paginated<unknown>, 'current_page' | 'last_page' | 'total' | 'from' | 'to'>; onPage: (page: number) => void }) {
  if (meta.total === 0) return null
  return (
    <div className="flex items-center justify-between gap-4 border-t border-slate-200/60 px-4 py-3 text-sm dark:border-white/[0.06]">
      <p className="text-slate-500">
        <span className="font-medium text-slate-700 dark:text-slate-300">{meta.from}–{meta.to}</span> of{' '}
        <span className="font-medium text-slate-700 dark:text-slate-300">{meta.total}</span>
      </p>
      <div className="flex items-center gap-1">
        <button
          disabled={meta.current_page <= 1}
          onClick={() => onPage(meta.current_page - 1)}
          className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 disabled:opacity-40 dark:hover:bg-slate-800"
          aria-label="Previous page"
        >
          <ChevronLeft className="size-4" />
        </button>
        <span className="px-2 text-xs text-slate-500">
          Page {meta.current_page} / {meta.last_page}
        </span>
        <button
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
          className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 disabled:opacity-40 dark:hover:bg-slate-800"
          aria-label="Next page"
        >
          <ChevronRight className="size-4" />
        </button>
      </div>
    </div>
  )
}

export function ScoreRing({ score, size = 44 }: { score: number; size?: number }) {
  const r = (size - 6) / 2
  const c = 2 * Math.PI * r
  const color = score > 80 ? '#ef4444' : score > 60 ? '#f97316' : score > 30 ? '#f59e0b' : '#94a3b8'
  return (
    <div className="relative inline-flex items-center justify-center" style={{ width: size, height: size }}>
      <span className="absolute inset-1 rounded-full opacity-40 blur-md" style={{ backgroundColor: color }} />
      <svg width={size} height={size} className="relative -rotate-90">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" strokeWidth="4" className="stroke-slate-200/80 dark:stroke-white/10" />
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" strokeWidth="4" stroke={color} strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c - (c * Math.min(score, 100)) / 100} className="transition-all duration-700" />
      </svg>
      <span className="font-display absolute text-xs font-bold text-slate-800 dark:text-slate-100">{score}</span>
    </div>
  )
}

export function DescriptionList({ items }: { items: { label: string; value: ReactNode }[] }) {
  return (
    <dl className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
      {items.map((i) => (
        <div key={i.label} className="flex items-start justify-between gap-4 py-2.5 text-sm">
          <dt className="shrink-0 text-slate-500">{i.label}</dt>
          <dd className="min-w-0 text-right font-medium break-words text-slate-800 dark:text-slate-200">{i.value || <span className="font-normal text-slate-400">—</span>}</dd>
        </div>
      ))}
    </dl>
  )
}
