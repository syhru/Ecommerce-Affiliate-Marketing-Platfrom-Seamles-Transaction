import { ApiError, apiGet } from '@/src/lib/api';
import { clearAuthCookie, getTokenFromCookie } from '@/src/lib/auth-cookie';
import type { User } from '@/src/types/user';
import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

type AuthStatus = 'unknown' | 'validating' | 'authenticated' | 'guest';

interface UserState {
  user: User | null;
  authStatus: AuthStatus;
  isLoading: boolean;
  setUser: (user: User | null) => void;
  fetchUser: () => Promise<void>;
  clearUser: () => void;
}

// Generation guards prevent a request started before logout/token replacement
// from restoring authenticated state after local cleanup.
let generation = 0;

export const useUserStore = create<UserState>()(
  persist(
    (set) => ({
      user: null,
      authStatus: 'unknown',
      isLoading: true,
      setUser: (user) => set({ user }),
      fetchUser: async () => {
        const request = ++generation;
        const credential = getTokenFromCookie();
        if (!credential) {
          set({ user: null, authStatus: 'guest', isLoading: false });
          return;
        }
        set({ authStatus: 'validating', isLoading: true });
        const isCurrent = () => request === generation && credential === getTokenFromCookie();
        try {
          const user = await apiGet<User>('/user');
          if (isCurrent()) set({ user, authStatus: 'authenticated', isLoading: false });
        } catch (error: unknown) {
          if (!isCurrent()) return;
          if (error instanceof ApiError && error.status === 401) {
            clearAuthCookie();
            set({ user: null, authStatus: 'guest', isLoading: false });
          } else {
            console.error('Gagal sinkronisasi data user:', error);
            set({ authStatus: 'unknown', isLoading: false });
          }
        }
      },
      clearUser: () => {
        ++generation;
        set({ user: null, authStatus: 'guest', isLoading: false });
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
