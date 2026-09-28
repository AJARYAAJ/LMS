import { useEffect, useState } from 'react'
import clsx from 'clsx'
import { Monitor, Moon, Sun, UserCircle } from 'lucide-react'
import { useAction, useAppDispatch, useAppSelector, useCurrentUser } from '@/app/hooks'
import { setTheme, type Theme } from '@/features/ui/uiSlice'
import { useUpdatePasswordMutation, useUpdateProfileMutation } from '@/services/api'
import { Avatar, Badge, Button, Card, ColorPicker, Field, Input, PageHeader } from '@/components/ui'
import { ROLE_LABELS } from '@/lib/constants'

export function ProfilePage() {
  const run = useAction()
  const dispatch = useAppDispatch()
  const me = useCurrentUser()
  const theme = useAppSelector((s) => s.ui.theme)
  const [updateProfile, profileState] = useUpdateProfileMutation()
  const [updatePassword, passwordState] = useUpdatePasswordMutation()
  const [form, setForm] = useState({ name: '', email: '', phone: '', job_title: '', avatar_color: '#8b5cf6' })
  const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' })

  useEffect(() => { if (me) setForm({ name: me.name, email: me.email, phone: me.phone ?? '', job_title: me.job_title ?? '', avatar_color: me.avatar_color ?? '#8b5cf6' }) }, [me])

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader icon={<UserCircle />} title="Profile & preferences" />
      <div className="card mb-6 flex items-center gap-5 overflow-hidden p-6">
        <div className="absolute inset-0 -z-10 opacity-40" style={{ background: `radial-gradient(60% 120% at 0% 0%, ${form.avatar_color}66, transparent 60%)` }} />
        <Avatar name={form.name} color={form.avatar_color} size="xl" />
        <div>
          <h2 className="text-2xl font-bold text-slate-900 dark:text-white">{me?.name}</h2>
          <p className="text-slate-500">{me?.email}</p>
          <div className="mt-2 flex gap-2"><Badge color="#8b5cf6">{ROLE_LABELS[me?.role ?? '']}</Badge><Badge>{me?.organization?.name}</Badge></div>
        </div>
      </div>
      <div className="grid gap-6 md:grid-cols-2">
        <Card title="Personal details">
          <div className="space-y-4">
            <Field label="Name"><Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} /></Field>
            <Field label="Email"><Input type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} /></Field>
            <Field label="Phone"><Input value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} /></Field>
            <Field label="Job title"><Input value={form.job_title} onChange={(e) => setForm((f) => ({ ...f, job_title: e.target.value }))} /></Field>
            <Field label="Avatar colour"><ColorPicker value={form.avatar_color} onChange={(c) => setForm((f) => ({ ...f, avatar_color: c }))} /></Field>
            <div className="flex justify-end"><Button loading={profileState.isLoading} onClick={() => run(updateProfile(form), 'Profile saved')}>Save</Button></div>
          </div>
        </Card>
        <div className="space-y-6">
          <Card title="Appearance">
            <div className="grid grid-cols-3 gap-3">
              {([['light', Sun, 'Light'], ['dark', Moon, 'Dark'], ['system', Monitor, 'System']] as [Theme, typeof Sun, string][]).map(([v, Icon, l]) => (
                <button key={v} onClick={() => dispatch(setTheme(v))} className={clsx('flex flex-col items-center gap-2 rounded-2xl border p-4 text-sm font-medium transition', theme === v ? 'border-brand-400 bg-brand-50 text-brand-700 shadow-[0_8px_24px_-12px_rgba(139,92,246,0.6)] dark:bg-brand-500/15 dark:text-brand-200' : 'border-slate-200 dark:border-white/10')}>
                  <Icon className="size-5" />{l}
                </button>
              ))}
            </div>
          </Card>
          <Card title="Change password">
            <div className="space-y-4">
              <Field label="Current password"><Input type="password" value={pw.current_password} onChange={(e) => setPw((p) => ({ ...p, current_password: e.target.value }))} /></Field>
              <Field label="New password"><Input type="password" value={pw.password} onChange={(e) => setPw((p) => ({ ...p, password: e.target.value }))} /></Field>
              <Field label="Confirm"><Input type="password" value={pw.password_confirmation} onChange={(e) => setPw((p) => ({ ...p, password_confirmation: e.target.value }))} /></Field>
              <div className="flex justify-end"><Button loading={passwordState.isLoading} disabled={!pw.password} onClick={async () => { if (await run(updatePassword(pw), 'Password updated') !== undefined) setPw({ current_password: '', password: '', password_confirmation: '' }) }}>Update password</Button></div>
            </div>
          </Card>
        </div>
      </div>
    </div>
  )
}
