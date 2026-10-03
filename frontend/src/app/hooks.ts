import { useCallback } from 'react'
import { useDispatch, useSelector } from 'react-redux'
import type { AppDispatch, RootState } from './store'
import { toastAdded, type ToastKind } from '@/features/ui/uiSlice'
import { errorMessage } from '@/services/api'

export const useAppDispatch = useDispatch.withTypes<AppDispatch>()
export const useAppSelector = useSelector.withTypes<RootState>()

export function useCurrentUser() {
  return useAppSelector((s) => s.auth.user)
}

export function usePermissions() {
  const user = useCurrentUser()
  return {
    write: user?.permissions?.write ?? false,
    admin: user?.permissions?.manage_settings ?? false,
    manager: user?.permissions?.manage_team ?? false,
  }
}

export function useToast() {
  const dispatch = useAppDispatch()
  return useCallback(
    (kind: ToastKind, title: string, description?: string) => dispatch(toastAdded({ kind, title, description })),
    [dispatch],
  )
}

/**
 * Wrap a mutation call with success / error toasts.
 * Returns the unwrapped result or undefined on failure.
 */
export function useAction() {
  const toast = useToast()
  return useCallback(
    async <T,>(promise: { unwrap: () => Promise<T> }, success?: string): Promise<T | undefined> => {
      try {
        const result = await promise.unwrap()
        if (success) toast('success', success)
        return result
      } catch (err) {
        toast('error', errorMessage(err))
        return undefined
      }
    },
    [toast],
  )
}
