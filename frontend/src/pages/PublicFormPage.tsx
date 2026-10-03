import { useState, type FormEvent } from 'react'
import { useParams } from 'react-router-dom'
import { CheckCircle2, Sparkles } from 'lucide-react'
import { fieldErrors, usePublicFormQuery, useSubmitPublicFormMutation } from '@/services/api'
import { Aurora } from '@/components/layout/AppLayout'
import { Field, Input, Spinner, Textarea } from '@/components/ui'

/** Public hosted web-to-lead form (/f/:slug). No authentication. */
export function PublicFormPage() {
  const slug = useParams().slug ?? ''
  const { data: form, isLoading, isError } = usePublicFormQuery(slug)
  const [submit, { isLoading: sending, error, data: done }] = useSubmitPublicFormMutation()
  const [values, setValues] = useState<Record<string, string>>({ _hp: '' })
  const errors = fieldErrors(error)

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      const r = await submit({ slug, body: values }).unwrap()
      if (r.redirect_url) setTimeout(() => { window.location.href = r.redirect_url! }, 1500)
    } catch { /* inline errors */ }
  }

  return (
    <div className="flex min-h-screen items-center justify-center p-4">
      <Aurora />
      <div className="card w-full max-w-lg animate-slide-up overflow-hidden p-0">
        {isLoading ? <div className="flex h-64 items-center justify-center"><Spinner /></div> : isError || !form ? (
          <div className="p-10 text-center"><p className="font-display text-xl font-bold">Form not available</p><p className="mt-1 text-sm text-slate-500">This form may have been disabled.</p></div>
        ) : (
          <>
            <div className="h-1.5" style={{ background: `linear-gradient(90deg, ${form.accent_color}, #d946ef, #06b6d4)` }} />
            <div className="p-8 sm:p-10">
              {done ? (
                <div className="py-10 text-center">
                  <CheckCircle2 className="mx-auto size-14 animate-float" style={{ color: form.accent_color }} />
                  <p className="font-display mt-4 text-2xl font-bold text-slate-900 dark:text-white">{done.message}</p>
                </div>
              ) : (
                <form onSubmit={onSubmit} className="space-y-4">
                  <p className="text-xs font-semibold tracking-[0.14em] text-slate-400 uppercase">{form.organization}</p>
                  <h1 className="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">{form.title || form.name}</h1>
                  {form.description && <p className="text-slate-500">{form.description}</p>}
                  <div className="space-y-4 pt-2">
                    {form.fields.map((f) => (
                      <Field key={f.key} label={f.label} required={f.required} error={errors[f.key]}>
                        {f.type === 'textarea'
                          ? <Textarea rows={4} required={f.required} value={values[f.key] ?? ''} onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))} />
                          : <Input type={f.type} required={f.required} value={values[f.key] ?? ''} onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))} />}
                      </Field>
                    ))}
                    <input tabIndex={-1} autoComplete="off" aria-hidden className="absolute -left-[9999px]" value={values._hp} onChange={(e) => setValues((v) => ({ ...v, _hp: e.target.value }))} />
                  </div>
                  <button type="submit" disabled={sending} className="mt-2 flex h-12 w-full items-center justify-center gap-2 rounded-2xl font-semibold text-white shadow-lg transition hover:brightness-110 disabled:opacity-60" style={{ background: `linear-gradient(135deg, ${form.accent_color}, #c026d3)` }}>
                    {sending ? <Spinner className="size-4 border-white/40 border-t-white" /> : form.submit_label}
                  </button>
                  <p className="flex items-center justify-center gap-1 pt-2 text-[11px] text-slate-400"><Sparkles className="size-3" /> Powered by LeadFlow</p>
                </form>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  )
}
