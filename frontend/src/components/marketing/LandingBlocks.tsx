import { useState, type FormEvent } from 'react'
import { CheckCircle2, Quote } from 'lucide-react'
import { fieldErrors, useSubmitLandingPageMutation } from '@/services/api'
import { Field, Input, Spinner, Textarea } from '@/components/ui'
import type { LandingBlock, WebFormField } from '@/types'

export interface LandingContent {
  name: string
  blocks: LandingBlock[]
  accent_color: string
  organization: string
  form: { fields: WebFormField[]; submit_label: string; success_message: string; redirect_url: string | null } | null
}

const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']

function LeadForm({ slug, form, accent, preview }: { slug: string; form: NonNullable<LandingContent['form']>; accent: string; preview?: boolean }) {
  const [submit, { isLoading, error, data: done }] = useSubmitLandingPageMutation()
  const [values, setValues] = useState<Record<string, string>>({ _hp: '' })
  const errors = fieldErrors(error)

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault()
    if (preview) return
    // Carry the visitor's UTM tags through to the lead.
    const params = new URLSearchParams(window.location.search)
    const utm = Object.fromEntries(UTM.filter((k) => params.get(k)).map((k) => [k, params.get(k)!]))
    try {
      const r = await submit({ slug, body: { ...values, ...utm } }).unwrap()
      if (r.redirect_url) setTimeout(() => { window.location.href = r.redirect_url! }, 1500)
    } catch { /* inline errors */ }
  }

  if (done) {
    return <div className="py-8 text-center"><CheckCircle2 className="mx-auto size-12" style={{ color: accent }} /><p className="mt-3 text-xl font-bold text-slate-900">{done.message}</p></div>
  }
  return (
    <form onSubmit={onSubmit} className="space-y-4 text-left">
      {form.fields.map((f) => (
        <Field key={f.key} label={f.label} required={f.required} error={errors[f.key]}>
          {f.type === 'textarea'
            ? <Textarea rows={3} required={f.required} value={values[f.key] ?? ''} onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))} />
            : <Input type={f.type} required={f.required} value={values[f.key] ?? ''} onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))} />}
        </Field>
      ))}
      <input tabIndex={-1} autoComplete="off" aria-hidden className="absolute -left-[9999px]" value={values._hp} onChange={(e) => setValues((v) => ({ ...v, _hp: e.target.value }))} />
      <button type="submit" disabled={isLoading} className="flex h-12 w-full items-center justify-center rounded-xl font-semibold text-white shadow-md transition hover:brightness-110 disabled:opacity-60" style={{ background: accent }}>
        {isLoading ? <Spinner className="size-4 border-white/40 border-t-white" /> : form.submit_label}
      </button>
    </form>
  )
}

/** Renders a landing page from its blocks (used by the public page and the editor's live preview). */
export function LandingView({ page, slug, preview }: { page: LandingContent; slug: string; preview?: boolean }) {
  const accent = page.accent_color
  const toForm = () => document.getElementById(preview ? 'lp-preview-form' : 'lp-form')?.scrollIntoView({ behavior: 'smooth' })
  const button = (label?: string) => label ? <button type="button" onClick={toForm} className="mt-6 rounded-xl px-6 py-3 font-semibold text-white shadow-md transition hover:brightness-110" style={{ background: accent }}>{label}</button> : null

  return (
    <div className="bg-white text-slate-800">
      {page.blocks.map((b, i) => {
        switch (b.type) {
          case 'hero':
            return (
              <section key={i} className="px-6 py-16 sm:py-24" style={{ background: `linear-gradient(160deg, ${accent}1f, #ffffff 70%)` }}>
                <div className={b.image_url ? 'mx-auto grid max-w-5xl items-center gap-10 md:grid-cols-2' : 'mx-auto max-w-3xl text-center'}>
                  <div>
                    <p className="text-xs font-semibold tracking-[0.14em] uppercase" style={{ color: accent }}>{page.organization}</p>
                    <h1 className="mt-3 text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl">{b.heading}</h1>
                    {b.subheading && <p className="mt-4 text-lg text-slate-600">{b.subheading}</p>}
                    {button(b.button_label)}
                  </div>
                  {b.image_url && <img src={b.image_url} alt="" className="w-full rounded-2xl shadow-lg" />}
                </div>
              </section>
            )
          case 'text':
            return <section key={i} className="mx-auto max-w-3xl px-6 py-12">{b.heading && <h2 className="text-2xl font-bold text-slate-900">{b.heading}</h2>}<p className="mt-3 whitespace-pre-line text-slate-600">{b.body}</p></section>
          case 'features':
            return (
              <section key={i} className="mx-auto max-w-5xl px-6 py-12">
                {b.heading && <h2 className="text-center text-2xl font-bold text-slate-900">{b.heading}</h2>}
                <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                  {(b.items ?? []).map((it, j) => (
                    <div key={j} className="rounded-2xl border border-slate-200 p-5">
                      <span className="mb-3 block h-1 w-10 rounded-full" style={{ background: accent }} />
                      <h3 className="font-semibold text-slate-900">{it.title}</h3>
                      {it.body && <p className="mt-1 text-sm text-slate-600">{it.body}</p>}
                    </div>
                  ))}
                </div>
              </section>
            )
          case 'testimonial':
            return (
              <section key={i} className="mx-auto max-w-3xl px-6 py-12 text-center">
                <Quote className="mx-auto size-8" style={{ color: accent }} aria-hidden />
                <blockquote className="mt-4 text-xl font-medium text-slate-800">“{b.quote}”</blockquote>
                <p className="mt-3 text-sm text-slate-500">{b.author}{b.role ? ` · ${b.role}` : ''}</p>
              </section>
            )
          case 'form':
            return (
              <section key={i} id={preview ? 'lp-preview-form' : 'lp-form'} className="px-6 py-12" style={{ background: `${accent}0d` }}>
                <div className="mx-auto max-w-md rounded-3xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
                  {b.heading && <h2 className="text-2xl font-bold text-slate-900">{b.heading}</h2>}
                  {b.body && <p className="mt-2 text-slate-600">{b.body}</p>}
                  <div className="mt-5">{page.form ? <LeadForm slug={slug} form={page.form} accent={accent} preview={preview} /> : <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">Pick a web form for this page.</p>}</div>
                </div>
              </section>
            )
          case 'cta':
            return (
              <section key={i} className="px-6 py-14 text-center text-white" style={{ background: accent }}>
                <h2 className="text-3xl font-bold">{b.heading}</h2>
                {b.body && <p className="mx-auto mt-3 max-w-xl text-white/85">{b.body}</p>}
                {b.button_label && <button type="button" onClick={toForm} className="mt-6 rounded-xl bg-white px-6 py-3 font-semibold shadow-md" style={{ color: accent }}>{b.button_label}</button>}
              </section>
            )
          default:
            return null
        }
      })}
      <footer className="py-8 text-center text-xs text-slate-400">© {page.organization} · Built with LeadFlow</footer>
    </div>
  )
}
