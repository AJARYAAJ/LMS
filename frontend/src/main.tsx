import { handleNativeTaps } from '@/lib/push'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { Provider } from 'react-redux'
import { BrowserRouter } from 'react-router-dom'
import { store } from '@/app/store'
import { applyTheme } from '@/features/ui/uiSlice'
import { installSpotlight } from '@/lib/spotlight'
import App from './App'
import './index.css'

applyTheme(store.getState().ui.theme)
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyTheme(store.getState().ui.theme))
installSpotlight()

// Installable app (PWA): register the service worker in production builds only.
handleNativeTaps()

if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => undefined))
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Provider store={store}>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </Provider>
  </StrictMode>,
)
