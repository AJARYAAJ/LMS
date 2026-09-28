import type { ReactNode } from 'react'
import { BarChart3, Sparkles, Workflow, Zap } from 'lucide-react'
import { Aurora } from '@/components/layout/AppLayout'

const highlights = [
  { icon: Zap, title: 'Smart routing', text: 'Round-robin, territory and score-based assignment rules.' },
  { icon: Workflow, title: 'Automations', text: 'WHEN → IF → THEN workflows that create tasks and notify owners.' },
  { icon: BarChart3, title: 'Live analytics', text: 'Funnels, source ROI and rep performance in real time.' },
]

export function AuthLayout({ title, subtitle, children }: { title: string; subtitle: ReactNode; children: ReactNode }) {
  return (
    <div className="grid min-h-screen gap-3 p-3 lg:grid-cols-[1fr_1.1fr]">
      <Aurora />
      <div className="flex items-center justify-center px-4 py-10 sm:px-10">
        <div className="card w-full max-w-md animate-slide-up p-8 sm:p-10">
          <div className="mb-8 flex items-center gap-3">
            <div className="relative flex size-11 items-center justify-center">
              <span className="absolute inset-0 animate-spin-slow rounded-2xl bg-[conic-gradient(from_0deg,#8b5cf6,#d946ef,#06b6d4,#8b5cf6)] blur-[5px]" />
              <span className="relative flex size-11 items-center justify-center rounded-2xl bg-ink-900 text-white"><Sparkles className="size-5" /></span>
            </div>
            <span className="font-display text-xl font-bold tracking-tight text-slate-900 dark:text-white">LeadFlow</span>
          </div>
          <h1 className="text-[28px] font-bold tracking-tight text-slate-900 dark:text-white">{title}</h1>
          <p className="mt-1.5 text-sm text-slate-500 dark:text-slate-400">{subtitle}</p>
          <div className="mt-8">{children}</div>
        </div>
      </div>

      <div className="relative hidden overflow-hidden rounded-[32px] bg-ink-950 lg:block">
        <div className="absolute -top-1/4 -left-1/4 size-[70%] animate-[aurora-a_24s_ease-in-out_infinite] rounded-full bg-[radial-gradient(circle,#7c3aed_0%,transparent_65%)] opacity-70 blur-3xl" />
        <div className="absolute -right-1/4 -bottom-1/4 size-[70%] animate-[aurora-b_30s_ease-in-out_infinite] rounded-full bg-[radial-gradient(circle,#db2777_0%,transparent_65%)] opacity-60 blur-3xl" />
        <div className="absolute top-1/3 left-1/3 size-[45%] animate-[aurora-a_36s_ease-in-out_infinite_reverse] rounded-full bg-[radial-gradient(circle,#0891b2_0%,transparent_65%)] opacity-50 blur-3xl" />
        <div className="absolute inset-0 opacity-20 [background-image:linear-gradient(rgba(255,255,255,.15)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.15)_1px,transparent_1px)] [background-size:44px_44px]" />
        <div className="relative flex h-full flex-col justify-between p-12 text-white">
          <div>
            <p className="text-sm font-medium text-white/70">Lead Management Platform</p>
            <h2 className="mt-4 max-w-lg text-5xl leading-[1.05] font-bold tracking-tight">
              Every lead,{' '}
              <span className="bg-gradient-to-r from-violet-300 via-fuchsia-300 to-cyan-300 bg-clip-text text-transparent">beautifully</span> in flow.
            </h2>
            <p className="mt-4 max-w-md text-white/60">Capture → qualify → assign → follow up → convert. One luminous workspace for the whole revenue team.</p>
          </div>

          <div className="animate-float rounded-3xl border border-white/15 bg-white/[0.07] p-6 shadow-2xl backdrop-blur-xl">
            <div className="flex items-center justify-between text-xs text-white/70">
              <span>Pipeline this month</span>
              <span className="rounded-full bg-emerald-400/20 px-2 py-0.5 font-medium text-emerald-200">+18.4%</span>
            </div>
            <div className="mt-4 flex h-24 items-end gap-2">
              {[35, 52, 44, 68, 57, 80, 74, 92, 86, 100].map((h, i) => (
                <div key={i} className="flex-1 rounded-t-lg bg-gradient-to-t from-fuchsia-500/40 via-violet-400/70 to-cyan-200" style={{ height: `${h}%` }} />
              ))}
            </div>
          </div>

          <ul className="space-y-4">
            {highlights.map((h) => (
              <li key={h.title} className="flex gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/15 backdrop-blur">
                  <h.icon className="size-4" />
                </span>
                <span>
                  <span className="block text-sm font-semibold">{h.title}</span>
                  <span className="block text-sm text-white/70">{h.text}</span>
                </span>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  )
}
