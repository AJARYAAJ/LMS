import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Building2, Globe, Pencil, Phone, Plus, Search, Trash2, Users } from 'lucide-react'
import { useAction, useAppSelector, usePermissions } from '@/app/hooks'
import { useAccountQuery, useAccountsQuery, useDeleteAccountMutation, useMetaQuery, useSaveAccountMutation } from '@/services/api'
import { Avatar, Badge, Button, Card, ConfirmDialog, DescriptionList, EmptyState, Field, Input, Modal, PageHeader, PageLoader, Pagination, Select, Textarea } from '@/components/ui'
import { CustomFieldInputs, RecordPanels, useCustomFieldRows } from '@/components/crm/RecordPanels'
import { ActivityModal } from '@/components/crm/ActivityModal'
import { date, money } from '@/lib/format'
import type { Account } from '@/types'

function AccountFormModal({ open, onClose, account }: { open: boolean; onClose: () => void; account?: Account | null }) {
  const run = useAction()
  const { data: meta } = useMetaQuery()
  const [save, { isLoading }] = useSaveAccountMutation()
  const [form, setForm] = useState<Record<string, string>>({})
  const [custom, setCustom] = useState<Record<string, unknown>>({})
  const keys = ['name', 'domain', 'industry', 'company_size', 'phone', 'website', 'city', 'country', 'annual_revenue', 'owner_id', 'description'] as const

  useEffect(() => { if (open) { setForm(Object.fromEntries(keys.map((k) => [k, String(account?.[k] ?? '')]))); setCustom(account?.custom_fields ?? {}) } }, [open, account]) // eslint-disable-line react-hooks/exhaustive-deps

  const set = (k: string) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))
  const submit = async () => {
    const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : k === 'owner_id' || k === 'annual_revenue' ? Number(v) : v]))
    if (await run(save({ ...(account ? { id: account.id } : {}), ...body, custom_fields: custom } as Partial<Account>), account ? 'Account updated' : 'Account created')) onClose()
  }

  return (
    <Modal open={open} onClose={onClose} size="lg" title={account ? 'Edit account' : 'New account'} footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} disabled={!form.name} loading={isLoading}>Save</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Name" required className="sm:col-span-2"><Input autoFocus value={form.name ?? ''} onChange={set('name')} /></Field>
        <Field label="Website"><Input value={form.website ?? ''} onChange={set('website')} /></Field>
        <Field label="Domain"><Input value={form.domain ?? ''} onChange={set('domain')} /></Field>
        <Field label="Industry"><Input value={form.industry ?? ''} onChange={set('industry')} /></Field>
        <Field label="Company size"><Input value={form.company_size ?? ''} onChange={set('company_size')} /></Field>
        <Field label="Phone"><Input value={form.phone ?? ''} onChange={set('phone')} /></Field>
        <Field label="Annual revenue"><Input type="number" value={form.annual_revenue ?? ''} onChange={set('annual_revenue')} /></Field>
        <Field label="City"><Input value={form.city ?? ''} onChange={set('city')} /></Field>
        <Field label="Country"><Input value={form.country ?? ''} onChange={set('country')} /></Field>
        <Field label="Owner" className="sm:col-span-2"><Select value={form.owner_id ?? ''} onChange={set('owner_id')} placeholder="—">{meta?.users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</Select></Field>
        <CustomFieldInputs entity="account" value={custom} onChange={setCustom} />
        <Field label="Description" className="sm:col-span-2"><Textarea value={form.description ?? ''} onChange={set('description')} /></Field>
      </div>
    </Modal>
  )
}

export function AccountsPage() {
  const navigate = useNavigate()
  const { write } = usePermissions()
  const currency = useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const { data, isLoading } = useAccountsQuery({ search, page })

  return (
    <div>
      <PageHeader icon={<Building2 />} title="Accounts" description="Companies and their people, deals and pipeline."
        actions={write && <Button size="sm" icon={<Plus className="size-4" />} onClick={() => setShowForm(true)}>New account</Button>} />
      <div className="card mb-5 p-3"><Input icon={<Search className="size-4" />} placeholder="Search accounts…" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} /></div>
      {isLoading ? <PageLoader /> : (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px]">
              <thead><tr>{['Account', 'Industry', 'Contacts', 'Deals', 'Open pipeline', 'Owner'].map((h) => <th key={h} className="table-head">{h}</th>)}</tr></thead>
              <tbody className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
                {data?.data.map((a) => (
                  <tr key={a.id} onClick={() => navigate(`/accounts/${a.id}`)} className="cursor-pointer transition hover:bg-brand-50/40 dark:hover:bg-white/[0.03]">
                    <td className="table-cell"><div className="flex items-center gap-3"><span className="flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-100 to-cyan-100 font-display font-bold text-brand-700 dark:from-brand-500/20 dark:to-cyan-500/20 dark:text-brand-200">{a.name[0]}</span><div><p className="font-medium text-slate-900 dark:text-white">{a.name}</p><p className="text-xs text-slate-500">{[a.city, a.country].filter(Boolean).join(', ')}</p></div></div></td>
                    <td className="table-cell">{a.industry ?? '—'}</td>
                    <td className="table-cell">{a.contacts_count}</td>
                    <td className="table-cell">{a.deals_count}</td>
                    <td className="table-cell font-display font-semibold">{money(a.open_pipeline ?? 0, currency, true)}</td>
                    <td className="table-cell">{a.owner && <span className="flex items-center gap-2"><Avatar name={a.owner.name} color={a.owner.avatar_color} size="xs" />{a.owner.name}</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {data && !data.data.length && <EmptyState icon={<Building2 />} title="No accounts" />}
          {data && <Pagination meta={data} onPage={setPage} />}
        </div>
      )}
      <AccountFormModal open={showForm} onClose={() => setShowForm(false)} />
    </div>
  )
}

export function AccountDetailPage() {
  const id = Number(useParams().id)
  const navigate = useNavigate()
  const run = useAction()
  const { write, manager } = usePermissions()
  const currency = useAppSelector((s) => s.auth.user?.organization?.currency ?? 'USD')
  const { data: a, isLoading } = useAccountQuery(id)
  const customRows = useCustomFieldRows('account', a?.custom_fields)
  const [remove] = useDeleteAccountMutation()
  const [modal, setModal] = useState<null | 'edit' | 'activity' | 'delete'>(null)

  if (isLoading) return <PageLoader />
  if (!a) return <EmptyState title="Account not found" />

  return (
    <div className="space-y-6">
      <Link to="/accounts" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand-600"><ArrowLeft className="size-4" /> Accounts</Link>
      <section className="card flex flex-wrap items-center gap-5 p-6">
        <span className="flex size-16 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 via-fuchsia-500 to-cyan-400 font-display text-2xl font-bold text-white shadow-lg shadow-fuchsia-500/30">{a.name[0]}</span>
        <div className="min-w-0 flex-1">
          <h1 className="text-3xl font-bold text-slate-900 dark:text-white">{a.name}</h1>
          <div className="mt-2 flex flex-wrap gap-2">
            {a.industry && <span className="chip">{a.industry}</span>}
            {a.website && <a className="chip" href={a.website.startsWith('http') ? a.website : `https://${a.website}`} target="_blank" rel="noreferrer"><Globe className="size-3.5" />{a.website}</a>}
            {a.phone && <span className="chip"><Phone className="size-3.5" />{a.phone}</span>}
          </div>
        </div>
        <div className="text-right"><p className="text-xs tracking-wider text-slate-500 uppercase">Open pipeline</p><p className="font-display text-3xl font-bold gradient-text">{money(a.open_pipeline ?? 0, currency)}</p></div>
        {write && <div className="flex w-full gap-2">
          <Button size="sm" variant="secondary" onClick={() => setModal('activity')}>Log activity</Button>
          <Button size="sm" variant="secondary" icon={<Pencil className="size-4" />} onClick={() => setModal('edit')}>Edit</Button>
          {manager && <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" />} onClick={() => setModal('delete')}>Delete</Button>}
        </div>}
      </section>
      <div className="grid gap-6 lg:grid-cols-3">
        <Card title={<span className="flex items-center gap-2"><Users className="size-4" /> People</span>}>
          {a.contacts?.map((c) => (
            <Link key={c.id} to={`/contacts/${c.id}`} className="flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-brand-50 dark:hover:bg-white/5">
              <Avatar name={`${c.first_name} ${c.last_name ?? ''}`} size="sm" />
              <span className="min-w-0"><span className="block truncate text-sm font-medium">{c.first_name} {c.last_name}</span><span className="block truncate text-xs text-slate-500">{c.job_title}</span></span>
            </Link>
          ))}
          {!a.contacts?.length && <p className="text-sm text-slate-500">No contacts.</p>}
        </Card>
        <Card title="Deals">
          {a.deals?.map((d) => (
            <Link key={d.id} to={`/deals/${d.id}`} className="flex items-center justify-between gap-2 rounded-xl px-2 py-2 text-sm hover:bg-brand-50 dark:hover:bg-white/5">
              <span className="truncate">{d.name}</span><span className="flex items-center gap-2"><span className="font-display font-semibold">{money(d.amount, currency, true)}</span>{d.stage && <Badge color={d.stage.color}>{d.stage.name}</Badge>}</span>
            </Link>
          ))}
          {!a.deals?.length && <p className="text-sm text-slate-500">No deals.</p>}
        </Card>
        <Card title="About">
          <DescriptionList items={[
            { label: 'Company size', value: a.company_size }, { label: 'Annual revenue', value: a.annual_revenue && money(a.annual_revenue, currency) },
            { label: 'Location', value: [a.city, a.country].filter(Boolean).join(', ') }, { label: 'Owner', value: a.owner?.name }, { label: 'Created', value: date(a.created_at) },
            ...customRows,
          ]} />
          {a.description && <p className="mt-3 text-sm text-slate-600 dark:text-slate-400">{a.description}</p>}
        </Card>
      </div>
      <RecordPanels type="accounts" id={a.id} name={a.name} />
      <AccountFormModal open={modal === 'edit'} onClose={() => setModal(null)} account={a} />
      <ActivityModal open={modal === 'activity'} onClose={() => setModal(null)} subjectType="accounts" subjectId={a.id} />
      <ConfirmDialog open={modal === 'delete'} onClose={() => setModal(null)} title="Delete account?" onConfirm={async () => { if (await run(remove(a.id), 'Account deleted') !== undefined) navigate('/accounts') }} />
    </div>
  )
}
