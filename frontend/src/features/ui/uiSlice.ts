import { createSlice, nanoid, type PayloadAction } from '@reduxjs/toolkit'

export type Theme = 'light' | 'dark' | 'system'
export type ToastKind = 'success' | 'error' | 'info'

export interface Toast {
  id: string
  kind: ToastKind
  title: string
  description?: string
}

interface UiState {
  theme: Theme
  sidebarCollapsed: boolean
  mobileNavOpen: boolean
  commandOpen: boolean
  toasts: Toast[]
}

function read<T extends string>(key: string, fallback: T): T {
  try {
    return (localStorage.getItem(key) as T) || fallback
  } catch {
    return fallback
  }
}

function write(key: string, value: string) {
  try {
    localStorage.setItem(key, value)
  } catch {
    /* storage unavailable */
  }
}

export function applyTheme(theme: Theme) {
  const dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
  document.documentElement.classList.toggle('dark', dark)
}

const initialState: UiState = {
  theme: read<Theme>('lms.theme', 'system'),
  sidebarCollapsed: read('lms.sidebar', 'open') === 'collapsed',
  mobileNavOpen: false,
  commandOpen: false,
  toasts: [],
}

const uiSlice = createSlice({
  name: 'ui',
  initialState,
  reducers: {
    setTheme(state, action: PayloadAction<Theme>) {
      state.theme = action.payload
      write('lms.theme', action.payload)
      applyTheme(action.payload)
    },
    toggleSidebar(state) {
      state.sidebarCollapsed = !state.sidebarCollapsed
      write('lms.sidebar', state.sidebarCollapsed ? 'collapsed' : 'open')
    },
    setMobileNav(state, action: PayloadAction<boolean>) {
      state.mobileNavOpen = action.payload
    },
    setCommandOpen(state, action: PayloadAction<boolean>) {
      state.commandOpen = action.payload
    },
    toastAdded: {
      reducer(state, action: PayloadAction<Toast>) {
        state.toasts.push(action.payload)
        if (state.toasts.length > 4) state.toasts.shift()
      },
      prepare(toast: Omit<Toast, 'id'>) {
        return { payload: { ...toast, id: nanoid() } }
      },
    },
    toastDismissed(state, action: PayloadAction<string>) {
      state.toasts = state.toasts.filter((t) => t.id !== action.payload)
    },
  },
})

export const { setTheme, toggleSidebar, setMobileNav, setCommandOpen, toastAdded, toastDismissed } = uiSlice.actions
export default uiSlice.reducer
