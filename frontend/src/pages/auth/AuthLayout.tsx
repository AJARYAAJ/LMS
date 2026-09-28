import type { ReactNode } from 'react'
import { BarChart3, Sparkles, Workflow, Zap } from 'lucide-react'

const highlights = [
  { icon: Zap, title: 'Smart routing', text: 'Round-robin, territory and score-based assignment rules.' },
  { icon: Workflow, title: 'Automations', text: 'WHEN → IF → THEN workflows that create tasks and notify owners.' },
  { icon: BarChart3, title: 'Live analytics', text: 'Funnels, source ROI and rep performance in real time.' },
]

export function AuthLayout({ title, subtitle, children }: { title: string; subtitle: ReactNode; children: ReactNode }) {
  return (
    <div className="grid min-h-screen lg:grid-cols-2">
      <div className="flex items-center justify-center px-6 py-12 sm:px-12">
        <div className="w-full max-w-sm animate-slide-up">
          <div className="mb-8 flex items-center gap-2.5">
            <div className="flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30">
              <Sparkles className="size-5" />
            </div>
            <span className="text-lg font-bold tracking-tight text-slate-900 dark:text-white">LeadFlow</span>
          </div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{title}</h1>
          <p className="mt-1.5 text-sm text-slate-500">{subtitle}</p>
          <div className="mt-8">{children}</div>
        </div>
      </div>

      <div className="relative hidden overflow-hidden bg-gradient-to-br from-brand-600 via-indigo-700 to-violet-800 lg:block">
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(255,255,255,0.18),transparent_45%),radial-gradient(circle_at_80%_70%,rgba(236,72,153,0.35),transparent_45%)]" />
        <div className="absolute inset-0 opacity-20 [background-image:linear-gradient(rgba(255,255,255,.15)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.15)_1px,transparent_1px)] [background-size:44px_44px]" />
        <div className="relative flex h-full flex-col justify-between p-12 text-white">
          <div>
            <p className="text-sm font-medium text-white/70">Lead Management Platform</p>
            <h2 className="mt-3 max-w-md text-4xl leading-tight font-semibold tracking-tight">
              Capture, qualify and convert every lead — in one workspace.
            </h2>
          </div>

          <div className="rounded-2xl border border-white/15 bg-white/10 p-5 shadow-2xl backdrop-blur-md">
            <div className="flex items-center justify-between text-xs text-white/70">
              <span>Pipeline this month</span>
              <span className="rounded-full bg-emerald-400/20 px-2 py-0.5 font-medium text-emerald-200">+18.4%</span>
            </div>
            <div className="mt-4 flex h-24 items-end gap-2">
              {[35, 52, 44, 68, 57, 80, 74, 92, 86, 100].map((h, i) => (
                <div key={i} className="flex-1 rounded-t-md bg-gradient-to-t from-white/30 to-white/80" style={{ height: `${h}%` }} />
              ))}
            </div>
          </div>

          <ul className="space-y-4">
            {highlights.map((h) => (
              <li key={h.title} className="flex gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/15 ring-1 ring-white/20">
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
