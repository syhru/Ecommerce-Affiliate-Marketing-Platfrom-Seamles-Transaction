import { apiGet } from '@/src/lib/api';
import type { User } from '@/src/types/user';
import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

interface UserState {
  user: User | null;
  isLoading: boolean;
  setUser: (user: User | null) => void;
  fetchUser: () => Promise<void>;
  clearUser: () => void;
}

export const useUserStore = create<UserState>()(
  persist(
    (set) => ({
      user: null,
      isLoading: true,
      
      // Mengatur data user ke dalam state memori
      setUser: (user) => set({ user, isLoading: false }),

      // Fungsi utama untuk sinkronisasi data dengan server (SWR Pattern)
      fetchUser: async () => {
        try {
          const res = await apiGet<any>('/user');
          const apiUser = res?.data || res?.user || res;
          if (apiUser) {
            set({ user: apiUser, isLoading: false });
          }
        } catch (error: any) {
          if (error?.response?.status === 401) {
            // Silently clear user state for unauthenticated users
            set({ user: null, isLoading: false });
          } else {
             // Only log actual network or server errors, not the 401 unauth
             console.error("Gagal sinkronisasi data user:", error);
             set({ isLoading: false });
          }
        }
      },

      // Membersihkan data saat logout
      clearUser: () => set({ user: null, isLoading: false }),
    }),
    {
      name: 'auth_user_storage', // Nama kunci di storage
      storage: createJSONStorage(() => localStorage), // Tetap gunakan localStorage hanya untuk persistent cache (non-sensitive)
    }
  )
);