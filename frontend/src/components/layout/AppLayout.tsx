import { useEffect } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import clsx from 'clsx'
import { useAppDispatch, useAppSelector } from '@/app/hooks'
import { userLoaded } from '@/features/auth/authSlice'
import { useMeQuery } from '@/services/api'
import { Spinner } from '@/components/ui'
import { Sidebar } from './Sidebar'
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

  if (!token || isError) return <Navigate to="/login" replace state={{ from: location.pathname }} />

  if (!user) {
    return (
      <div className="flex h-screen items-center justify-center">
        <Spinner className="size-8" />
      </div>
    )
  }

  return (
    <div className="min-h-screen">
      <Sidebar />
      <div className={clsx('transition-all duration-300', collapsed ? 'lg:pl-[72px]' : 'lg:pl-64')}>
        <Topbar />
        <main className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8">
          <Outlet />
        </main>
      </div>
      <CommandPalette />
    </div>
  )
}

export function RequireRole({ allow, children }: { allow: 'admin' | 'manager'; children: React.ReactNode }) {
  const perms = useAppSelector((s) => s.auth.user?.permissions)
  const ok = allow === 'admin' ? perms?.manage_settings : perms?.manage_team
  return ok ? <>{children}</> : <Navigate to="/" replace />
}
