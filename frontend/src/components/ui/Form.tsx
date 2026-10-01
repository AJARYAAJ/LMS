import { Children, cloneElement, forwardRef, isValidElement, useId, type InputHTMLAttributes, type ReactElement, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import clsx from 'clsx'
import { Check } from 'lucide-react'
import { COLOR_SWATCHES } from '@/lib/constants'

export function Field({ label, error, hint, children, className, required }: {
  label?: ReactNode
  error?: string
  hint?: ReactNode
  children: ReactNode
  className?: string
  required?: boolean
}) {
  // Tie the label (and hint/error) to the control so screen readers announce them together.
  const auto = useId()
  const only = Children.count(children) === 1 && isValidElement(children) ? (children as ReactElement<{ id?: string; 'aria-describedby'?: string; 'aria-invalid'?: boolean }>) : null
  const id = only?.props.id ?? auto
  const noteId = `${id}-note`
  const note = error || hint
  const control = only
    ? cloneElement(only, { id, ...(note ? { 'aria-describedby': noteId } : {}), ...(error ? { 'aria-invalid': true } : {}) })
    : children

  return (
    <div className={className}>
      {label && (
        <label className="label" htmlFor={only ? id : undefined}>
          {label}
          {required && <span className="ml-0.5 text-rose-500">*</span>}
        </label>
      )}
      {control}
      {error ? <p id={noteId} className="mt-1 text-xs text-rose-600">{error}</p> : hint ? <p id={noteId} className="mt-1 text-xs text-slate-500">{hint}</p> : null}
    </div>
  )
}

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & { icon?: ReactNode; invalid?: boolean }>(
  function Input({ className, icon, invalid, ...props }, ref) {
    if (icon) {
      return (
        <div className="relative">
          <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">{icon}</span>
          <input ref={ref} className={clsx('input pl-9', invalid && 'border-rose-400', className)} {...props} />
        </div>
      )
    }
    return <input ref={ref} className={clsx('input', invalid && 'border-rose-400', className)} {...props} />
  },
)

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(function Textarea(
  { className, rows = 3, ...props },
  ref,
) {
  return <textarea ref={ref} rows={rows} className={clsx('input resize-y', className)} {...props} />
})

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement> & { placeholder?: string }>(function Select(
  { className, children, placeholder, ...props },
  ref,
) {
  return (
    <select ref={ref} className={clsx('input appearance-none bg-[length:16px] bg-[right_0.6rem_center] bg-no-repeat pr-8', className)}
      style={{ backgroundImage: "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E\")" }}
      {...props}
    >
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {children}
    </select>
  )
})

export function Toggle({ checked, onChange, label, description, disabled }: {
  checked: boolean
  onChange: (value: boolean) => void
  label?: ReactNode
  description?: ReactNode
  disabled?: boolean
}) {
  return (
    <label className={clsx('flex cursor-pointer items-start gap-3', disabled && 'cursor-not-allowed opacity-60')}>
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        className={clsx(
          'relative mt-0.5 inline-flex h-5 w-9 shrink-0 rounded-full transition-colors focus-visible:ring-4 focus-visible:ring-brand-500/20 focus-visible:outline-none',
          checked ? 'bg-brand-600' : 'bg-slate-300 dark:bg-slate-700',
        )}
      >
        <span className={clsx('absolute top-0.5 left-0.5 size-4 rounded-full bg-white shadow transition-transform', checked && 'translate-x-4')} />
      </button>
      {(label || description) && (
        <span>
          {label && <span className="block text-sm font-medium text-slate-800 dark:text-slate-200">{label}</span>}
          {description && <span className="block text-xs text-slate-500">{description}</span>}
        </span>
      )}
    </label>
  )
}

export function Checkbox({ checked, onChange, indeterminate, label }: {
  checked: boolean
  onChange: (value: boolean) => void
  indeterminate?: boolean
  label?: string
}) {
  return (
    <button
      type="button"
      role="checkbox"
      aria-checked={indeterminate ? 'mixed' : checked}
      aria-label={label}
      onClick={(e) => {
        e.stopPropagation()
        onChange(!checked)
      }}
      className={clsx(
        'flex size-4 shrink-0 items-center justify-center rounded border transition',
        checked || indeterminate ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-300 bg-white dark:border-slate-600 dark:bg-slate-900',
      )}
    >
      {checked && <Check className="size-3" strokeWidth={3} />}
      {!checked && indeterminate && <span className="h-0.5 w-2 rounded bg-white" />}
    </button>
  )
}

export function ColorPicker({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {COLOR_SWATCHES.map((c) => (
        <button
          key={c}
          type="button"
          aria-label={`Colour ${c}`}
          onClick={() => onChange(c)}
          className={clsx('size-6 rounded-full ring-offset-2 transition dark:ring-offset-slate-900', value === c && 'ring-2 ring-slate-900 dark:ring-white')}
          style={{ backgroundColor: c }}
        />
      ))}
      <input type="color" value={value} onChange={(e) => onChange(e.target.value)} className="size-6 cursor-pointer rounded-full border-0 bg-transparent p-0" />
    </div>
  )
}
