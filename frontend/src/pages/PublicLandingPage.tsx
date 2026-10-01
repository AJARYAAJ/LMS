import { useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { FileQuestion } from 'lucide-react'
import { usePublicLandingPageQuery } from '@/services/api'
import { EmptyState, PageLoader } from '@/components/ui'
import { LandingView } from '@/components/marketing/LandingBlocks'

/** Public landing page (/p/:slug). */
export function PublicLandingPage() {
  const slug = useParams().slug ?? ''
  const { data, isLoading, isError } = usePublicLandingPageQuery(slug)

  useEffect(() => {
    if (!data) return
    document.title = data.seo_title
    let meta = document.querySelector('meta[name="description"]')
    if (!meta) { meta = document.createElement('meta'); meta.setAttribute('name', 'description'); document.head.appendChild(meta) }
    meta.setAttribute('content', data.seo_description ?? '')
  }, [data])

  if (isLoading) return <div className="min-h-screen bg-white"><PageLoader /></div>
  if (isError || !data) return <div className="flex min-h-screen items-center justify-center bg-white p-6"><EmptyState icon={<FileQuestion />} title="Page not found" description="This page may have been unpublished." /></div>
  return <LandingView page={data} slug={slug} />
}
