interface Payload {
  name?: string
  value?: number | string
  color?: string
  payload?: { fill?: string }
}

export function ChartTooltip({ active, payload, label, formatter }: {
  active?: boolean
  payload?: Payload[]
  label?: string | number
  formatter?: (v: number | string) => string
}) {
  if (!active || !payload?.length) return null
  return (
    <div className="rounded-lg border border-slate-200 bg-white/95 px-3 py-2 text-xs shadow-lg backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
      {label !== undefined && <p className="mb-1 font-medium text-slate-700 dark:text-slate-200">{label}</p>}
      {payload.map((p, i) => (
        <p key={i} className="flex items-center gap-2 text-slate-600 dark:text-slate-300">
          <span className="size-2 rounded-full" style={{ backgroundColor: p.color ?? p.payload?.fill }} />
          <span className="capitalize">{p.name}</span>
          <span className="ml-auto pl-3 font-semibold text-slate-900 dark:text-white">{formatter ? formatter(p.value ?? 0) : p.value}</span>
        </p>
      ))}
    </div>
  )
}
