import { useRef, useState } from 'react'
import clsx from 'clsx'
import { CheckCircle2, Download, FileSpreadsheet, UploadCloud } from 'lucide-react'
import { useToast } from '@/app/hooks'
import { errorMessage, useImportLeadsMutation, useMetaQuery } from '@/services/api'
import { Button, Field, Modal, Select, Toggle } from '@/components/ui'
import { downloadFile } from '@/lib/download'

export function ImportModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const toast = useToast()
  const { data: meta } = useMetaQuery()
  const [importLeads, { isLoading, data: result, reset }] = useImportLeadsMutation()
  const [file, setFile] = useState<File | null>(null)
  const [skip, setSkip] = useState(true)
  const [source, setSource] = useState('')
  const [dragging, setDragging] = useState(false)
  const input = useRef<HTMLInputElement>(null)

  const close = () => {
    setFile(null)
    reset()
    onClose()
  }

  const submit = async () => {
    if (!file) return
    const body = new FormData()
    body.append('file', file)
    body.append('skip_duplicates', skip ? '1' : '0')
    if (source) body.append('default_source_id', source)
    try {
      const r = await importLeads(body).unwrap()
      toast('success', `Imported ${r.created} leads`)
    } catch (e) {
      toast('error', errorMessage(e))
    }
  }

  return (
    <Modal
      open={open}
      onClose={close}
      title="Import leads"
      description="Upload a CSV. Columns are matched automatically (name, email, phone, company, source, owner, tags…)."
      footer={result ? <Button onClick={close}>Done</Button> : (
        <>
          <Button variant="ghost" icon={<Download className="size-4" />} onClick={() => downloadFile('leads/import/template', {}, 'lead-import-template.csv')} className="mr-auto">Template</Button>
          <Button variant="secondary" onClick={close}>Cancel</Button>
          <Button onClick={submit} disabled={!file} loading={isLoading}>Import</Button>
        </>
      )}
    >
      {result ? (
        <div className="space-y-4">
          <div className="flex items-center gap-3 rounded-xl bg-emerald-50 p-4 dark:bg-emerald-500/10">
            <CheckCircle2 className="size-6 text-emerald-600" />
            <p className="text-sm text-emerald-800 dark:text-emerald-300">Import finished.</p>
          </div>
          <div className="grid grid-cols-3 gap-3 text-center">
            {[['Created', result.created, 'text-emerald-600'], ['Skipped (duplicates)', result.skipped, 'text-amber-600'], ['Failed', result.failed, 'text-rose-600']].map(([l, v, c]) => (
              <div key={l as string} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                <p className={clsx('text-2xl font-semibold', c as string)}>{v}</p>
                <p className="text-xs text-slate-500">{l}</p>
              </div>
            ))}
          </div>
          {!!result.errors.length && (
            <ul className="max-h-40 overflow-y-auto rounded-lg bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
              {result.errors.map((e) => <li key={e.line}>Line {e.line}: {e.message}</li>)}
            </ul>
          )}
        </div>
      ) : (
        <div className="space-y-5">
          <div
            onClick={() => input.current?.click()}
            onDragOver={(e) => { e.preventDefault(); setDragging(true) }}
            onDragLeave={() => setDragging(false)}
            onDrop={(e) => { e.preventDefault(); setDragging(false); setFile(e.dataTransfer.files[0] ?? null) }}
            className={clsx('flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-10 text-center transition',
              dragging ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10' : 'border-slate-300 hover:border-brand-400 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/50')}
          >
            {file ? <FileSpreadsheet className="size-10 text-brand-500" /> : <UploadCloud className="size-10 text-slate-400" />}
            <p className="mt-3 text-sm font-medium text-slate-800 dark:text-slate-200">{file ? file.name : 'Drop your CSV here or click to browse'}</p>
            <p className="mt-1 text-xs text-slate-500">{file ? `${(file.size / 1024).toFixed(1)} KB` : 'Up to 5,000 rows · 10 MB'}</p>
            <input ref={input} type="file" accept=".csv,text/csv" className="hidden" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          </div>
          <Field label="Default source for rows without one">
            <Select value={source} onChange={(e) => setSource(e.target.value)} placeholder="None">
              {meta?.sources.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </Select>
          </Field>
          <Toggle checked={skip} onChange={setSkip} label="Skip duplicates" description="Rows matching an existing lead's email or phone are skipped." />
        </div>
      )}
    </Modal>
  )
}
