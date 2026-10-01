import { useState } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { Check, KeyRound, ShieldCheck } from 'lucide-react'
import { useAppSelector } from '@/app/hooks'
import { errorMessage, useApproveOAuthMutation, useDescribeOAuthQuery } from '@/services/api'
import { Button, EmptyState, PageLoader } from '@/components/ui'

/** "Allow <app> to access your LeadFlow account?" — the OAuth consent screen. */
export function OAuthConsentPage() {
  const location = useLocation()
  const token = useAppSelector((s) => s.auth.token)
  const params = Object.fromEntries(new URLSearchParams(location.search))
  const { data, error, isLoading } = useDescribeOAuthQuery(params, { skip: !token })
  const [approve, state] = useApproveOAuthMutation()
  const [leaving, setLeaving] = useState(false)

  if (!token) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />

  const answer = async (allow: boolean) => {
    try {
      const { redirect } = await approve({ ...params, approve: allow }).unwrap()
      setLeaving(true)
      window.location.assign(redirect)
    } catch { /* shown below */ }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-[#f6f5fb] p-6 dark:bg-slate-950">
      <div className="w-full max-w-md rounded-3xl bg-white p-8 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-white/10">
        {isLoading ? <PageLoader /> : error || !data ? (
          <EmptyState icon={<KeyRound />} title="This app can’t connect" description={errorMessage(error, 'The link is missing details.')} />
        ) : (
          <>
            <span className="flex size-12 items-center justify-center rounded-2xl bg-brand-500/10 text-brand-600"><ShieldCheck className="size-6" /></span>
            <h1 className="mt-4 text-xl font-bold text-slate-900 dark:text-white">Allow {data.app.name} to access your LeadFlow account?</h1>
            <p className="mt-1 text-sm text-slate-500">In the {data.organization} workspace, {data.app.name} will be able to:</p>
            <ul className="mt-4 space-y-2">
              {data.scopes.map((s) => <li key={s.key} className="flex gap-2 text-sm text-slate-700 dark:text-slate-200"><Check className="mt-0.5 size-4 shrink-0 text-emerald-600" />{s.label}</li>)}
            </ul>
            <p className="mt-4 text-xs text-slate-500">It can’t see your password or change workspace settings. You’ll be sent back to <strong>{data.redirect_host}</strong>. An admin can disconnect the app any time.</p>
            {state.error && <p className="mt-3 text-sm text-rose-600" role="alert">{errorMessage(state.error)}</p>}
            <div className="mt-6 flex gap-2">
              <Button className="flex-1" loading={state.isLoading || leaving} onClick={() => answer(true)}>Allow</Button>
              <Button className="flex-1" variant="secondary" disabled={state.isLoading || leaving} onClick={() => answer(false)}>Deny</Button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}
