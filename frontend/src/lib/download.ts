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

/** Build a CSV (quoted where needed) in the browser and download it. */
export function downloadCsv(filename: string, rows: (string | number | null | undefined)[][]) {
  const cell = (v: string | number | null | undefined) => {
    const s = v === null || v === undefined ? '' : String(v)
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
  }
  const blob = new Blob([rows.map((r) => r.map(cell).join(',')).join('\n')], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}
