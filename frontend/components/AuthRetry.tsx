'use client';

import { useUserStore } from '@/src/stores/useUserStore';

export function AuthRetry() {
  const { authError, fetchUser } = useUserStore();

  return (
    <main className="min-h-screen flex flex-col items-center justify-center gap-4 p-6 bg-white text-slate-900">
      <p role="alert">{authError || 'Sesi belum dapat diverifikasi. Silakan coba lagi.'}</p>
      <button
        type="button"
        className="rounded border border-slate-300 px-4 py-2 font-semibold"
        onClick={() => void fetchUser()}
      >
        Coba lagi
      </button>
    </main>
  );
}
