import { useState, type FormEvent } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { Lock, Mail } from 'lucide-react'
import { useAppDispatch, useAppSelector } from '@/app/hooks'
import { credentialsReceived } from '@/features/auth/authSlice'
import { errorMessage, useLoginMutation } from '@/services/api'
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
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  if (token) return <Navigate to="/" replace />

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      const result = await login({ email, password }).unwrap()
      dispatch(credentialsReceived(result))
      navigate((location.state as { from?: string } | null)?.from ?? '/', { replace: true })
    } catch {
      /* shown below */
    }
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
