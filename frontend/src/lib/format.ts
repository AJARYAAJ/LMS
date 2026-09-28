import { format, formatDistanceToNowStrict, isToday, isTomorrow, isYesterday, parseISO } from 'date-fns'

export function toDate(value: string | Date | null | undefined): Date | null {
  if (!value) return null
  return typeof value === 'string' ? parseISO(value) : value
}

export function money(value: number | string | null | undefined, currency = 'USD', compact = false): string {
  const n = Number(value ?? 0)
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
    maximumFractionDigits: compact || n >= 1000 ? 0 : 2,
    notation: compact ? 'compact' : 'standard',
  }).format(n)
}

export function number(value: number | null | undefined, compact = false): string {
  return new Intl.NumberFormat(undefined, { notation: compact ? 'compact' : 'standard' }).format(value ?? 0)
}

export function ago(value: string | null | undefined): string {
  const d = toDate(value)
  return d ? formatDistanceToNowStrict(d, { addSuffix: true }) : '—'
}

export function date(value: string | null | undefined, pattern = 'MMM d, yyyy'): string {
  const d = toDate(value)
  return d ? format(d, pattern) : '—'
}

export function dateTime(value: string | null | undefined): string {
  return date(value, 'MMM d, yyyy · h:mm a')
}

export function friendlyDue(value: string | null | undefined): string {
  const d = toDate(value)
  if (!d) return 'No due date'
  const time = format(d, 'h:mm a')
  if (isToday(d)) return `Today, ${time}`
  if (isTomorrow(d)) return `Tomorrow, ${time}`
  if (isYesterday(d)) return `Yesterday, ${time}`
  return format(d, 'MMM d, h:mm a')
}

/** Value for <input type="datetime-local"> */
export function toLocalInput(value: string | null | undefined): string {
  const d = toDate(value)
  return d ? format(d, "yyyy-MM-dd'T'HH:mm") : ''
}

export function fromLocalInput(value: string): string | null {
  return value ? new Date(value).toISOString() : null
}

export function initials(name: string | null | undefined): string {
  return (name ?? '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join('')
}

export function humanize(value: string | null | undefined): string {
  if (!value) return ''
  return value.replace(/[_.]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

export function percent(value: number | null | undefined, digits = 1): string {
  return `${(value ?? 0).toFixed(digits)}%`
}
