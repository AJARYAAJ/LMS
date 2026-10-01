import { useState } from 'react'
import { Copy, KeyRound, Plus, RefreshCw, Trash2 } from 'lucide-react'
import { useAction, useToast } from '@/app/hooks'
import { API_URL, useCreateOAuthAppMutation, useDeleteOAuthAppMutation, useOauthAppsQuery, useRotateOAuthSecretMutation } from '@/services/api'
import { Badge, Button, Card, ConfirmDialog, EmptyState, Field, Input, Modal, PageLoader, Segmented, Textarea } from '@/components/ui'
import { SectionHeader } from '@/components/crm/ConditionBuilder'
import type { OAuthAppRow } from '@/types'

const api = (path: string) => new URL(`${API_URL}/${path}`, window.location.origin).toString()

function Copyable({ label, value }: { label: string; value: string }) {
  const toast = useToast()
  return (
    <div>
      <p className="text-xs text-slate-500">{label}</p>
      <div className="mt-1 flex items-center gap-1">
        <code className="min-w-0 flex-1 truncate rounded-lg bg-slate-900/[0.04] px-2 py-1 font-mono text-xs dark:bg-white/5">{value}</code>
        <button onClick={() => { navigator.clipboard?.writeText(value); toast('info', `${label} copied`) }} className="rounded-lg p-1 text-slate-400 hover:text-brand-600" aria-label={`Copy ${label}`}><Copy className="size-3.5" /></button>
      </div>
    </div>
  )
}

/** Register apps (Zapier, Make, your own tools) that call the API on a person's behalf. */
export function OAuthAppsSection() {
  const run = useAction()
  const { data, isLoading } = useOauthAppsQuery()
  const [create, createState] = useCreateOAuthAppMutation()
  const [rotate] = useRotateOAuthSecretMutation()
  const [remove] = useDeleteOAuthAppMutation()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ name: '', redirects: '', kind: 'confidential' as 'confidential' | 'public' })
  const [secret, setSecret] = useState<{ name: string; value: string } | null>(null)
  const [deleting, setDeleting] = useState<OAuthAppRow | null>(null)
  const authorizeUrl = `${window.location.origin}/oauth/authorize`

  const submit = async () => {
    const r = await run(create({ name: form.name, redirect_uris: form.redirects.split(/\s+/).filter(Boolean), confidential: form.kind === 'confidential' }), 'App registered')
    if (r) {
      setOpen(false)
      if (r.client_secret) setSecret({ name: form.name, value: r.client_secret })
      setForm({ name: '', redirects: '', kind: 'confidential' })
    }
  }

  return (
    <div>
      <SectionHeader title="OAuth apps" description="Let other tools call the API as the person who connects them, with only the permissions they approve. Tokens expire after an hour and refresh for 60 days."
        action={<Button size="sm" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>Register app</Button>} />
      <Card title="Endpoints" className="mb-5">
        <div className="grid gap-3 md:grid-cols-3">
          <Copyable label="Authorization URL" value={authorizeUrl} />
          <Copyable label="Token URL" value={api('oauth/token')} />
          <Copyable label="Revocation URL" value={api('oauth/revoke')} />
        </div>
        <p className="mt-3 text-xs text-slate-500">Scopes: <code>read</code> (GET requests) and <code>write</code> (changes). Public apps (mobile, single-page) must use PKCE with S256.</p>
      </Card>
      {isLoading ? <PageLoader /> : !data?.length ? <div className="card"><EmptyState icon={<KeyRound />} title="No apps yet" description="Register an app to get a client ID." /></div> : (
        <div className="grid gap-4 md:grid-cols-2">
          {data.map((a) => (
            <div key={a.id} className="card space-y-3 p-5">
              <div className="flex items-center gap-2">
                <h3 className="font-semibold text-slate-900 dark:text-white">{a.name}</h3>
                <Badge color={a.confidential ? '#7c3aed' : '#0284c7'}>{a.confidential ? 'server app' : 'public app (PKCE)'}</Badge>
                <span className="ml-auto text-xs text-slate-500">{a.active_users} connected {a.active_users === 1 ? 'person' : 'people'}</span>
              </div>
              <Copyable label="Client ID" value={a.client_id} />
              <p className="truncate text-xs text-slate-500">Redirects: {a.redirect_uris.join(', ')}</p>
              <div className="flex gap-2">
                {a.confidential && <Button size="xs" variant="subtle" icon={<RefreshCw className="size-3.5" />} onClick={async () => {
                  const r = await run(rotate(a.id), 'New secret created; the old one stopped working')
                  if (r) setSecret({ name: a.name, value: r.client_secret })
                }}>New secret</Button>}
                <Button size="xs" variant="ghost" className="ml-auto text-rose-600" icon={<Trash2 className="size-3.5" />} onClick={() => setDeleting(a)}>Delete</Button>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="Register an OAuth app"
        footer={<><Button variant="secondary" onClick={() => setOpen(false)}>Cancel</Button><Button onClick={submit} loading={createState.isLoading} disabled={!form.name.trim() || !form.redirects.trim()}>Register</Button></>}>
        <div className="space-y-4">
          <Field label="App name" required><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Zapier" /></Field>
          <Field label="Redirect URLs" required hint="One per line. https:// (http:// only for localhost)."><Textarea rows={3} value={form.redirects} onChange={(e) => setForm({ ...form, redirects: e.target.value })} placeholder="https://zapier.com/dashboard/auth/oauth/return/App123CLIAPI/" /></Field>
          <div>
            <p className="mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">Type</p>
            <Segmented value={form.kind} onChange={(kind) => setForm({ ...form, kind })} options={[{ value: 'confidential', label: 'Server app (has a secret)' }, { value: 'public', label: 'Public app (PKCE, no secret)' }]} />
          </div>
        </div>
      </Modal>

      <Modal open={!!secret} onClose={() => setSecret(null)} title={`Client secret for ${secret?.name}`} description="Copy it now — it won’t be shown again."
        footer={<Button onClick={() => setSecret(null)}>Done</Button>}>
        {secret && <Copyable label="Client secret" value={secret.value} />}
      </Modal>

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} title={`Delete ${deleting?.name}?`} message="Everyone who connected it is signed out of the app immediately."
        onConfirm={async () => { if (deleting) await run({ unwrap: () => remove(deleting.id).unwrap() }, 'App deleted'); setDeleting(null) }} />
    </div>
  )
}
