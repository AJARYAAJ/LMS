/**
 * Global pointer tracker for the `.card` spotlight effect: writes the cursor
 * position into CSS variables on the hovered card only (one listener total).
 */
export function installSpotlight() {
  let frame = 0
  document.addEventListener(
    'pointermove',
    (e) => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(() => {
        const el = (e.target as HTMLElement | null)?.closest?.('.card') as HTMLElement | null
        if (!el) return
        const r = el.getBoundingClientRect()
        el.style.setProperty('--x', `${e.clientX - r.left}px`)
        el.style.setProperty('--y', `${e.clientY - r.top}px`)
      })
    },
    { passive: true },
  )
}
