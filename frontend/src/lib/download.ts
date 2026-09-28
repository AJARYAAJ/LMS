import { API_URL } from '@/services/api'
import { store } from '@/app/store'

/** Download a file from an authenticated API endpoint. */
export async function downloadFile(path: string, params: Record<string, string | number | undefined> = {}, filename = 'export.csv') {
  const query = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== undefined && v !== '') as [string, string][])
  const res = await fetch(`${API_URL}/${path}${query.size ? `?${query}` : ''}`, {
    headers: { Authorization: `Bearer ${store.getState().auth.token}`, Accept: 'text/csv' },
  })
  if (!res.ok) throw new Error('Download failed')
  const blob = await res.blob()
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}
