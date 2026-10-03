/** Push notifications for this device: Web Push in browsers / installed PWAs, FCM in the native app shell. */

interface NativePushPlugin {
  requestPermissions: () => Promise<{ receive: string }>
  register: () => Promise<void>
  addListener: (event: string, cb: (data: never) => void) => Promise<unknown>
}

type CapacitorGlobal = { isNativePlatform?: () => boolean; Plugins?: { PushNotifications?: NativePushPlugin } }

const capacitor = () => (window as unknown as { Capacitor?: CapacitorGlobal }).Capacitor

export const nativePush = (): NativePushPlugin | null => (capacitor()?.isNativePlatform?.() ? capacitor()?.Plugins?.PushNotifications ?? null : null)

export const webPushSupported = () => typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

async function registration() {
  return (await navigator.serviceWorker.getRegistration()) ?? navigator.serviceWorker.register('/sw.js')
}

export async function currentWebSubscription(): Promise<PushSubscription | null> {
  if (!webPushSupported()) return null
  return (await registration()).pushManager.getSubscription()
}

function keyBytes(base64url: string): Uint8Array<ArrayBuffer> {
  const b64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))
}

/** Ask permission and subscribe this browser; returns the JSON to send to the server. */
export async function subscribeWeb(vapidPublicKey: string): Promise<PushSubscriptionJSON> {
  if ((await Notification.requestPermission()) !== 'granted') throw new Error('Notifications are blocked for this site. Allow them in your browser settings.')
  const reg = await registration()
  await navigator.serviceWorker.ready
  const existing = await reg.pushManager.getSubscription()
  const sub = existing ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(vapidPublicKey) }))
  return sub.toJSON()
}

/** Native app: ask permission and get this device's FCM token. */
export async function registerNative(): Promise<string> {
  const push = nativePush()
  if (!push) throw new Error('Not running in the LeadFlow app.')
  if ((await push.requestPermissions()).receive !== 'granted') throw new Error('Notifications are turned off for LeadFlow in your phone settings.')
  return new Promise((resolve, reject) => {
    push.addListener('registration', ((t: { value: string }) => resolve(t.value)) as (d: never) => void)
    push.addListener('registrationError', ((e: { error: string }) => reject(new Error(e.error))) as (d: never) => void)
    push.register().catch(reject)
  })
}

/** Native app: tapping a notification opens the related page. */
export function handleNativeTaps() {
  nativePush()?.addListener('pushNotificationActionPerformed', ((a: { notification: { data?: { url?: string } } }) => {
    const url = a.notification.data?.url
    if (url) window.location.assign(url)
  }) as (d: never) => void)
}
