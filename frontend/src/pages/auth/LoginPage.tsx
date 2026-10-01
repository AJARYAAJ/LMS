import { useState, type FormEvent } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { Lock, Mail, ShieldCheck } from 'lucide-react'
import { useAppDispatch, useAppSelector } from '@/app/hooks'
import { credentialsReceived } from '@/features/auth/authSlice'
import { errorMessage, useLoginMutation, useTwoFactorChallengeMutation } from '@/services/api'
import type { User } from '@/types'
import { Button, Field, Input } from '@/components/ui'
import { AuthLayout } from './AuthLayout'

const demoAccounts = [
  { label: 'Admin', email: 'admin@lms.test' },
  { label: 'Manager', email: 'manager@lms.test' },
  { label: 'Sales rep', email: 'riley@lms.test' },
  { label: 'Viewer', email: 'viewer@lms.test' },
]

export function LoginPage() {
  const dispatch = useAppDispatch()
  const navigate = useNavigate()
  const location = useLocation()
  const token = useAppSelector((s) => s.auth.token)
  const [login, { isLoading, error }] = useLoginMutation()
  const [verify, verifyState] = useTwoFactorChallengeMutation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [challenge, setChallenge] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [useRecovery, setUseRecovery] = useState(false)

  if (token) return <Navigate to="/" replace />

  const done = (result: { token: string; user: User }) => {
    dispatch(credentialsReceived(result))
    navigate((location.state as { from?: string } | null)?.from ?? '/', { replace: true })
  }

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      const result = await login({ email, password }).unwrap()
      if ('two_factor_required' in result) setChallenge(result.challenge)
      else done(result)
    } catch {
      /* shown below */
    }
  }

  const submitCode = async (e: FormEvent) => {
    e.preventDefault()
    if (!challenge) return
    try {
      done(await verify(useRecovery ? { challenge, recovery_code: code } : { challenge, code }).unwrap())
    } catch {
      /* shown below */
    }
  }

  if (challenge) {
    return (
      <AuthLayout title="Two-step verification" subtitle={useRecovery ? 'Enter one of the recovery codes you saved.' : 'Enter the 6-digit code from your authenticator app.'}>
        <form onSubmit={submitCode} className="space-y-4">
          {verifyState.error && <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300" role="alert">{errorMessage(verifyState.error)}</div>}
          <Field label={useRecovery ? 'Recovery code' : 'Authentication code'}>
            <Input value={code} onChange={(e) => setCode(e.target.value)} inputMode={useRecovery ? 'text' : 'numeric'} autoComplete="one-time-code" placeholder={useRecovery ? 'abcde-fghij' : '123 456'} autoFocus icon={<ShieldCheck className="size-4" />} />
          </Field>
          <Button type="submit" size="lg" className="w-full" loading={verifyState.isLoading} disabled={code.trim().length < 6}>Verify</Button>
          <div className="flex justify-between text-sm">
            <button type="button" className="text-brand-600 hover:underline" onClick={() => { setUseRecovery((v) => !v); setCode('') }}>{useRecovery ? 'Use the authenticator app' : 'Use a recovery code'}</button>
            <button type="button" className="text-slate-500 hover:underline" onClick={() => { setChallenge(null); setCode('') }}>Back</button>
          </div>
        </form>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title="Welcome back" subtitle="Sign in to your workspace to continue.">
      <form onSubmit={submit} className="space-y-4">
        {error && <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">{errorMessage(error)}</div>}
        <Field label="Email">
          <Input type="email" icon={<Mail className="size-4" />} value={email} onChange={(e) => setEmail(e.target.value)} placeholder="you@company.com" required autoFocus />
        </Field>
        <Field label="Password">
          <Input type="password" icon={<Lock className="size-4" />} value={password} onChange={(e) => setPassword(e.target.value)} placeholder="••••••••" required />
        </Field>
        <Button type="submit" size="lg" className="w-full" loading={isLoading}>Sign in</Button>
      </form>

      <div className="mt-8 rounded-xl border border-dashed border-slate-300 p-4 dark:border-slate-700">
        <p className="text-xs font-medium text-slate-500">Demo accounts · password <code className="rounded bg-slate-100 px-1 dark:bg-slate-800">password</code></p>
        <div className="mt-2 grid grid-cols-2 gap-2">
          {demoAccounts.map((a) => (
            <button
              key={a.email}
              type="button"
              onClick={() => { setEmail(a.email); setPassword('password') }}
              className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-left text-xs transition hover:border-brand-300 hover:bg-brand-50 dark:border-slate-700 dark:hover:bg-brand-500/10"
            >
              <span className="block font-medium text-slate-800 dark:text-slate-200">{a.label}</span>
              <span className="block truncate text-slate-500">{a.email}</span>
            </button>
          ))}
        </div>
      </div>

      <p className="mt-6 text-center text-sm text-slate-500">
        New here? <Link to="/register" className="font-medium text-brand-600 hover:text-brand-700">Create an organization</Link>
      </p>
    </AuthLayout>
  )
}
