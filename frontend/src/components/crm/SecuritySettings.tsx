import { useEffect, useState } from 'react'
import QRCode from 'qrcode'
import { Copy, Download, KeyRound, ShieldCheck, ShieldOff } from 'lucide-react'
import { useAction, useToast } from '@/app/hooks'
import { useTwoFactorConfirmMutation, useTwoFactorDisableMutation, useTwoFactorRegenerateMutation, useTwoFactorSetupMutation, useTwoFactorStatusQuery } from '@/services/api'
import { Badge, Button, Card, Field, Input, Modal, PageLoader } from '@/components/ui'
import { downloadCsv } from '@/lib/download'

function RecoveryCodes({ codes }: { codes: string[] }) {
  const toast = useToast()
  return (
    <div className="space-y-3">
      <p className="text-sm text-slate-600 dark:text-slate-300">Save these one-time codes somewhere safe. Each lets you sign in once if you lose your phone.</p>
      <ul className="grid grid-cols-2 gap-2 rounded-2xl bg-slate-900/[0.04] p-4 font-mono text-sm dark:bg-white/[0.05]">{codes.map((c) => <li key={c}>{c}</li>)}</ul>
      <div className="flex gap-2">
        <Button size="sm" variant="secondary" icon={<Copy className="size-4" />} onClick={() => { navigator.clipboard?.writeText(codes.join('\n')); toast('info', 'Codes copied') }}>Copy</Button>
        <Button size="sm" variant="secondary" icon={<Download className="size-4" />} onClick={() => downloadCsv('leadflow-recovery-codes.txt', codes.map((c) => [c]))}>Download</Button>
      </div>
    </div>
  )
}

/** Two-step login: set up an authenticator app, manage recovery codes, turn it off. */
export function TwoFactorCard() {
  const run = useAction()
  const { data, isLoading } = useTwoFactorStatusQuery()
  const [setup, setupState] = useTwoFactorSetupMutation()
  const [confirm, confirmState] = useTwoFactorConfirmMutation()
  const [regenerate, regenState] = useTwoFactorRegenerateMutation()
  const [disable, disableState] = useTwoFactorDisableMutation()
  const [pending, setPending] = useState<{ secret: string; uri: string } | null>(null)
  const [qr, setQr] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [codes, setCodes] = useState<string[] | null>(null)
  const [passwordFor, setPasswordFor] = useState<'disable' | 'codes' | null>(null)
  const [password, setPassword] = useState('')

  useEffect(() => {
    if (pending) QRCode.toDataURL(pending.uri, { margin: 1, width: 200 }).then(setQr).catch(() => setQr(null))
  }, [pending])

  if (isLoading || !data) return <Card title="Two-step login"><PageLoader /></Card>

  const start = async () => { const r = await run(setup()); if (r) { setPending(r); setCode('') } }
  const finish = async () => { const r = await run(confirm({ code }), 'Two-step login is on'); if (r) { setPending(null); setCodes(r.recovery_codes) } }
  const withPassword = async () => {
    if (passwordFor === 'disable') {
      if (await run(disable({ password }), 'Two-step login turned off')) setPasswordFor(null)
    } else {
      const r = await run(regenerate({ password }), 'New recovery codes created')
      if (r) { setPasswordFor(null); setCodes(r.recovery_codes) }
    }
    setPassword('')
  }

  return (
    <Card title={<span className="flex items-center gap-2"><ShieldCheck className="size-4 text-brand-500" />Two-step login</span>}
      subtitle="Ask for a code from an authenticator app (Google Authenticator, 1Password, Authy…) when you sign in."
      action={data.enabled ? <Badge color="#10b981" dot>On</Badge> : <Badge color="#94a3b8" dot>Off</Badge>}>
      {data.enabled ? (
        <div className="flex flex-wrap items-center gap-3">
          <p className="flex-1 text-sm text-slate-600 dark:text-slate-300">{data.recovery_codes_left} recovery code{data.recovery_codes_left === 1 ? '' : 's'} left.</p>
          <Button size="sm" variant="secondary" icon={<KeyRound className="size-4" />} onClick={() => setPasswordFor('codes')}>New recovery codes</Button>
          <Button size="sm" variant="ghost" icon={<ShieldOff className="size-4" />} onClick={() => setPasswordFor('disable')}>Turn off</Button>
        </div>
      ) : <Button size="sm" icon={<ShieldCheck className="size-4" />} loading={setupState.isLoading} onClick={start}>Set up</Button>}

      <Modal open={!!pending} onClose={() => setPending(null)} title="Set up your authenticator"
        footer={<><Button variant="secondary" onClick={() => setPending(null)}>Cancel</Button><Button onClick={finish} loading={confirmState.isLoading} disabled={code.replace(/\s/g, '').length !== 6}>Turn on</Button></>}>
        <div className="space-y-4">
          <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
            <li>Scan this code with your authenticator app (or type the key below).</li>
            <li>Enter the 6-digit code it shows.</li>
          </ol>
          <div className="flex flex-wrap items-center gap-4">
            {qr ? <img src={qr} alt="QR code for your authenticator app" className="size-44 rounded-xl bg-white p-2 ring-1 ring-slate-200" /> : <div className="size-44 animate-pulse rounded-xl bg-slate-100" />}
            <div className="min-w-0 flex-1">
              <p className="text-xs text-slate-500">Setup key</p>
              <code className="block font-mono text-sm break-all">{pending?.secret.match(/.{1,4}/g)?.join(' ')}</code>
            </div>
          </div>
          <Field label="Code from the app"><Input value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" autoComplete="one-time-code" placeholder="123 456" /></Field>
        </div>
      </Modal>

      <Modal open={!!codes} onClose={() => setCodes(null)} title="Your recovery codes" footer={<Button onClick={() => setCodes(null)}>I saved them</Button>}>
        {codes && <RecoveryCodes codes={codes} />}
      </Modal>

      <Modal open={!!passwordFor} onClose={() => setPasswordFor(null)} size="sm" title={passwordFor === 'disable' ? 'Turn off two-step login' : 'Create new recovery codes'} description="Confirm with your password."
        footer={<><Button variant="secondary" onClick={() => setPasswordFor(null)}>Cancel</Button><Button variant={passwordFor === 'disable' ? 'danger' : 'primary'} onClick={withPassword} loading={disableState.isLoading || regenState.isLoading} disabled={!password}>Confirm</Button></>}>
        <Field label="Password"><Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" /></Field>
      </Modal>
    </Card>
  )
}
