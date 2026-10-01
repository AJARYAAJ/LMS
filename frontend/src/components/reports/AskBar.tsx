import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { LayoutDashboard, Sparkles, Wand2, X } from 'lucide-react'
import { useAction } from '@/app/hooks'
import { useAskReportMutation } from '@/services/api'
import { Button } from '@/components/ui'
import { ReportCard } from '@/components/reports/ReportChart'
import { AddToDashboardModal } from '@/pages/reports/DashboardsView'
import type { AskAnswer, DashboardTile } from '@/types'

const EXAMPLES = ['Won revenue by rep this quarter', 'Lead sources over time', 'Why are deals lost?', 'Overdue tasks per person', 'AI call outcomes mix']

export const STUDIO_HANDOFF_KEY = 'lms.studio-spec'

/** "Ask anything" box: a plain-English question becomes a chart you can keep. */
export function AskBar() {
  const run = useAction()
  const [, setParams] = useSearchParams()
  const [ask, { isLoading }] = useAskReportMutation()
  const [question, setQuestion] = useState('')
  const [answer, setAnswer] = useState<AskAnswer | null>(null)
  const [tile, setTile] = useState<DashboardTile | null>(null)

  const submit = async (q = question) => {
    if (q.trim().length < 3) return
    setQuestion(q)
    const r = await run(ask(q.trim()))
    if (r) setAnswer(r)
  }

  const openInStudio = () => {
    if (!answer) return
    try { sessionStorage.setItem(STUDIO_HANDOFF_KEY, JSON.stringify(answer.result.spec)) } catch { /* storage may be blocked */ }
    setParams({ tab: 'studio' })
  }

  return (
    <div className="mb-6">
      <form className="card flex items-center gap-3 p-2 pl-4" onSubmit={(e) => { e.preventDefault(); submit() }}>
        <Sparkles className="size-5 shrink-0 text-brand-500" aria-hidden />
        <input value={question} onChange={(e) => setQuestion(e.target.value)} aria-label="Ask a question about your data"
          placeholder="Ask anything — “won revenue by rep this quarter”, “speed to lead by owner”…"
          className="min-w-0 flex-1 bg-transparent py-2 text-sm text-slate-900 outline-none placeholder:text-slate-400 dark:text-white" />
        <Button type="submit" size="sm" loading={isLoading} disabled={question.trim().length < 3}>Ask</Button>
      </form>
      {!answer && (
        <div className="mt-2 flex flex-wrap gap-1.5 pl-1">
          {EXAMPLES.map((e) => <button key={e} onClick={() => submit(e)} className="rounded-full px-2.5 py-1 text-xs text-slate-500 transition hover:bg-brand-500/10 hover:text-brand-700 dark:hover:text-brand-200">{e}</button>)}
        </div>
      )}
      {answer && (
        <div className="mt-4">
          <ReportCard title={answer.title} result={answer.result} height={260}
            subtitle={`${answer.result.entity_label}: ${answer.result.metric_label.toLowerCase()}${answer.result.dimension_label ? ` by ${answer.result.dimension_label.toLowerCase()}` : ''} · ${answer.result.range.label} · ${answer.interpreter === 'claude' ? 'interpreted by Claude' : 'interpreted by keyword rules'}`}
            actions={<button onClick={() => setAnswer(null)} className="rounded-lg p-1.5 text-slate-400 hover:text-slate-700" aria-label="Close answer"><X className="size-4" /></button>}
            footer={
              <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-200/60 pt-4 dark:border-white/[0.06]">
                <Button size="sm" variant="secondary" icon={<LayoutDashboard className="size-4" />} onClick={() => setTile({ id: Math.random().toString(36).slice(2, 10), kind: 'spec', span: 1, title: answer.title, spec: answer.result.spec })}>Add to dashboard</Button>
                <Button size="sm" variant="ghost" icon={<Wand2 className="size-4" />} onClick={openInStudio}>Refine in studio</Button>
              </div>
            } />
        </div>
      )}
      <AddToDashboardModal tile={tile} onClose={() => setTile(null)} />
    </div>
  )
}
