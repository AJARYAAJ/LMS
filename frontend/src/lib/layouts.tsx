import type { ReactNode } from 'react'
import clsx from 'clsx'
import { Badge, DescriptionList, Field, Input, Select, Textarea } from '@/components/ui'
import { date, friendlyDue, fromLocalInput, humanize, money, toLocalInput } from '@/lib/format'
import type { CustomField, LayoutEntity, Meta, PageLayout } from '@/types'

type Option = { value: string | number; label: string }
type Kind = 'text' | 'email' | 'tel' | 'number' | 'date' | 'datetime' | 'textarea' | 'select' | 'tags'

interface FieldDef {
  label: string
  kind: Kind
  wide?: boolean
  options?: (meta: Meta, extra: LayoutExtras) => Option[]
  placeholder?: string
  money?: boolean
}

/** Lookup lists that are not part of meta (e.g. accounts for a contact). */
export interface LayoutExtras {
  accounts?: Option[]
  contacts?: Option[]
}

const users = (m: Meta) => m.users.filter((u) => u.role !== 'viewer').map((u) => ({ value: u.id, label: u.name }))
const SIZES = ['1-10', '11-50', '51-200', '201-1000', '1000+'].map((s) => ({ value: s, label: s }))
const TIMELINES = ['This month', 'This quarter', 'Next quarter', '6+ months'].map((s) => ({ value: s, label: s }))

/** Standard fields per entity. Custom fields ("custom.<key>") are derived from meta. */
export const FIELD_DEFS: Record<LayoutEntity, Record<string, FieldDef>> = {
  lead: {
    first_name: { label: 'First name', kind: 'text' },
    last_name: { label: 'Last name', kind: 'text' },
    job_title: { label: 'Job title', kind: 'text' },
    email: { label: 'Email', kind: 'email' },
    phone: { label: 'Phone', kind: 'tel' },
    company: { label: 'Company', kind: 'text' },
    website: { label: 'Website', kind: 'text', placeholder: 'acme.com' },
    industry: { label: 'Industry', kind: 'text' },
    company_size: { label: 'Company size', kind: 'select', options: () => SIZES },
    city: { label: 'City', kind: 'text' },
    state: { label: 'State / region', kind: 'text' },
    country: { label: 'Country', kind: 'text' },
    lead_status_id: { label: 'Status', kind: 'select', options: (m) => m.statuses.filter((s) => s.is_active).map((s) => ({ value: s.id, label: s.name })) },
    lead_source_id: { label: 'Source', kind: 'select', options: (m) => m.sources.map((s) => ({ value: s.id, label: s.name })) },
    campaign_id: { label: 'Campaign', kind: 'select', options: (m) => m.campaigns.map((c) => ({ value: c.id, label: c.name })) },
    owner_id: { label: 'Owner', kind: 'select', options: users },
    team_id: { label: 'Team', kind: 'select', options: (m) => m.teams.map((t) => ({ value: t.id, label: t.name })) },
    priority: { label: 'Priority', kind: 'select', options: (m) => m.enums.priorities.map((p) => ({ value: p, label: humanize(p) })) },
    budget: { label: 'Budget', kind: 'number', money: true },
    expected_value: { label: 'Expected value', kind: 'number', money: true },
    timeline: { label: 'Timeline', kind: 'select', options: () => TIMELINES },
    next_follow_up_at: { label: 'Next follow-up', kind: 'datetime' },
    requirements: { label: 'Requirements', kind: 'textarea', wide: true, placeholder: 'What does the lead need? Pain points, use-case, decision process…' },
    tag_ids: { label: 'Tags', kind: 'tags', wide: true },
  },
  contact: {
    first_name: { label: 'First name', kind: 'text' },
    last_name: { label: 'Last name', kind: 'text' },
    email: { label: 'Email', kind: 'email' },
    phone: { label: 'Phone', kind: 'tel' },
    job_title: { label: 'Job title', kind: 'text' },
    account_id: { label: 'Account', kind: 'select', options: (_m, x) => x.accounts ?? [] },
    owner_id: { label: 'Owner', kind: 'select', options: users },
  },
  account: {
    name: { label: 'Name', kind: 'text' },
    website: { label: 'Website', kind: 'text' },
    domain: { label: 'Domain', kind: 'text' },
    industry: { label: 'Industry', kind: 'text' },
    company_size: { label: 'Company size', kind: 'select', options: () => SIZES },
    phone: { label: 'Phone', kind: 'tel' },
    annual_revenue: { label: 'Annual revenue', kind: 'number', money: true },
    city: { label: 'City', kind: 'text' },
    country: { label: 'Country', kind: 'text' },
    owner_id: { label: 'Owner', kind: 'select', options: users },
    description: { label: 'Description', kind: 'textarea', wide: true },
  },
  deal: {
    name: { label: 'Deal name', kind: 'text', wide: true },
    amount: { label: 'Amount', kind: 'number', money: true },
    pipeline_stage_id: { label: 'Stage', kind: 'select', options: (m) => m.stages.map((s) => ({ value: s.id, label: `${s.name} · ${s.probability}%` })) },
    account_id: { label: 'Account', kind: 'select', options: (_m, x) => x.accounts ?? [] },
    contact_id: { label: 'Contact', kind: 'select', options: (_m, x) => x.contacts ?? [] },
    owner_id: { label: 'Owner', kind: 'select', options: users },
    expected_close_date: { label: 'Expected close', kind: 'date' },
    description: { label: 'Description', kind: 'textarea', wide: true },
  },
}

const CUSTOM_KIND: Record<CustomField['type'], Kind> = { text: 'text', textarea: 'textarea', number: 'number', date: 'date', select: 'select', boolean: 'select' }

/** Resolve a layout key (standard or "custom.<key>") to a field definition. */
export function fieldDef(entity: LayoutEntity, key: string, meta?: Meta): (FieldDef & { custom?: CustomField }) | null {
  if (key.startsWith('custom.')) {
    const cf = meta?.custom_fields.find((f) => f.entity === entity && f.key === key.slice(7))
    if (!cf) return null
    return {
      label: cf.label,
      kind: CUSTOM_KIND[cf.type],
      wide: cf.type === 'textarea',
      custom: cf,
      options: () => cf.type === 'boolean' ? [{ value: 'true', label: 'Yes' }, { value: 'false', label: 'No' }] : (cf.options ?? []).map((o) => ({ value: o, label: o })),
    }
  }
  return FIELD_DEFS[entity][key] ?? null
}

export function layoutFor(meta: Meta | undefined, entity: LayoutEntity): PageLayout {
  return meta?.layouts?.[entity] ?? { sections: [{ title: 'Details', fields: Object.keys(FIELD_DEFS[entity]) }], hidden: [], customized: false }
}

export type LayoutValues = Record<string, unknown> & { custom_fields?: Record<string, unknown> | null }

const read = (values: LayoutValues, key: string) => (key.startsWith('custom.') ? values.custom_fields?.[key.slice(7)] : values[key])

/**
 * Renders the organization's layout as form inputs. `onChange` receives
 * standard keys as-is and custom fields as "custom.<key>".
 */
export function LayoutFields({ entity, meta, values, onChange, errors = {}, omit = [], placeholders = {}, extras = {}, required = [] }: {
  entity: LayoutEntity
  meta?: Meta
  values: LayoutValues
  onChange: (key: string, value: unknown) => void
  errors?: Record<string, string>
  omit?: string[]
  placeholders?: Record<string, string>
  extras?: LayoutExtras
  required?: string[]
}) {
  if (!meta) return null
  const layout = layoutFor(meta, entity)

  return (
    <div className="space-y-6">
      {layout.sections.map((section) => {
        const fields = section.fields.filter((k) => !omit.includes(k) && fieldDef(entity, k, meta))
        if (!fields.length) return null
        return (
          <section key={section.title}>
            <h4 className="mb-3 text-xs font-semibold tracking-wider text-slate-400 uppercase">{section.title}</h4>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {fields.map((key) => {
                const def = fieldDef(entity, key, meta)!
                const value = read(values, key)
                const isRequired = required.includes(key) || !!def.custom?.is_required
                return (
                  <Field key={key} label={def.label} required={isRequired} error={errors[key]} className={clsx(def.wide && 'sm:col-span-2 lg:col-span-3')}>
                    <FieldInput entity={entity} fieldKey={key} def={def} value={value} meta={meta} extras={extras} placeholder={placeholders[key] ?? def.placeholder} required={isRequired} onChange={(v) => onChange(key, v)} />
                  </Field>
                )
              })}
            </div>
          </section>
        )
      })}
    </div>
  )
}

function FieldInput({ def, value, meta, extras, placeholder, required, onChange }: {
  entity: LayoutEntity
  fieldKey: string
  def: FieldDef & { custom?: CustomField }
  value: unknown
  meta: Meta
  extras: LayoutExtras
  placeholder?: string
  required: boolean
  onChange: (v: unknown) => void
}) {
  const str = value === null || value === undefined ? '' : String(value)

  switch (def.kind) {
    case 'select':
      return (
        <Select value={def.custom?.type === 'boolean' ? (value === true ? 'true' : value === false ? 'false' : '') : str}
          onChange={(e) => onChange(def.custom?.type === 'boolean' ? (e.target.value === '' ? null : e.target.value === 'true') : e.target.value)}
          placeholder={placeholder ?? '—'}>
          {def.options?.(meta, extras).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </Select>
      )
    case 'textarea':
      return <Textarea rows={3} value={str} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} />
    case 'datetime':
      return <Input type="datetime-local" value={toLocalInput(str || null)} onChange={(e) => onChange(fromLocalInput(e.target.value))} />
    case 'date':
      return <Input type="date" value={str.slice(0, 10)} onChange={(e) => onChange(e.target.value)} />
    case 'tags': {
      const ids = (value as number[] | undefined) ?? []
      return (
        <div className="flex flex-wrap gap-2">
          {meta.tags.map((t) => {
            const on = ids.includes(t.id)
            return (
              <button key={t.id} type="button" onClick={() => onChange(on ? ids.filter((x) => x !== t.id) : [...ids, t.id])}
                className="rounded-full border px-3 py-1 text-xs font-medium transition"
                style={on ? { backgroundColor: `${t.color}1f`, borderColor: t.color, color: t.color } : undefined}>
                {on ? '✓ ' : ''}{t.name}
              </button>
            )
          })}
          {!meta.tags.length && <span className="text-xs text-slate-400">No tags defined.</span>}
        </div>
      )
    }
    default:
      return <Input type={def.kind === 'number' ? 'number' : def.kind} min={def.kind === 'number' ? 0 : undefined} value={str} placeholder={placeholder} required={required} onChange={(e) => onChange(e.target.value)} />
  }
}

/** Renders the layout as read-only detail rows, one block per section. */
export function LayoutDetails({ entity, meta, record, currency = 'USD', skip = [], extras = {}, footer }: {
  entity: LayoutEntity
  meta?: Meta
  record: LayoutValues
  currency?: string
  skip?: string[]
  extras?: LayoutExtras
  footer?: { label: string; value: ReactNode }[]
}) {
  if (!meta) return null
  const layout = layoutFor(meta, entity)

  return (
    <div className="space-y-5">
      {layout.sections.map((section, i) => {
        const items = section.fields
          .filter((k) => !skip.includes(k))
          .map((k) => ({ key: k, def: fieldDef(entity, k, meta) }))
          .filter((x): x is { key: string; def: FieldDef & { custom?: CustomField } } => !!x.def && x.def.kind !== 'textarea')
          .map(({ key, def }) => ({ label: def.label, value: displayValue(def, read(record, key), meta, extras, currency) }))
        const notes = section.fields
          .filter((k) => !skip.includes(k) && fieldDef(entity, k, meta)?.kind === 'textarea' && read(record, k))
          .map((k) => ({ label: fieldDef(entity, k, meta)!.label, text: String(read(record, k)) }))
        if (!items.length && !notes.length) return null
        return (
          <div key={section.title + i}>
            {layout.sections.length > 1 && <p className="mb-1 text-[10px] font-semibold tracking-[0.14em] text-slate-400 uppercase">{section.title}</p>}
            {!!items.length && <DescriptionList items={items} />}
            {notes.map((n) => (
              <div key={n.label} className="mt-3 rounded-2xl bg-slate-900/[0.03] p-4 dark:bg-white/[0.03]">
                <p className="label">{n.label}</p>
                <p className="text-sm whitespace-pre-line text-slate-700 dark:text-slate-300">{n.text}</p>
              </div>
            ))}
          </div>
        )
      })}
      {!!footer?.length && <DescriptionList items={footer} />}
    </div>
  )
}

function displayValue(def: FieldDef & { custom?: CustomField }, value: unknown, meta: Meta, extras: LayoutExtras, currency: string): ReactNode {
  if (value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)) return ''
  if (def.custom?.type === 'boolean') return value === true ? 'Yes' : 'No'
  if (def.kind === 'tags') {
    const tags = meta.tags.filter((t) => (value as number[]).includes(t.id))
    return <span className="flex flex-wrap justify-end gap-1">{tags.map((t) => <Badge key={t.id} color={t.color}>{t.name}</Badge>)}</span>
  }
  if (def.kind === 'select' && def.options) {
    const opt = def.options(meta, extras).find((o) => String(o.value) === String(value))
    return opt?.label ?? String(value)
  }
  if (def.money) return money(value as string, currency)
  if (def.kind === 'datetime') return friendlyDue(String(value))
  if (def.kind === 'date') return date(String(value))
  return String(value)
}
