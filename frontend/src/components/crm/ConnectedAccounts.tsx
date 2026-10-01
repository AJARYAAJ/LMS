import { useEffect } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Mail, RefreshCw, Trash2 } from 'lucide-react'
import { useAction, useToast } from '@/app/hooks'
import {
  useConnectAccountMutation, useConnectedAccountsQuery, useDisconnectAccountMutation, useSyncAccountMutation, useUpdateConnectedAccountMutation,
} from '@/services/api'
import { Badge, Button, Card, PageLoader, Toggle } from '@/components/ui'
import { ago } from '@/lib/format'

/** Connect your own Gmail / Outlook: emails with leads sync both ways, calendar busy times block booking slots. */
export function ConnectedAccountsCard() {
  const run = useAction()
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const { data } = useConnectedAccountsQuery()
  const [connect, connectState] = useConnectAccountMutation()
  const [update] = useUpdateConnectedAccountMutation()
  const [sync, syncState] = useSyncAccountMutation()
  const [disconnect] = useDisconnectAccountMutation()

  // Back from Google / Microsoft.
  useEffect(() => {
    const ok = params.get('connected')
    const err = params.get('connect_error')
    if (!ok && !err) return
    toast(ok ? 'success' : 'error', ok ? `${ok === 'google' ? 'Google' : 'Microsoft'} account connected` : err!)
    const next = new URLSearchParams(params)
    next.delete('connected'); next.delete('connect_error')
    setParams(next, { replace: true })
  }, [params, setParams, toast])

  const start = async (provider: string) => {
    const r = await run(connect(provider))
    if (r) window.location.assign(r.url)
  }

  return (
    <Card title={<span className="flex items-center gap-2"><Mail className="size-4 text-brand-500" />Email & calendar</span>}
      subtitle="Connect your mailbox: emails with leads appear on their timeline and in the inbox, emails you send from LeadFlow come from your own address, and your calendar's busy times block booking slots.">
      {!data ? <PageLoader /> : (
        <div className="space-y-4">
          {data.accounts.map((a) => (
            <div key={a.id} className="rounded-2xl border border-slate-200/80 p-4 dark:border-white/10">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-semibold text-slate-900 dark:text-white">{a.provider === 'google' ? 'Google' : 'Microsoft'}</span>
                <span className="text-sm text-slate-500">{a.email}</span>
                <Badge color={a.last_error ? '#e11d48' : '#059669'} dot className="ml-auto">{a.last_error ? 'Needs attention' : 'Connected'}</Badge>
              </div>
              {a.last_error && <p className="mt-2 rounded-lg bg-rose-50 px-2 py-1 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{a.last_error}</p>}
              <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <Toggle checked={a.sync_mail} onChange={(v) => run(update({ id: a.id, sync_mail: v }))} label="Sync and send email" />
                <Toggle checked={a.sync_calendar} onChange={(v) => run(update({ id: a.id, sync_calendar: v }))} label="Use my calendar for bookings" />
              </div>
              <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <span>{a.last_synced_at ? `Last synced ${ago(a.last_synced_at)}` : 'Not synced yet'}</span>
                <Button size="xs" variant="subtle" className="ml-auto" icon={<RefreshCw className="size-3.5" />} loading={syncState.isLoading} onClick={async () => {
                  const r = await run(sync(a.id))
                  if (r) toast('success', r.message)
                }}>Sync now</Button>
                <Button size="xs" variant="ghost" icon={<Trash2 className="size-3.5" />} onClick={() => run({ unwrap: () => disconnect(a.id).unwrap() }, 'Disconnected')}>Disconnect</Button>
              </div>
            </div>
          ))}
          <div className="flex flex-wrap gap-2">
            {data.providers.filter((p) => !data.accounts.some((a) => a.provider === p.key)).map((p) => (
              <Button key={p.key} variant="secondary" size="sm" disabled={!p.available} loading={connectState.isLoading && connectState.originalArgs === p.key} onClick={() => start(p.key)}
                title={p.available ? undefined : 'Your administrator needs to add the OAuth app credentials on the server first.'}>
                Connect {p.label}
              </Button>
            ))}
          </div>
          {data.providers.some((p) => !p.available) && <p className="text-xs text-slate-500">Greyed-out options need an administrator to add the {data.providers.filter((p) => !p.available).map((p) => p.key === 'google' ? 'GOOGLE_CLIENT_ID / SECRET' : 'MICROSOFT_CLIENT_ID / SECRET').join(' and ')} server settings.</p>}
        </div>
      )}
    </Card>
  )
}
