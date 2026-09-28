import { useEffect } from 'react'
import clsx from 'clsx'
import { CheckCircle2, Info, X, XCircle } from 'lucide-react'
import { useAppDispatch, useAppSelector } from '@/app/hooks'
import { toastDismissed, type Toast } from '@/features/ui/uiSlice'

const icons = {
  success: <CheckCircle2 className="size-5 text-emerald-500" />,
  error: <XCircle className="size-5 text-rose-500" />,
  info: <Info className="size-5 text-brand-500" />,
}

function ToastItem({ toast }: { toast: Toast }) {
  const dispatch = useAppDispatch()
  useEffect(() => {
    const t = setTimeout(() => dispatch(toastDismissed(toast.id)), toast.kind === 'error' ? 6000 : 3500)
    return () => clearTimeout(t)
  }, [dispatch, toast])

  return (
    <div className={clsx('pointer-events-auto flex w-80 animate-slide-up items-start gap-3 rounded-xl border bg-white p-3.5 shadow-lg shadow-slate-900/10 dark:bg-slate-900',
      toast.kind === 'error' ? 'border-rose-200 dark:border-rose-500/30' : 'border-slate-200 dark:border-slate-700')}>
      {icons[toast.kind]}
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium text-slate-900 dark:text-white">{toast.title}</p>
        {toast.description && <p className="mt-0.5 text-xs text-slate-500">{toast.description}</p>}
      </div>
      <button onClick={() => dispatch(toastDismissed(toast.id))} className="text-slate-400 hover:text-slate-600" aria-label="Dismiss">
        <X className="size-4" />
      </button>
    </div>
  )
}

export function Toaster() {
  const toasts = useAppSelector((s) => s.ui.toasts)
  return (
    <div className="pointer-events-none fixed right-4 bottom-4 z-[60] flex flex-col gap-2" aria-live="polite">
      {toasts.map((t) => (
        <ToastItem key={t.id} toast={t} />
      ))}
    </div>
  )
}
