import { lazy, type ComponentType } from 'react'

/**
 * Code-split page that never suspends once its chunk is loaded.
 *
 * React.lazy always suspends on first render, and React 19 keeps a Suspense
 * fallback up for at least ~300ms — so even a prefetched page felt sluggish.
 * Here, once `preload()` has resolved we render the component directly.
 */
export function lazyPage<M extends Record<string, unknown>, K extends keyof M>(loader: () => Promise<M>, name: K) {
  let Loaded: ComponentType | null = null
  let pending: Promise<void> | null = null
  const preload = () => (pending ??= loader().then((m) => { Loaded = m[name] as ComponentType }))
  const Lazy = lazy(() => preload().then(() => ({ default: Loaded as ComponentType })))

  function Page() {
    return Loaded ? <Loaded /> : <Lazy />
  }
  Page.preload = preload
  return Page
}
