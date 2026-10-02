import { useState } from 'react'
import clsx from 'clsx'
import { BellRing, Mail, MessagesSquare, MonitorSmartphone, Send } from 'lucide-react'
import { useAction, usePermissions, useToast } from '@/app/hooks'
import { useNotificationPrefsQuery, useTestNotificationMutation, useUpdateNotificationPrefsMutation } from '@/services/api'
import { Button, Card, PageLoader, Toggle } from '@/components/ui'
import { browserPermission, requestBrowserPermission } from '@/lib/browserNotify'
import { PushDevices } from '@/components/crm/PushDevices'

type Channel = 'in_app' | 'email' | 'browser'
const CHANNELS: { key: Channel; label: string; icon: typeof Mail }[] = [
  { key: 'in_app', label: 'In app', icon: BellRing },
  { key: 'email', label: 'Email', icon: Mail },
  { key: 'browser', label: 'Push', icon: MonitorSmartphone },
]

/** Per-event × per-channel notification matrix, daily digest, desktop permission and team chat routing. */
export function NotificationPreferences() {
  const run = useAction()
  const toast = useToast()
  const { admin } = usePermissions()
  const { data, isLoading } = useNotificationPrefsQuery()
  const [update] = useUpdateNotificationPrefsMutation()
  const [test, testState] = useTestNotificationMutation()
  const [permission, setPermission] = useState(browserPermission())

  const toggle = (kind: string, channel: Channel, value: boolean) => run(update({ notifications: { [kind]: { [channel]: value } } }))
  const toggleChat = (kind: string) => {
    const current = data?.chat_alert_kinds ?? []
    run(update({ chat_alert_kinds: current.includes(kind) ? current.filter((k) => k !== kind) : [...current, kind] }), 'Team chat alerts updated')
  }

  return (
    <Card title="Notifications" subtitle="Choose how you hear about what matters."
      action={<Button size="xs" variant="subtle" icon={<Send className="size-3.5" />} loading={testState.isLoading} onClick={async () => { const r = await run(test()); if (r) toast('success', r.message) }}>Send test</Button>}>
      {isLoading || !data ? <PageLoader /> : (
        <div className="space-y-6">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-[11px] tracking-wide text-slate-500 uppercase">
                  <th className="pb-2 text-left font-semibold">When</th>
                  {CHANNELS.map((c) => <th key={c.key} className="w-20 pb-2 font-semibold"><span className="inline-flex items-center gap-1"><c.icon className="size-3.5" />{c.label}</span></th>)}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200/60 dark:divide-white/[0.06]">
                {data.kinds.map((k) => (
                  <tr key={k.kind}>
                    <td className="py-2.5 pr-3 text-slate-700 dark:text-slate-200">{k.label}</td>
                    {CHANNELS.map((c) => (
                      <td key={c.key} className="py-2.5 text-center">
                        <input type="checkbox" aria-label={`${k.label} — ${c.label}`} checked={c.key === 'in_app' || k[c.key]} disabled={c.key === 'in_app'}
                          title={c.key === 'in_app' ? 'Everything always shows in the bell' : undefined}
                          onChange={(e) => toggle(k.kind, c.key, e.target.checked)}
                          className="size-4 cursor-pointer rounded accent-brand-600 disabled:cursor-default disabled:opacity-70" />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <Toggle checked={data.digest} onChange={(v) => run(update({ digest: v }), v ? 'Daily digest on' : 'Daily digest off')}
            label="Morning digest email" description="Weekday mornings: your overdue tasks, today's follow-ups and new leads." />

          <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-slate-900/[0.03] p-4 dark:bg-white/[0.04]">
            <div>
              <p className="text-sm font-medium text-slate-800 dark:text-slate-100">Desktop notifications</p>
              <p className="text-xs text-slate-500">
                {permission === 'granted' ? 'Allowed on this browser — alerts pop up while LeadFlow is open.'
                  : permission === 'denied' ? 'Blocked. Allow notifications for this site in your browser settings.'
                    : permission === 'unsupported' ? 'This browser does not support desktop notifications.'
                      : 'Allow this browser to show alerts outside the tab.'}
              </p>
            </div>
            {permission === 'default' && <Button size="sm" variant="secondary" onClick={async () => setPermission(await requestBrowserPermission())}>Allow</Button>}
            {permission === 'granted' && <span className="text-xs font-semibold text-emerald-600">● On</span>}
          </div>
          <PushDevices />

          {admin && (
            <div>
              <p className="mb-1 flex items-center gap-1.5 text-sm font-medium text-slate-800 dark:text-slate-100"><MessagesSquare className="size-4" />Team chat alerts</p>
              <p className="mb-3 text-xs text-slate-500">Posted to the Slack or Teams channels connected under Settings → Integrations, for the whole organization.</p>
              <div className="flex flex-wrap gap-2">
                {data.kinds.map((k) => {
                  const on = data.chat_alert_kinds.includes(k.kind)
                  return (
                    <button key={k.kind} onClick={() => toggleChat(k.kind)}
                      className={clsx('chip transition', on && 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>
                      {on ? '✓ ' : ''}{k.label}
                    </button>
                  )
                })}
              </div>
            </div>
          )}
        </div>
      )}
    </Card>
  )
}
