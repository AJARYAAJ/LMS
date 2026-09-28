import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react'
import clsx from 'clsx'
import { Loader2 } from 'lucide-react'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'subtle'
type Size = 'xs' | 'sm' | 'md' | 'lg'

const variants: Record<Variant, string> = {
  primary:
    'bg-[linear-gradient(135deg,#7c3aed,#c026d3_60%,#db2777)] bg-[length:160%_160%] bg-left text-white shadow-[0_8px_24px_-8px_rgba(192,38,211,0.6),inset_0_1px_0_rgba(255,255,255,0.25)] hover:bg-right hover:shadow-[0_12px_32px_-8px_rgba(192,38,211,0.75),inset_0_1px_0_rgba(255,255,255,0.25)] focus-visible:ring-fuchsia-500/40',
  secondary:
    'border border-slate-200/90 bg-white/70 text-slate-700 shadow-sm hover:border-brand-200 hover:bg-white focus-visible:ring-brand-400/30 dark:border-white/10 dark:bg-white/[0.05] dark:text-slate-200 dark:hover:bg-white/[0.09]',
  ghost: 'text-slate-600 hover:bg-slate-900/[0.05] hover:text-slate-900 dark:text-slate-400 dark:hover:bg-white/[0.07] dark:hover:text-white',
  subtle: 'bg-brand-50 text-brand-700 hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:hover:bg-brand-500/20',
  danger: 'bg-rose-600 text-white shadow-sm hover:bg-rose-700 focus-visible:ring-rose-500/40',
}

const sizes: Record<Size, string> = {
  xs: 'h-7 px-2.5 text-xs gap-1.5 rounded-md',
  sm: 'h-8 px-3 text-sm gap-1.5 rounded-xl',
  md: 'h-10 px-4 text-sm gap-2 rounded-xl',
  lg: 'h-12 px-6 text-sm gap-2 rounded-2xl',
}

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: Size
  loading?: boolean
  icon?: ReactNode
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', size = 'md', loading, icon, className, children, disabled, type = 'button', ...props },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      className={clsx(
        'inline-flex shrink-0 items-center justify-center font-medium whitespace-nowrap transition-all duration-300 focus-visible:ring-4 focus-visible:outline-none active:scale-[0.98] disabled:pointer-events-none disabled:opacity-50',
        variants[variant],
        sizes[size],
        className,
      )}
      {...props}
    >
      {loading ? <Loader2 className="size-4 animate-spin" /> : icon}
      {children}
    </button>
  )
})

export function IconButton({ className, label, ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { label: string }) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      className={clsx(
        'inline-flex size-9 items-center justify-center rounded-xl text-slate-500 transition hover:bg-slate-900/[0.05] hover:text-slate-900 focus-visible:ring-4 focus-visible:ring-brand-500/20 focus-visible:outline-none dark:text-slate-400 dark:hover:bg-white/[0.07] dark:hover:text-white',
        className,
      )}
      {...props}
    />
  )
}
