import { useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { useAppDispatch, useAppSelector } from '@/app/hooks'
import { credentialsReceived } from '@/features/auth/authSlice'
import { fieldErrors, useRegisterMutation } from '@/services/api'
import { Button, Field, Input, Select } from '@/components/ui'
import { AuthLayout } from './AuthLayout'

export function RegisterPage() {
  const dispatch = useAppDispatch()
  const navigate = useNavigate()
  const token = useAppSelector((s) => s.auth.token)
  const [register, { isLoading, error }] = useRegisterMutation()
  const [form, setForm] = useState({ organization_name: '', name: '', email: '', password: '', password_confirmation: '', currency: 'USD' })
  const errors = fieldErrors(error)
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))

  if (token) return <Navigate to="/" replace />

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      const result = await register(form).unwrap()
      dispatch(credentialsReceived(result))
      navigate('/', { replace: true })
    } catch {
      /* field errors shown inline */
    }
  }

  return (
    <AuthLayout title="Create your workspace" subtitle="Set up your organization with a ready-to-use sales process.">
      <form onSubmit={submit} className="space-y-4">
        <Field label="Organization name" error={errors.organization_name} required>
          <Input value={form.organization_name} onChange={set('organization_name')} placeholder="Acme Inc." required autoFocus />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Your name" error={errors.name} required>
            <Input value={form.name} onChange={set('name')} required />
          </Field>
          <Field label="Currency">
            <Select value={form.currency} onChange={set('currency')}>
              {['USD', 'EUR', 'GBP', 'INR', 'AUD', 'CAD', 'SGD', 'AED'].map((c) => <option key={c}>{c}</option>)}
            </Select>
          </Field>
        </div>
        <Field label="Work email" error={errors.email} required>
          <Input type="email" value={form.email} onChange={set('email')} required />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Password" error={errors.password} required>
            <Input type="password" value={form.password} onChange={set('password')} minLength={8} required />
          </Field>
          <Field label="Confirm" required>
            <Input type="password" value={form.password_confirmation} onChange={set('password_confirmation')} required />
          </Field>
        </div>
        <Button type="submit" size="lg" className="w-full" loading={isLoading}>Create workspace</Button>
      </form>
      <p className="mt-6 text-center text-sm text-slate-500">
        Already have an account? <Link to="/login" className="font-medium text-brand-600 hover:text-brand-700">Sign in</Link>
      </p>
    </AuthLayout>
  )
}
