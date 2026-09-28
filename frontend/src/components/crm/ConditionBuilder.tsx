import type { ReactNode } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { useMetaQuery } from '@/services/api'
import { Button, Input, Select } from '@/components/ui'
import { OPERATOR_LABELS, VALUELESS_OPERATORS } from '@/lib/constants'
import { humanize } from '@/lib/format'
import type { Condition } from '@/types'

export function SectionHeader({ title, description, action }: { title: string; description?: ReactNode; action?: ReactNode }) {
  return (
    <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h2 className="text-xl font-bold text-slate-900 dark:text-white">{title}</h2>
        {description && <p className="mt-0.5 max-w-2xl text-sm text-slate-500">{description}</p>}
      </div>
      {action}
    </div>
  )
}

/** WHEN field OPERATOR value … builder shared by assignment, scoring and automation rules. */
export function ConditionBuilder({ value, onChange, emptyLabel = 'Applies to every lead.' }: { value: Condition[]; onChange: (v: Condition[]) => void; emptyLabel?: string }) {
  const { data: meta } = useMetaQuery()
  const fields = meta?.enums.condition_fields ?? []
  const operators = meta?.enums.operators ?? []
  const patch = (i: number, p: Partial<Condition>) => onChange(value.map((c, j) => (j === i ? { ...c, ...p } : c)))

  return (
    <div className="space-y-2">
      {!value.length && <p className="rounded-2xl border border-dashed border-slate-300 px-4 py-3 text-sm text-slate-500 dark:border-white/10">{emptyLabel}</p>}
      {value.map((c, i) => (
        <div key={i} className="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200/80 bg-white/60 p-2 dark:border-white/10 dark:bg-white/[0.03]">
          <span className="w-10 text-center text-[11px] font-bold tracking-wider text-brand-600 uppercase">{i === 0 ? 'If' : 'And'}</span>
          <Select className="w-44" value={c.field} onChange={(e) => patch(i, { field: e.target.value })}>
            {fields.map((f) => <option key={f} value={f}>{humanize(f)}</option>)}
          </Select>
          <Select className="w-52" value={c.operator} onChange={(e) => patch(i, { operator: e.target.value })}>
            {operators.map((o) => <option key={o} value={o}>{OPERATOR_LABELS[o] ?? o}</option>)}
          </Select>
          {!VALUELESS_OPERATORS.includes(c.operator) && (
            <ConditionValue field={c.field} value={c.value ?? ''} onChange={(v) => patch(i, { value: v })} />
          )}
          <button onClick={() => onChange(value.filter((_, j) => j !== i))} className="ml-auto rounded-lg p-2 text-slate-400 hover:text-rose-600" aria-label="Remove condition"><Trash2 className="size-4" /></button>
        </div>
      ))}
      <Button size="xs" variant="subtle" icon={<Plus className="size-3.5" />} onClick={() => onChange([...value, { field: fields[0] ?? 'country', operator: 'equals', value: '' }])}>Add condition</Button>
    </div>
  )
}

function ConditionValue({ field, value, onChange }: { field: string; value: string; onChange: (v: string) => void }) {
  const { data: meta } = useMetaQuery()
  const options: Record<string, { value: string | number; label: string }[] | undefined> = {
    lead_status_id: meta?.statuses.map((s) => ({ value: s.id, label: s.name })),
    status_key: meta?.statuses.map((s) => ({ value: s.key, label: s.name })),
    status_category: meta?.enums.status_categories.map((c) => ({ value: c, label: humanize(c) })),
    lead_source_id: meta?.sources.map((s) => ({ value: s.id, label: s.name })),
    source_key: meta?.sources.map((s) => ({ value: s.key, label: s.name })),
    campaign_id: meta?.campaigns.map((c) => ({ value: c.id, label: c.name })),
    owner_id: meta?.users.map((u) => ({ value: u.id, label: u.name })),
    team_id: meta?.teams.map((t) => ({ value: t.id, label: t.name })),
    priority: meta?.enums.priorities.map((p) => ({ value: p, label: humanize(p) })),
    rating: meta?.enums.ratings.map((r) => ({ value: r, label: humanize(r) })),
    tags: meta?.tags.map((t) => ({ value: t.name, label: t.name })),
  }
  const list = options[field]
  if (list) {
    return (
      <Select className="min-w-40 flex-1" value={value} onChange={(e) => onChange(e.target.value)} placeholder="Choose…">
        {list.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </Select>
    )
  }
  return <Input className="min-w-40 flex-1" value={value} onChange={(e) => onChange(e.target.value)} placeholder="Value" />
}
