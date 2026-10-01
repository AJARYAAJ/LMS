import { useState } from 'react'
import { Copy, Link2, Trash2 } from 'lucide-react'
import { useAction, useToast } from '@/app/hooks'
import { resources, useCreateTrackedLinkMutation, useDeleteTrackedLinkMutation, useSettings, useTrackedLinksQuery } from '@/services/api'
import { Button, Card, EmptyState, Field, Input, PageLoader, Select } from '@/components/ui'
import { ago } from '@/lib/format'

const SOURCES = ['newsletter', 'linkedin', 'facebook', 'instagram', 'google', 'twitter', 'youtube', 'partner']
const MEDIUMS = ['email', 'social', 'paid', 'cpc', 'display', 'referral', 'organic', 'qr']
const slug = (s: string) => s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')

/** Build UTM-tagged links with short, click-counting URLs. */
export function LinkBuilder() {
  const run = useAction()
  const toast = useToast()
  const { data: campaigns } = useSettings(resources.campaigns)
  const { data, isLoading } = useTrackedLinksQuery()
  const [create, state] = useCreateTrackedLinkMutation()
  const [remove] = useDeleteTrackedLinkMutation()
  const [f, setF] = useState({ destination: '', campaign_id: '', utm_source: '', utm_medium: '', utm_campaign: '', utm_content: '', utm_term: '', label: '' })
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }))
  const copy = (v: string) => { navigator.clipboard?.writeText(v); toast('info', 'Copied') }
  const valid = /^https?:\/\/.+\..+/.test(f.destination) && f.utm_source && f.utm_medium && f.utm_campaign

  const submit = async () => {
    const r = await run(create({ ...f, campaign_id: f.campaign_id ? Number(f.campaign_id) : null }), 'Link created')
    if (r) { copy(r.short_url); setF((x) => ({ ...x, utm_content: '', utm_term: '', label: '' })) }
  }

  return (
    <div className="space-y-6">
      <Card title={<span className="flex items-center gap-2"><Link2 className="size-4 text-brand-500" />UTM link builder</span>} subtitle="Tag every link you share so leads arrive with their source and campaign. You get a short link that counts clicks.">
        <div className="grid gap-4 md:grid-cols-2">
          <Field label="Destination URL" required className="md:col-span-2"><Input value={f.destination} onChange={(e) => set('destination', e.target.value)} placeholder="https://yourcompany.com/p/spring-launch" /></Field>
          <Field label="Campaign"><Select value={f.campaign_id} placeholder="None" onChange={(e) => { const c = campaigns?.find((x) => String(x.id) === e.target.value); setF((x) => ({ ...x, campaign_id: e.target.value, utm_campaign: c ? slug(c.name) : x.utm_campaign })) }}>{campaigns?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</Select></Field>
          <Field label="utm_campaign" required><Input value={f.utm_campaign} onChange={(e) => set('utm_campaign', e.target.value)} placeholder="spring-launch" /></Field>
          <Field label="utm_source" required hint="Where: newsletter, linkedin, google…"><Input list="utm-sources" value={f.utm_source} onChange={(e) => set('utm_source', e.target.value)} /></Field>
          <Field label="utm_medium" required hint="How: email, social, cpc…"><Input list="utm-mediums" value={f.utm_medium} onChange={(e) => set('utm_medium', e.target.value)} /></Field>
          <Field label="utm_content" hint="Which ad or link (optional)"><Input value={f.utm_content} onChange={(e) => set('utm_content', e.target.value)} /></Field>
          <Field label="utm_term" hint="Paid keyword (optional)"><Input value={f.utm_term} onChange={(e) => set('utm_term', e.target.value)} /></Field>
          <datalist id="utm-sources">{SOURCES.map((s) => <option key={s} value={s} />)}</datalist>
          <datalist id="utm-mediums">{MEDIUMS.map((s) => <option key={s} value={s} />)}</datalist>
        </div>
        <div className="mt-4 flex justify-end"><Button onClick={submit} loading={state.isLoading} disabled={!valid}>Create link</Button></div>
      </Card>

      <div className="card overflow-x-auto">
        {isLoading ? <PageLoader /> : !data?.length ? <EmptyState icon={<Link2 />} title="No links yet" /> : (
          <table className="w-full text-sm">
            <thead className="text-left text-xs text-slate-500"><tr><th className="px-4 py-3 font-medium">Short link</th><th className="px-4 py-3 font-medium">Goes to</th><th className="px-4 py-3 font-medium">Tags</th><th className="px-4 py-3 text-right font-medium">Clicks</th><th className="px-4 py-3" /></tr></thead>
            <tbody>
              {data.map((l) => (
                <tr key={l.id} className="border-t border-slate-200/60 dark:border-white/[0.06]">
                  <td className="px-4 py-3"><button className="flex items-center gap-1 font-mono text-xs text-brand-600 hover:underline" onClick={() => copy(l.short_url)}><Copy className="size-3.5" />{l.short_url.replace(/^https?:\/\//, '')}</button></td>
                  <td className="max-w-xs truncate px-4 py-3 text-slate-500" title={l.tagged_url}>{l.destination}</td>
                  <td className="px-4 py-3 text-xs text-slate-500">{[l.utm_source, l.utm_medium, l.utm_campaign].join(' · ')}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{l.clicks}{l.last_clicked_at && <span className="block text-[11px] text-slate-400">{ago(l.last_clicked_at)}</span>}</td>
                  <td className="px-4 py-3 text-right"><button className="text-slate-400 hover:text-rose-600" onClick={() => run({ unwrap: () => remove(l.id).unwrap() }, 'Link deleted')} aria-label="Delete link"><Trash2 className="size-4" /></button></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
