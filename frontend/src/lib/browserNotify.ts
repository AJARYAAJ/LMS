/** Desktop notifications while the app is open (no push server needed). */
export const browserNotifySupported = () => typeof window !== 'undefined' && 'Notification' in window

export function browserPermission(): NotificationPermission | 'unsupported' {
  return browserNotifySupported() ? Notification.permission : 'unsupported'
}

export async function requestBrowserPermission(): Promise<NotificationPermission | 'unsupported'> {
  if (!browserNotifySupported()) return 'unsupported'
  return Notification.permission === 'default' ? Notification.requestPermission() : Notification.permission
}

export async function showBrowserNotification(title: string, body: string, url?: string | null, tag?: string) {
  if (browserPermission() !== 'granted') return
  const options: NotificationOptions = { body, tag, icon: '/icons/icon-192.png', badge: '/icons/icon-192.png', data: { url: url ?? '/notifications' } }
  try {
    const reg = await navigator.serviceWorker?.getRegistration()
    if (reg) return void (await reg.showNotification(title, options))
  } catch { /* fall through to the page-level API */ }
  const n = new Notification(title, options)
  n.onclick = () => { window.focus(); if (url) window.location.assign(url); n.close() }
}
