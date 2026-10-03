import { Suspense, useEffect } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import clsx from 'clsx'
import { useAppDispatch, useAppSelector } from '@/app/hooks'
import { userLoaded } from '@/features/auth/authSlice'
import { useMeQuery } from '@/services/api'
import { PageLoader, Spinner } from '@/components/ui'
import { Sidebar } from './Sidebar'
import { NotificationPrompt } from './NotificationPrompt'
import { Topbar } from './Topbar'
import { CommandPalette } from './CommandPalette'

export function AppLayout() {
  const dispatch = useAppDispatch()
  const location = useLocation()
  const token = useAppSelector((s) => s.auth.token)
  const user = useAppSelector((s) => s.auth.user)
  const collapsed = useAppSelector((s) => s.ui.sidebarCollapsed)
  const { data: me, isError } = useMeQuery(undefined, { skip: !token })

  useEffect(() => {
    if (me) dispatch(userLoaded(me))
  }, [me, dispatch])

  if (!token || isError) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />

  if (!user) {
    return (
      <div className="flex h-screen items-center justify-center">
        <Spinner className="size-8" />
      </div>
    )
  }

  return (
    <div className="min-h-screen">
      <Aurora />
      <Sidebar />
      <div className={clsx('pt-3 transition-all duration-500 ease-[cubic-bezier(0.16,1,0.3,1)]', collapsed ? 'lg:pl-[88px]' : 'lg:pl-[260px]')}>
        <Topbar />
        <main key={location.pathname.split('/')[1]} className="mx-auto max-w-[1600px] animate-slide-up px-4 py-7 sm:px-6 lg:px-8">
          <Suspense fallback={<PageLoader />}>
            <Outlet />
          </Suspense>
        </main>
      </div>
      <CommandPalette />
      <NotificationPrompt />
    </div>
  )
}

export function Aurora() {
  return (
    <div className="aurora" aria-hidden>
      <span />
      <span />
      <span />
    </div>
  )
}

export function RequireRole({ allow, children }: { allow: 'admin' | 'manager'; children: React.ReactNode }) {
  const perms = useAppSelector((s) => s.auth.user?.permissions)
  const ok = allow === 'admin' ? perms?.manage_settings : perms?.manage_team
  return ok ? <>{children}</> : <Navigate to="/" replace />
}
