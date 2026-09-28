import { useEffect, useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import clsx from 'clsx'
import { AlertTriangle, X } from 'lucide-react'
import { Button } from './Button'

function useEscape(open: boolean, onClose: () => void) {
  useEffect(() => {
    if (!open) return
    const handler = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', handler)
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', handler)
      document.body.style.overflow = overflow
    }
  }, [open, onClose])
}

export function Modal({ open, onClose, title, description, children, footer, size = 'md' }: {
  open: boolean
  onClose: () => void
  title?: ReactNode
  description?: ReactNode
  children: ReactNode
  footer?: ReactNode
  size?: 'sm' | 'md' | 'lg' | 'xl'
}) {
  useEscape(open, onClose)
  if (!open) return null
  const widths = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4">
      <div className="absolute inset-0 animate-fade-in bg-slate-950/50 backdrop-blur-sm" onClick={onClose} />
      <div
        role="dialog"
        aria-modal="true"
        className={clsx('relative flex max-h-[92vh] w-full animate-slide-up flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl dark:bg-slate-900 dark:ring-1 dark:ring-slate-800', widths[size])}
      >
        {(title || description) && (
          <div className="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-4 dark:border-slate-800">
            <div>
              {title && <h2 className="text-base font-semibold text-slate-900 dark:text-white">{title}</h2>}
              {description && <p className="mt-0.5 text-sm text-slate-500">{description}</p>}
            </div>
            <button onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800" aria-label="Close">
              <X className="size-5" />
            </button>
          </div>
        )}
        <div className="overflow-y-auto px-6 py-5">{children}</div>
        {footer && <div className="flex justify-end gap-2 border-t border-slate-100 bg-slate-50/60 px-6 py-3 dark:border-slate-800 dark:bg-slate-900/60">{footer}</div>}
      </div>
    </div>,
    document.body,
  )
}

export function Drawer({ open, onClose, title, children, footer, width = 'max-w-xl' }: {
  open: boolean
  onClose: () => void
  title?: ReactNode
  children: ReactNode
  footer?: ReactNode
  width?: string
}) {
  useEscape(open, onClose)
  if (!open) return null

  return createPortal(
    <div className="fixed inset-0 z-50">
      <div className="absolute inset-0 animate-fade-in bg-slate-950/40 backdrop-blur-[2px]" onClick={onClose} />
      <div className={clsx('absolute inset-y-0 right-0 flex w-full animate-slide-in-right flex-col bg-white shadow-2xl dark:bg-slate-900 dark:ring-1 dark:ring-slate-800', width)}>
        <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4 dark:border-slate-800">
          <h2 className="text-base font-semibold text-slate-900 dark:text-white">{title}</h2>
          <button onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800" aria-label="Close">
            <X className="size-5" />
          </button>
        </div>
        <div className="flex-1 overflow-y-auto px-6 py-5">{children}</div>
        {footer && <div className="flex justify-end gap-2 border-t border-slate-100 px-6 py-3 dark:border-slate-800">{footer}</div>}
      </div>
    </div>,
    document.body,
  )
}

export function ConfirmDialog({ open, onClose, onConfirm, title, message, confirmLabel = 'Delete', loading, danger = true }: {
  open: boolean
  onClose: () => void
  onConfirm: () => void
  title: string
  message?: ReactNode
  confirmLabel?: string
  loading?: boolean
  danger?: boolean
}) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button variant={danger ? 'danger' : 'primary'} loading={loading} onClick={onConfirm}>{confirmLabel}</Button>
        </>
      }
    >
      <div className="flex gap-4">
        <div className={clsx('flex size-10 shrink-0 items-center justify-center rounded-full', danger ? 'bg-rose-100 text-rose-600 dark:bg-rose-500/15' : 'bg-brand-100 text-brand-600')}>
          <AlertTriangle className="size-5" />
        </div>
        <div>
          <h3 className="font-semibold text-slate-900 dark:text-white">{title}</h3>
          {message && <div className="mt-1 text-sm text-slate-500">{message}</div>}
        </div>
      </div>
    </Modal>
  )
}

/** Small click-to-open popover menu. */
export function Menu({ trigger, children, align = 'right', width = 'w-56' }: {
  trigger: (props: { open: boolean; toggle: () => void }) => ReactNode
  children: ReactNode | ((close: () => void) => ReactNode)
  align?: 'left' | 'right'
  width?: string
}) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const handler = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false)
    const esc = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('mousedown', handler)
    document.addEventListener('keydown', esc)
    return () => {
      document.removeEventListener('mousedown', handler)
      document.removeEventListener('keydown', esc)
    }
  }, [open])

  const close = () => setOpen(false)

  return (
    <div ref={ref} className="relative">
      {trigger({ open, toggle: () => setOpen((o) => !o) })}
      {open && (
        <div
          className={clsx(
            'absolute z-40 mt-2 animate-slide-up overflow-hidden rounded-xl border border-slate-200 bg-white p-1 shadow-xl shadow-slate-900/10 dark:border-slate-700 dark:bg-slate-900',
            align === 'right' ? 'right-0' : 'left-0',
            width,
          )}
        >
          {typeof children === 'function' ? children(close) : children}
        </div>
      )}
    </div>
  )
}

export function MenuItem({ icon, children, onClick, danger, active }: {
  icon?: ReactNode
  children: ReactNode
  onClick?: () => void
  danger?: boolean
  active?: boolean
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={clsx(
        'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm transition',
        danger ? 'text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
        active && 'bg-slate-100 dark:bg-slate-800',
      )}
    >
      {icon && <span className="text-slate-400 [&>svg]:size-4">{icon}</span>}
      {children}
    </button>
  )
}
