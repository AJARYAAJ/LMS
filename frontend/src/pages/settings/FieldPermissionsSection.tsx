import { useEffect, useState } from 'react'
import clsx from 'clsx'
import { EyeOff, Lock, Pencil } from 'lucide-react'
import { useAction } from '@/app/hooks'
import { useFieldPermissionsQuery, useMetaQuery, useSaveFieldPermissionsMutation } from '@/services/api'
import { Button, Card, PageLoader, Segmented } from '@/components/ui'
import { SectionHeader } from '@/components/crm/ConditionBuilder'
import { fieldDef } from '@/lib/layouts'
import { ROLE_LABELS } from '@/lib/constants'
import type { FieldLevel, FieldPermissionSettings } from '@/types'

const LEVELS: { value: FieldLevel; label: string; icon: typeof Pencil }[] = [
  { value: 'edit', label: 'Edit', icon: Pencil },
  { value: 'read', label: 'Read only', icon: Lock },
  { value: 'hidden', label: 'Hidden', icon: EyeOff },
]

/** Role × field matrix: who can edit, only see, or not see each lead / deal field. */
export function FieldPermissionsSection() {
  const run = useAction()
  const { data: meta } = useMetaQuery()
  const { data } = useFieldPermissionsQuery()
  const [save, state] = useSaveFieldPermissionsMutation()
  const [entity, setEntity] = useState<'lead' | 'deal'>('lead')
  const [rules, setRules] = useState<FieldPermissionSettings['rules']>({})
  useEffect(() => { if (data) setRules(structuredClone(data.rules ?? {})) }, [data])

  if (!data || !meta) return <PageLoader />
  const level = (field: string, role: string): FieldLevel => (rules[entity]?.[field]?.[role as 'manager'] ?? 'edit')
  const set = (field: string, role: string, value: FieldLevel) => setRules((r) => ({ ...r, [entity]: { ...(r[entity] ?? {}), [field]: { ...(r[entity]?.[field] ?? {}), [role]: value } } }))
  const changed = JSON.stringify(rules) !== JSON.stringify(data.rules ?? {})

  return (
    <>
      <SectionHeader title="Field permissions" description="Decide which roles can edit, only see, or not see sensitive fields. Hidden fields are never sent to that role — not in lists, forms, exports or reports. Admins always see everything."
        action={<Button size="sm" disabled={!changed} loading={state.isLoading} onClick={() => run(save({ rules }), 'Field permissions saved')}>Save</Button>} />
      <div className="mb-4"><Segmented value={entity} onChange={setEntity} options={[{ value: 'lead', label: 'Leads' }, { value: 'deal', label: 'Deals' }]} /></div>
      <Card padded={false}>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px]">
            <thead><tr><th className="table-head">Field</th>{data.roles.map((r) => <th key={r} className="table-head">{ROLE_LABELS[r]}</th>)}</tr></thead>
            <tbody className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
              {data.fields[entity].filter((f) => f !== 'tag_ids').map((f) => (
                <tr key={f}>
                  <td className="table-cell font-medium">{fieldDef(entity, f, meta)?.label ?? f}</td>
                  {data.roles.map((role) => (
                    <td key={role} className="table-cell">
                      <div className="inline-flex rounded-lg bg-slate-900/[0.04] p-0.5 dark:bg-white/[0.05]" role="radiogroup" aria-label={`${fieldDef(entity, f, meta)?.label ?? f} for ${ROLE_LABELS[role]}`}>
                        {LEVELS.map((l) => (
                          <button key={l.value} type="button" role="radio" aria-checked={level(f, role) === l.value} onClick={() => set(f, role, l.value)} title={l.label}
                            className={clsx('flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium transition', level(f, role) === l.value
                              ? l.value === 'hidden' ? 'bg-rose-500/15 text-rose-700 dark:text-rose-300' : l.value === 'read' ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300' : 'bg-white text-slate-900 shadow-sm dark:bg-white/10 dark:text-white'
                              : 'text-slate-500 hover:text-slate-800')}>
                            <l.icon className="size-3" aria-hidden />{l.label}
                          </button>
                        ))}
                      </div>
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  )
}
