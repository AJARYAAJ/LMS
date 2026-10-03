import { useEffect, useState } from 'react'
import { BellRing, X } from 'lucide-react'
import { useAction } from '@/app/hooks'
import { usePushConfigQuery, useSubscribePushMutation } from '@/services/api'
import { Button } from '@/components/ui'
import { currentWebSubscription, nativePush, registerNative, subscribeWeb, webPushSupported } from '@/lib/push'

const DISMISSED = 'lf.notify-prompt-dismissed'

/**
 * A one-time card inviting people to get alerts outside LeadFlow (phone / desktop),
 * so a new lead or a reply is never missed while the tab is closed.
 */
export function NotificationPrompt() {
  const run = useAction()
  const native = !!nativePush()
  const { data } = usePushConfigQuery(undefined, { skip: !native && !webPushSupported() })
  const [subscribe, state] = useSubscribePushMutation()
  const [show, setShow] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let dismissed = false
    try { dismissed = localStorage.getItem(DISMISSED) === '1' } catch { /* private mode */ }
    if (dismissed || (!native && (!webPushSupported() || Notification.permission === 'denied'))) return
    const t = setTimeout(async () => {
      if (native || !(await currentWebSubscription().catch(() => null))) setShow(true)
    }, 4000)
    return () => clearTimeout(t)
  }, [native])

  const dismiss = () => {
    try { localStorage.setItem(DISMISSED, '1') } catch { /* ignore */ }
    setShow(false)
  }
  const enable = async () => {
    setError(null)
    try {
      if (native) {
        const token = await registerNative()
        await run(subscribe({ fcm_token: token, device: 'LeadFlow app' }), 'Notifications are on for this phone')
      } else {
        const sub = await subscribeWeb(data!.vapid_public_key)
        await run({ unwrap: () => subscribe({ endpoint: sub.endpoint, keys: sub.keys }).unwrap().then(() => true) }, 'Notifications are on for this device')
      }
      dismiss()
    } catch (e) {
      setError((e as Error).message)
    }
  }

  if (!show || (!native && !data) || (native && !data?.fcm)) return null
  return (
    <div role="dialog" aria-labelledby="notify-prompt-title" className="fixed right-4 bottom-4 z-40 w-[min(22rem,calc(100vw-2rem))] animate-slide-up rounded-2xl border border-slate-200/80 bg-white/95 p-4 shadow-xl backdrop-blur dark:border-white/10 dark:bg-slate-900/95">
      <div className="flex items-start gap-3">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600"><BellRing className="size-5" /></span>
        <div className="min-w-0 flex-1">
          <p id="notify-prompt-title" className="text-sm font-semibold text-slate-900 dark:text-white">Never miss a lead</p>
          <p className="mt-0.5 text-xs text-slate-500">Get new leads, replies, meetings and reminders as notifications on this device — even when LeadFlow is closed.</p>
          {error && <p className="mt-1 text-xs text-rose-600" role="alert">{error}</p>}
          <div className="mt-3 flex gap-2">
            <Button size="sm" loading={state.isLoading} onClick={enable}>Enable alerts</Button>
            <Button size="sm" variant="ghost" onClick={dismiss}>Not now</Button>
          </div>
        </div>
        <button onClick={dismiss} className="rounded-lg p-1 text-slate-400 hover:text-slate-700" aria-label="Close"><X className="size-4" /></button>
      </div>
    </div>
  )
}
