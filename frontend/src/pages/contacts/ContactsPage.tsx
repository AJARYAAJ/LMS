import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Mail, Pencil, Phone, Plus, Search, Target, Trash2, Users } from 'lucide-react'
import { useAction, usePermissions } from '@/app/hooks'
import { useAccountsQuery, useContactQuery, useContactsQuery, useDeleteContactMutation, useMetaQuery, useSaveContactMutation } from '@/services/api'
import { Avatar, Badge, Button, Card, ConfirmDialog, EmptyState, Input, Modal, PageHeader, PageLoader, Pagination } from '@/components/ui'
import { RecordPanels } from '@/components/crm/RecordPanels'
import { LayoutDetails, LayoutFields, type LayoutValues } from '@/lib/layouts'
import { ActivityModal } from '@/components/crm/ActivityModal'
import { ago, date } from '@/lib/format'
import type { Contact } from '@/types'

export function ContactFormModal({ open, onClose, contact }: { open: boolean; onClose: () => void; contact?: Contact | null }) {
  const run = useAction()
  const { data: meta } = useMetaQuery()
  const { data: accounts } = useAccountsQuery({ per_page: 100 }, { skip: !open })
  const [save, { isLoading }] = useSaveContactMutation()
  const [form, setForm] = useState<Record<string, string>>({})
  const [custom, setCustom] = useState<Record<string, unknown>>({})

  useEffect(() => {
    if (open) setCustom(contact?.custom_fields ?? {})
    if (open) setForm({
      first_name: contact?.first_name ?? '', last_name: contact?.last_name ?? '', email: contact?.email ?? '', phone: contact?.phone ?? '',
      job_title: contact?.job_title ?? '', account_id: String(contact?.account_id ?? ''), owner_id: String(contact?.owner_id ?? ''),
    })
  }, [open, contact])

  const submit = async () => {
    const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : k.endsWith('_id') ? Number(v) : v]))
    if (await run(save({ ...(contact ? { id: contact.id } : {}), ...body, custom_fields: custom } as Partial<Contact>), contact ? 'Contact updated' : 'Contact created')) onClose()
  }

  return (
    <Modal open={open} onClose={onClose} title={contact ? 'Edit contact' : 'New contact'} footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} disabled={!form.first_name} loading={isLoading}>Save</Button></>}>
      <LayoutFields
        entity="contact"
        meta={meta}
        values={{ ...form, custom_fields: custom }}
        required={['first_name']}
        extras={{ accounts: accounts?.data.map((a) => ({ value: a.id, label: a.name })) }}
        onChange={(k, v) => (k.startsWith('custom.') ? setCustom((c) => ({ ...c, [k.slice(7)]: v })) : setForm((f) => ({ ...f, [k]: v as string })))}
      />
    </Modal>
  )
}

export function ContactsPage() {
  const navigate = useNavigate()
  const { write } = usePermissions()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const { data, isLoading } = useContactsQuery({ search, page })

  return (
    <div>
      <PageHeader icon={<Users />} title="Contacts" description="People you do business with — created manually or from converted leads."
        actions={write && <Button size="sm" icon={<Plus className="size-4" />} onClick={() => setShowForm(true)}>New contact</Button>} />
      <div className="card mb-5 p-3"><Input icon={<Search className="size-4" />} placeholder="Search contacts…" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} /></div>
      {isLoading ? <PageLoader /> : (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {data?.data.map((c) => (
            <button key={c.id} onClick={() => navigate(`/contacts/${c.id}`)} className="card group p-5 text-left transition hover:-translate-y-1">
              <div className="flex items-center gap-3">
                <Avatar name={`${c.first_name} ${c.last_name ?? ''}`} color={c.owner?.avatar_color} size="lg" />
                <div className="min-w-0">
                  <p className="font-display truncate font-semibold text-slate-900 dark:text-white">{c.first_name} {c.last_name}</p>
                  <p className="truncate text-sm text-slate-500">{c.job_title ?? 'Contact'}{c.account && ` · ${c.account.name}`}</p>
                </div>
              </div>
              <div className="mt-4 space-y-1.5 text-sm text-slate-600 dark:text-slate-400">
                {c.email && <p className="flex items-center gap-2 truncate"><Mail className="size-4 text-brand-400" />{c.email}</p>}
                {c.phone && <p className="flex items-center gap-2"><Phone className="size-4 text-emerald-400" />{c.phone}</p>}
              </div>
              <div className="mt-4 flex items-center justify-between text-xs text-slate-500">
                <span>{c.deals_count ?? 0} deals</span><span>Added {ago(c.created_at)}</span>
              </div>
            </button>
          ))}
        </div>
      )}
      {data && !data.data.length && <div className="card"><EmptyState icon={<Users />} title="No contacts" description="Convert a lead to create your first contact." /></div>}
      {data && data.last_page > 1 && <div className="card mt-4"><Pagination meta={data} onPage={setPage} /></div>}
      <ContactFormModal open={showForm} onClose={() => setShowForm(false)} />
    </div>
  )
}

export function ContactDetailPage() {
  const id = Number(useParams().id)
  const navigate = useNavigate()
  const run = useAction()
  const { write, manager } = usePermissions()
  const { data: c, isLoading } = useContactQuery(id)
  const { data: meta } = useMetaQuery()
  const [remove] = useDeleteContactMutation()
  const [modal, setModal] = useState<null | 'edit' | 'activity' | 'delete'>(null)

  if (isLoading) return <PageLoader />
  if (!c) return <EmptyState title="Contact not found" />

  return (
    <div className="space-y-6">
      <Link to="/contacts" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand-600"><ArrowLeft className="size-4" /> Contacts</Link>
      <section className="card flex flex-wrap items-center gap-5 p-6">
        <Avatar name={`${c.first_name} ${c.last_name ?? ''}`} color={c.owner?.avatar_color} size="xl" />
        <div className="min-w-0 flex-1">
          <h1 className="text-3xl font-bold text-slate-900 dark:text-white">{c.first_name} {c.last_name}</h1>
          <p className="mt-1 text-slate-500">{c.job_title}{c.account && <> at <Link to={`/accounts/${c.account.id}`} className="text-brand-600 hover:underline">{c.account.name}</Link></>}</p>
          <div className="mt-3 flex flex-wrap gap-2">
            {c.email && <a href={`mailto:${c.email}`} className="chip"><Mail className="size-3.5" />{c.email}</a>}
            {c.phone && <a href={`tel:${c.phone}`} className="chip"><Phone className="size-3.5" />{c.phone}</a>}
            {c.lead_id && <Link to={`/leads/${c.lead_id}`} className="chip"><Target className="size-3.5" />Original lead</Link>}
          </div>
        </div>
        {write && <div className="flex gap-2">
          <Button size="sm" variant="secondary" onClick={() => setModal('activity')}>Log activity</Button>
          <Button size="sm" variant="secondary" icon={<Pencil className="size-4" />} onClick={() => setModal('edit')}>Edit</Button>
          {manager && <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" />} onClick={() => setModal('delete')} aria-label="Delete" />}
        </div>}
      </section>
      <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
        <RecordPanels type="contacts" id={c.id} name={`${c.first_name} ${c.last_name ?? ''}`.trim()} />
        <Card title="Deals & details">
          {c.deals?.length ? c.deals.map((d) => (
            <Link key={d.id} to={`/deals/${d.id}`} className="flex items-center justify-between rounded-xl px-2 py-2 text-sm hover:bg-brand-50 dark:hover:bg-white/5">
              <span>{d.name}</span>{d.stage && <Badge color={d.stage.color}>{d.stage.name}</Badge>}
            </Link>
          )) : <p className="text-sm text-slate-500">No deals.</p>}
          <div className="mt-5 border-t border-slate-200/60 pt-4 dark:border-white/[0.06]">
            <LayoutDetails entity="contact" meta={meta} record={c as unknown as LayoutValues} skip={['first_name', 'last_name']}
              extras={{ accounts: c.account ? [{ value: c.account.id, label: c.account.name }] : [] }}
              footer={[{ label: 'Created', value: date(c.created_at) }]} />
          </div>
        </Card>
      </div>
      <ContactFormModal open={modal === 'edit'} onClose={() => setModal(null)} contact={c} />
      <ActivityModal open={modal === 'activity'} onClose={() => setModal(null)} subjectType="contacts" subjectId={c.id} />
      <ConfirmDialog open={modal === 'delete'} onClose={() => setModal(null)} title="Delete contact?" onConfirm={async () => { if (await run(remove(c.id), 'Contact deleted') !== undefined) navigate('/contacts') }} />
    </div>
  )
}

