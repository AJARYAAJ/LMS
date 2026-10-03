import type { Priority, Rating } from '@/types'

export const PRIORITY_STYLES: Record<Priority, string> = {
  low: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
  medium: 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
  high: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
  urgent: 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
}

export const RATING_META: Record<Rating, { label: string; color: string; emoji: string }> = {
  cold: { label: 'Cold', color: '#64748b', emoji: '❄️' },
  warm: { label: 'Warm', color: '#f59e0b', emoji: '🌤️' },
  hot: { label: 'Hot', color: '#f97316', emoji: '🔥' },
  very_high: { label: 'Very high intent', color: '#ef4444', emoji: '🚀' },
}

export const ROLE_LABELS: Record<string, string> = {
  admin: 'Admin',
  manager: 'Manager',
  sales_rep: 'Sales rep',
  viewer: 'Read-only',
}

export const OPERATOR_LABELS: Record<string, string> = {
  equals: 'equals',
  not_equals: 'does not equal',
  contains: 'contains',
  not_contains: 'does not contain',
  starts_with: 'starts with',
  ends_with: 'ends with',
  greater_than: 'is greater than',
  less_than: 'is less than',
  is_empty: 'is empty',
  is_not_empty: 'is not empty',
  in: 'is one of (comma separated)',
  business_email: 'is a business email',
}

export const VALUELESS_OPERATORS = ['is_empty', 'is_not_empty', 'business_email']

export const COLOR_SWATCHES = ['#6366f1', '#8b5cf6', '#ec4899', '#ef4444', '#f97316', '#f59e0b', '#10b981', '#14b8a6', '#0ea5e9', '#64748b']

export const FORECAST_LABEL: Record<string, string> = {
  pipeline: 'Pipeline', best_case: 'Best case', commit: 'Commit', closed: 'Closed won', omitted: 'Omitted',
}
