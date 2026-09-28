import { Link } from 'react-router-dom'
import { Compass } from 'lucide-react'
import { Button } from '@/components/ui'

export function NotFoundPage() {
  return (
    <div className="flex flex-col items-center justify-center py-24 text-center">
      <p className="font-display text-8xl font-extrabold gradient-text">404</p>
      <p className="mt-2 text-lg font-semibold text-slate-900 dark:text-white">Lost in the aurora</p>
      <p className="mt-1 text-sm text-slate-500">That page drifted away.</p>
      <Link to="/" className="mt-6"><Button icon={<Compass className="size-4" />}>Back to Pulse</Button></Link>
    </div>
  )
}
