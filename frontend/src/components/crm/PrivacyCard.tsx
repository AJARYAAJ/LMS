import { useState } from 'react'
import clsx from 'clsx'
import { Download, ShieldAlert, ShieldCheck } from 'lucide-react'
import { useAction, usePermissions, useToast } from '@/app/hooks'
import { useEraseLeadMutation, useSetConsentMutation } from '@/services/api'
import { Button, Card, Field, Input, Modal } from '@/components/ui'
import { downloadFile } from '@/lib/download'
import type { ConsentEntry, Lead } from '@/types'

const CHANNELS = [['email', 'Email'], ['sms', 'SMS'], ['whatsapp', 'WhatsApp'], ['calls', 'Calls']] as const
const STATUS = {
  granted: { label: 'Opted in', className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' },
  denied: { label: 'Opted out', className: 'bg-rose-500/10 text-rose-700 dark:text-rose-300' },
  unknown: { label: 'Not asked', className: 'bg-slate-500/10 text-slate-600 dark:text-slate-300' },
} as const

/** Consent per channel, data export and erasure for one person. */
export function PrivacyCard({ lead }: { lead: Lead & { consent?: Record<string, ConsentEntry> | null; erased_at?: string | null } }) {
  const run = useAction()
  const toast = useToast()
  const { write, admin, manager } = usePermissions()
  const [setConsent] = useSetConsentMutation()
  const [erase, eraseState] = useEraseLeadMutation()
  const [erasing, setErasing] = useState(false)
  const [confirm, setConfirm] = useState('')

  if (lead.erased_at) {
    return <Card title="Privacy"><p className="flex items-center gap-2 text-sm text-slate-500"><ShieldAlert className="size-4" />Personal data was erased on {new Date(lead.erased_at).toLocaleDateString()}.</p></Card>
  }

  return (
    <Card title={<span className="flex items-center gap-2"><ShieldCheck className="size-4 text-brand-500" />Privacy & consent</span>}>
      <ul className="space-y-2">
        {CHANNELS.map(([key, label]) => {
          const entry = lead.consent?.[key]
          const status = entry?.status ?? 'unknown'
          return (
            <li key={key} className="flex items-center justify-between gap-2 text-sm">
              <span className="text-slate-700 dark:text-slate-200">{label}</span>
              {write ? (
                <select value={status} aria-label={`${label} consent`} onChange={(e) => run(setConsent({ id: lead.id, channel: key, status: e.target.value }), `${label}: ${STATUS[e.target.value as keyof typeof STATUS].label.toLowerCase()}`)}
                  className={clsx('cursor-pointer rounded-full border-0 py-0.5 pr-7 pl-2.5 text-xs font-semibold', STATUS[status].className)} title={entry?.source ? `Source: ${entry.source}${entry.at ? ` · ${new Date(entry.at).toLocaleDateString()}` : ''}` : undefined}>
                  {Object.entries(STATUS).map(([v, s]) => <option key={v} value={v}>{s.label}</option>)}
                </select>
              ) : <span className={clsx('rounded-full px-2.5 py-0.5 text-xs font-semibold', STATUS[status].className)}>{STATUS[status].label}</span>}
            </li>
          )
        })}
      </ul>
      <p className="mt-3 text-xs text-slate-500">Opted-out channels are blocked for everyone. Email unsubscribe links and STOP replies update this automatically.</p>
      {(manager || admin) && (
        <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-200/60 pt-4 dark:border-white/[0.06]">
          <Button size="xs" variant="secondary" icon={<Download className="size-3.5" />} onClick={() => downloadFile(`leads/${lead.id}/export`, {}, `lead-${lead.id}-export.json`).catch(() => toast('error', 'Export failed'))}>Export data</Button>
          {admin && <Button size="xs" variant="ghost" className="text-rose-600" icon={<ShieldAlert className="size-3.5" />} onClick={() => { setErasing(true); setConfirm('') }}>Erase personal data</Button>}
        </div>
      )}
      <Modal open={erasing} onClose={() => setErasing(false)} size="sm" title="Erase personal data?" description="Name, email, phone, company, notes, message bodies and call transcripts are removed for good. Anonymous history stays for reporting."
        footer={<><Button variant="secondary" onClick={() => setErasing(false)}>Cancel</Button><Button variant="danger" loading={eraseState.isLoading} disabled={confirm !== lead.full_name && confirm !== 'ERASE'} onClick={async () => { if (await run(erase({ id: lead.id, confirm }), 'Personal data erased')) setErasing(false) }}>Erase</Button></>}>
        <Field label={`Type “${lead.full_name}” to confirm`}><Input value={confirm} onChange={(e) => setConfirm(e.target.value)} autoComplete="off" /></Field>
      </Modal>
    </Card>
  )
}
