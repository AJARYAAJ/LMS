import { useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { MailX } from 'lucide-react'
import { useUnsubscribeMutation } from '@/services/api'
import { PageLoader } from '@/components/ui'

/** Landing page for the unsubscribe link at the bottom of every email. */
export function UnsubscribePage() {
  const { lead = '', signature = '' } = useParams()
  const [unsubscribe, { data, isLoading, isError }] = useUnsubscribeMutation()
  useEffect(() => { unsubscribe({ lead, signature }) }, [lead, signature, unsubscribe])

  return (
    <div className="flex min-h-screen items-center justify-center bg-[#f6f5fb] p-6 text-slate-800">
      <div className="w-full max-w-md rounded-3xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        {isLoading || (!data && !isError) ? <PageLoader /> : isError ? (
          <><h1 className="text-xl font-bold">Link not valid</h1><p className="mt-2 text-slate-600">This unsubscribe link is broken or incomplete.</p></>
        ) : (
          <>
            <MailX className="mx-auto size-12 text-brand-600" />
            <h1 className="mt-4 text-xl font-bold text-slate-900">You're unsubscribed</h1>
            <p className="mt-2 text-slate-600">{data?.organization} won't email {data?.email ?? 'you'} again.</p>
          </>
        )}
      </div>
    </div>
  )
}
