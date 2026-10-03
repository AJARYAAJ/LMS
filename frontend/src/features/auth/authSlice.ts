import { createSlice, type PayloadAction } from '@reduxjs/toolkit'
import type { User } from '@/types'

const TOKEN_KEY = 'lms.token'

function readToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

interface AuthState {
  token: string | null
  user: User | null
}

const initialState: AuthState = { token: readToken(), user: null }

const authSlice = createSlice({
  name: 'auth',
  initialState,
  reducers: {
    credentialsReceived(state, action: PayloadAction<{ token: string; user: User }>) {
      state.token = action.payload.token
      state.user = action.payload.user
      try {
        localStorage.setItem(TOKEN_KEY, action.payload.token)
      } catch {
        /* storage unavailable */
      }
    },
    userLoaded(state, action: PayloadAction<User>) {
      state.user = action.payload
    },
    loggedOut(state) {
      state.token = null
      state.user = null
      try {
        localStorage.removeItem(TOKEN_KEY)
      } catch {
        /* storage unavailable */
      }
    },
  },
})

export const { credentialsReceived, userLoaded, loggedOut } = authSlice.actions
export default authSlice.reducer
