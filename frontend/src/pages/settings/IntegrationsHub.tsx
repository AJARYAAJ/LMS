import { useEffect, useMemo, useState } from 'react'
import clsx from 'clsx'
import {
  Bot, CheckCircle2, Copy, ExternalLink, Mail, MessageCircle, MessagesSquare, Phone, PlugZap, Plus, RefreshCw, Sparkles, Trash2, TriangleAlert,
} from 'lucide-react'
import { useAction, useToast } from '@/app/hooks'
import {
  errorMessage, useDeleteIntegrationMutation, useIntegrationsQuery, useSaveIntegrationMutation, useTestIntegrationMutation, useToggleIntegrationMutation,
} from '@/services/api'
import { Badge, Button, ConfirmDialog, Field, Input, Modal, PageLoader, Select, Toggle } from '@/components/ui'
import { SectionHeader } from '@/components/crm/ConditionBuilder'
import { ago } from '@/lib/format'
import type { IntegrationProvider } from '@/types'

const CATEGORY_ICON: Record<string, typeof Mail> = { email: Mail, messaging: MessageCircle, voice: Phone, ai: Sparkles, chat: MessagesSquare }
const BRAND: Record<string, string> = {
  smtp: '#64748b', sendgrid: '#1a82e2', twilio: '#f22f46', meta_whatsapp: '#25d366', vapi: '#10b981', retell: '#6366f1',
  bland: '#0f172a', simulator: '#d946ef', anthropic: '#d97757', slack: '#4a154b', teams: '#5059c9',
}

/**
 * Vendor marketplace: connect the providers LeadFlow uses for email,
 * SMS/WhatsApp, AI voice calls, AI and team chat.
 */
export function IntegrationsHub() {
  const run = useAction()
  const toast = useToast()
  const { data, isLoading } = useIntegrationsQuery()
  const [toggle] = useToggleIntegrationMutation()
  const [remove] = useDeleteIntegrationMutation()
  const [test, testState] = useTestIntegrationMutation()
  const [editing, setEditing] = useState<IntegrationProvider | null>(null)
  const [disconnecting, setDisconnecting] = useState<IntegrationProvider | null>(null)
  const [filter, setFilter] = useState<string>('all')

  const groups = useMemo(() => {
    const categories = Object.entries(data?.categories ?? {})
    return categories
      .filter(([key]) => filter === 'all' || filter === key)
      .map(([key, label]) => ({ key, label, providers: data?.providers.filter((p) => p.category === key) ?? [] }))
  }, [data, filter])

  if (isLoading || !data) return <PageLoader />
  const connectedCount = data.providers.filter((p) => p.connection).length

  const runTest = async (p: IntegrationProvider) => {
    if (!p.connection) return
    try {
      const r = await test(p.connection.id).unwrap()
      toast('success', r.message)
    } catch (e) {
      toast('error', errorMessage(e))
    }
  }

  return (
    <>
      <SectionHeader
        title="Integrations"
        description="Connect the vendors LeadFlow works through. Credentials are encrypted per organization and never shown again after saving."
        action={<Badge color="#10b981">{connectedCount} connected</Badge>}
      />
      <div className="mb-6 flex flex-wrap gap-2">
        {[['all', 'All'], ...Object.entries(data.categories)].map(([k, l]) => (
          <button key={k} onClick={() => setFilter(k)} className={clsx('chip transition', filter === k && 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>{l}</button>
        ))}
      </div>

      <div className="space-y-8">
        {groups.map((g) => {
          const Icon = CATEGORY_ICON[g.key] ?? PlugZap
          return (
            <section key={g.key}>
              <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-300"><Icon className="size-4 text-brand-500" />{g.label}</h3>
              <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                {g.providers.map((p) => {
                  const c = p.connection
                  const color = BRAND[p.key] ?? '#8b5cf6'
                  return (
                    <div key={p.key} className={clsx('card flex flex-col p-5', c && !c.is_active && 'opacity-70')}>
                      <div className="flex items-start gap-3">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl font-display text-lg font-bold text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${color}, ${color}bb)`, boxShadow: `0 10px 24px -12px ${color}` }}>
                          {p.key === 'simulator' ? <Bot className="size-5" /> : p.name[0]}
                        </span>
                        <div className="min-w-0 flex-1">
                          <p className="font-semibold text-slate-900 dark:text-white">{p.name}</p>
                          <p className="text-xs text-slate-500">{p.description}</p>
                        </div>
                      </div>

                      {c && (
                        <div className="mt-4 space-y-2 text-xs">
                          <p className={clsx('flex items-center gap-1.5 font-medium', c.status === 'error' ? 'text-rose-600' : 'text-emerald-600')}>
                            {c.status === 'error' ? <TriangleAlert className="size-3.5" /> : <CheckCircle2 className="size-3.5" />}
                            {c.status === 'error' ? 'Needs attention' : c.is_active ? 'Connected' : 'Paused'}
                            {c.last_tested_at && <span className="font-normal text-slate-400">· tested {ago(c.last_tested_at)}</span>}
                          </p>
                          {c.last_error && <p className="rounded-lg bg-rose-50 px-2 py-1 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{c.last_error}</p>}
                          {c.redirect_url && (
                            <div>
                              <p className="text-slate-500">Redirect URL (add it in {p.name}):</p>
                              <div className="mt-1 flex gap-1">
                                <code className="min-w-0 flex-1 truncate rounded-lg bg-slate-900/[0.04] px-2 py-1 font-mono text-[11px] dark:bg-white/5">{c.redirect_url}</code>
                                <button onClick={() => { navigator.clipboard?.writeText(c.redirect_url!); toast('info', 'Redirect URL copied') }} className="rounded-lg p-1 text-slate-400 hover:text-brand-600" aria-label="Copy redirect URL"><Copy className="size-3.5" /></button>
                              </div>
                            </div>
                          )}
                          {c.inbound_url && (
                            <div>
                              <p className="text-slate-500">Webhook URL (paste into {p.name}):</p>
                              <div className="mt-1 flex gap-1">
                                <code className="min-w-0 flex-1 truncate rounded-lg bg-slate-900/[0.04] px-2 py-1 font-mono text-[11px] dark:bg-white/5">{c.inbound_url}</code>
                                <button onClick={() => { navigator.clipboard?.writeText(c.inbound_url!); toast('info', 'Webhook URL copied') }} className="rounded-lg p-1 text-slate-400 hover:text-brand-600" aria-label="Copy webhook URL"><Copy className="size-3.5" /></button>
                              </div>
                            </div>
                          )}
                        </div>
                      )}

                      <div className="mt-auto flex flex-wrap items-center gap-2 pt-4">
                        {c ? (
                          <>
                            <Toggle checked={c.is_active} onChange={(v) => run(toggle({ id: c.id, is_active: v }), v ? `${p.name} enabled` : `${p.name} paused`)} />
                            <Button size="xs" variant="secondary" icon={<RefreshCw className={clsx('size-3.5', testState.isLoading && testState.originalArgs === c.id && 'animate-spin')} />} onClick={() => runTest(p)}>Test</Button>
                            {p.fields.length > 0 && <Button size="xs" variant="ghost" onClick={() => setEditing(p)}>Edit</Button>}
                            <button onClick={() => setDisconnecting(p)} className="ml-auto rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label={`Disconnect ${p.name}`}><Trash2 className="size-4" /></button>
                          </>
                        ) : (
                          <Button size="xs" icon={<Plus className="size-3.5" />} onClick={() => setEditing(p)}>Connect</Button>
                        )}
                      </div>
                    </div>
                  )
                })}
              </div>
            </section>
          )
        })}
      </div>

      <ConnectModal provider={editing} onClose={() => setEditing(null)} />
      <ConfirmDialog open={!!disconnecting} onClose={() => setDisconnecting(null)} title={`Disconnect ${disconnecting?.name}?`} confirmLabel="Disconnect"
        message="Stored credentials are deleted. Features using this vendor fall back to the next connected provider (or stop)."
        onConfirm={async () => { if (disconnecting?.connection) await run(remove(disconnecting.connection.id), `${disconnecting.name} disconnected`); setDisconnecting(null) }} />
    </>
  )
}

function ConnectModal({ provider, onClose }: { provider: IntegrationProvider | null; onClose: () => void }) {
  const run = useAction()
  const [save, { isLoading }] = useSaveIntegrationMutation()
  const [values, setValues] = useState<Record<string, string>>({})

  useEffect(() => {
    if (provider) setValues(Object.fromEntries(provider.fields.map((f) => [f.key, provider.connection?.values[f.key] ?? ''])))
  }, [provider])

  if (!provider) return null
  const connected = !!provider.connection
  const submit = async () => {
    if (await run(save({ provider: provider.key, config: values }), connected ? `${provider.name} updated` : `${provider.name} connected`) !== undefined) onClose()
  }

  return (
    <Modal open onClose={onClose} title={`${connected ? 'Edit' : 'Connect'} ${provider.name}`} description={provider.description}
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} loading={isLoading}>{connected ? 'Save' : 'Connect'}</Button></>}>
      <div className="space-y-4">
        {provider.fields.length === 0 && <p className="text-sm text-slate-600 dark:text-slate-400">No credentials needed.</p>}
        {provider.fields.map((f) => (
          <Field key={f.key} label={f.label} required={f.required && !(f.secret && provider.connection?.secrets_set[f.key])}
            hint={f.secret && provider.connection?.secrets_set[f.key] ? 'Saved — leave blank to keep the current value.' : undefined}>
            {f.options ? (
              <Select value={values[f.key] ?? ''} onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))} placeholder="—">
                {f.options.map((o) => <option key={o}>{o}</option>)}
              </Select>
            ) : (
              <Input type={f.secret ? 'password' : 'text'} autoComplete="off" placeholder={f.secret && provider.connection?.secrets_set[f.key] ? '••••••••' : f.placeholder}
                value={values[f.key] ?? ''} onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))} />
            )}
          </Field>
        ))}
        <p className="flex items-center gap-1.5 text-xs text-slate-500"><ExternalLink className="size-3.5" /> Find these values in your {provider.name} dashboard. Use “Test” after connecting to verify them.</p>
      </div>
    </Modal>
  )
}
