import { useEffect, useState } from 'react'
import { BellRing, Smartphone } from 'lucide-react'
import { useAction } from '@/app/hooks'
import { usePushConfigQuery, useSubscribePushMutation, useUnsubscribePushMutation } from '@/services/api'
import { Button } from '@/components/ui'
import { ago } from '@/lib/format'
import { currentWebSubscription, nativePush, registerNative, subscribeWeb, webPushSupported } from '@/lib/push'

/** Turn push on for this device (phone or computer), and see the other devices that get pushes. */
export function PushDevices() {
  const run = useAction()
  const { data } = usePushConfigQuery()
  const [subscribe, subState] = useSubscribePushMutation()
  const [unsubscribe] = useUnsubscribePushMutation()
  const [mine, setMine] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const native = !!nativePush()
  const supported = native ? !!data?.fcm : webPushSupported()

  useEffect(() => { currentWebSubscription().then((s) => setMine(s?.endpoint ?? null)).catch(() => undefined) }, [])

  const turnOn = async () => {
    setError(null)
    try {
      if (native) {
        const token = await registerNative()
        if (await run(subscribe({ fcm_token: token, device: 'LeadFlow app' }), 'Push is on for this phone')) setMine(token)
      } else {
        const sub = await subscribeWeb(data!.vapid_public_key)
        if (await run({ unwrap: () => subscribe({ endpoint: sub.endpoint, keys: sub.keys }).unwrap().then(() => true) }, 'Push is on for this device')) setMine(sub.endpoint ?? null)
      }
    } catch (e) {
      setError((e as Error).message)
    }
  }
  const turnOff = async () => {
    const sub = await currentWebSubscription()
    await sub?.unsubscribe()
    await run({ unwrap: () => unsubscribe(native ? { fcm_token: mine ?? '' } : { endpoint: mine ?? '' }).unwrap() }, 'Push is off for this device')
    setMine(null)
  }

  return (
    <div className="rounded-2xl bg-slate-900/[0.03] p-4 dark:bg-white/[0.04]">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p className="flex items-center gap-1.5 text-sm font-medium text-slate-800 dark:text-slate-100"><BellRing className="size-4" />Push notifications on this device</p>
          <p className="text-xs text-slate-500">
            {!supported ? (native ? 'Push isn’t set up on this server yet.' : 'This browser doesn’t support push. On iPhone, add LeadFlow to your Home Screen first.')
              : mine ? 'On — alerts arrive even when LeadFlow is closed.'
                : 'Get alerts on your phone or computer even when LeadFlow is closed. Pick which events push in the “Push” column above.'}
          </p>
          {error && <p className="mt-1 text-xs text-rose-600" role="alert">{error}</p>}
        </div>
        {supported && data && (mine
          ? <Button size="sm" variant="ghost" onClick={turnOff}>Turn off push</Button>
          : <Button size="sm" variant="secondary" loading={subState.isLoading} onClick={turnOn}>Turn on push</Button>)}
      </div>
      {!!data?.devices.length && (
        <ul className="mt-3 space-y-1 text-xs text-slate-500">
          {data.devices.map((d) => (
            <li key={d.id} className="flex items-center gap-2">
              <Smartphone className="size-3.5" />
              <span className="truncate">{d.kind === 'fcm' ? 'LeadFlow app' : (d.device ?? 'Browser').replace(/\(.*?\)/g, '').slice(0, 60)}</span>
              <span className="ml-auto shrink-0">{d.last_used_at ? `last push ${ago(d.last_used_at)}` : `added ${ago(d.created_at)}`}</span>
              <button className="shrink-0 text-slate-400 hover:text-rose-600" onClick={() => run({ unwrap: () => unsubscribe({ id: d.id }).unwrap() }, 'Device removed')} aria-label="Remove device">Remove</button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
