import { ApiError, apiGet } from '@/src/lib/api';
import { clearAuthCookie, getTokenFromCookie } from '@/src/lib/auth-cookie';
import type { User, UserResponse } from '@/src/types/user';
import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

type AuthStatus = 'unknown' | 'validating' | 'authenticated' | 'guest';

interface UserState {
  user: User | null;
  authStatus: AuthStatus;
  authError: string | null;
  isLoading: boolean;
  setUser: (user: User | null) => void;
  fetchUser: () => Promise<void>;
  clearUser: () => void;
}

// Generation guards prevent a request started before logout/token replacement
// from restoring authenticated state after local cleanup.
let generation = 0;
let validatedCredential: string | null = null;
let logoutCredential: string | null | undefined;

// A pre-logout /user response must not clear the credential before logout completes.
export function suspendUserValidation(): () => void {
  logoutCredential = getTokenFromCookie();
  ++generation;
  const user = validatedCredential === logoutCredential ? useUserStore.getState().user : null;
  useUserStore.setState({ user, isLoading: false, authStatus: user ? 'authenticated' : 'unknown' });
  return () => {
    logoutCredential = undefined;
    const currentCredential = getTokenFromCookie();
    if (validatedCredential !== currentCredential) {
      validatedCredential = null;
      if (useUserStore.getState().user || !currentCredential) {
        useUserStore.setState({ user: null, authStatus: currentCredential ? 'unknown' : 'guest', isLoading: false, authError: null });
      }
    }
  };
}

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);
const isNullableString = (value: unknown): boolean => value === null || typeof value === 'string';

function isUser(value: unknown): value is User {
  if (!isRecord(value) || !Number.isSafeInteger(value.id) || Number(value.id) <= 0 ||
      typeof value.name !== 'string' || !value.name.trim() ||
      typeof value.email !== 'string' || !value.email.trim() ||
      !['superadmin', 'affiliate', 'customer'].includes(String(value.role)) ||
      typeof value.email_verified !== 'boolean' || typeof value.is_active !== 'boolean' ||
      !isNullableString(value.telegram_chat_id) || !isNullableString(value.created_at)) return false;

  // Omitted relation is unresolved, not evidence that no affiliate exists.
  if (!Object.prototype.hasOwnProperty.call(value, 'affiliate_profile')) return true;
  const profile = value.affiliate_profile;
  return profile === null || (isRecord(profile) &&
    Number.isSafeInteger(profile.id) && Number(profile.id) > 0 && profile.user_id === value.id &&
    ['pending', 'active', 'rejected', 'inactive'].includes(String(profile.status)));
}

export const useUserStore = create<UserState>()(
  persist(
    (set, get) => ({
      user: null,
      authStatus: 'unknown',
      authError: null,
      isLoading: true,
      setUser: (user) => {
        if (user === null) get().clearUser();
        else void get().fetchUser();
      },
      fetchUser: async () => {
        const request = ++generation;
        const credential = getTokenFromCookie();
        if (logoutCredential !== undefined && credential === logoutCredential) return;
        if (!credential) {
          validatedCredential = null;
          set({ user: null, authStatus: 'guest', authError: null, isLoading: false });
          return;
        }
        const priorUser = validatedCredential === credential ? get().user : null;
        if (!priorUser) validatedCredential = null;
        set({ user: priorUser, authStatus: priorUser ? 'authenticated' : 'validating', authError: null, isLoading: true });
        const isCurrent = () => {
          if (request !== generation) return false;
          const currentCredential = getTokenFromCookie();
          if (credential === currentCredential) return true;
          validatedCredential = null;
          set({ user: null, authStatus: currentCredential ? 'unknown' : 'guest', authError: null, isLoading: false });
          return false;
        };
        try {
          const response = await apiGet<UserResponse>('/user');
          if (!isRecord(response) || !isUser(response.data)) throw new Error('Invalid user response');
          if (isCurrent()) {
            validatedCredential = credential;
            set({ user: response.data, authStatus: 'authenticated', isLoading: false,
              authError: Object.prototype.hasOwnProperty.call(response.data, 'affiliate_profile')
                ? null : 'Status affiliate belum dapat dikonfirmasi. Coba lagi.' });
          }
        } catch (error: unknown) {
          if (!isCurrent()) return;
          if (error instanceof ApiError && error.status === 401) {
            validatedCredential = null;
            clearAuthCookie();
            set({ user: null, authStatus: 'guest', authError: null, isLoading: false });
          } else {
            set({ user: priorUser, authStatus: priorUser ? 'authenticated' : 'unknown', isLoading: false,
              authError: 'Data akun belum dapat dikonfirmasi. Coba lagi.' });
          }
        }
      },
      clearUser: () => {
        ++generation;
        validatedCredential = null;
        set({ user: null, authStatus: 'guest', authError: null, isLoading: false });
      },
    }),
    {
      name: 'auth_user_storage',
      storage: createJSONStorage(() => localStorage),
      partialize: (state) => ({ user: state.user }),
      // Historical persisted flags (including old isLoading) are never trusted.
      merge: (_persisted, current) => current,
    }
  )
);
