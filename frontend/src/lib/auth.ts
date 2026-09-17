// ============================================================
// Auth Helpers — login, logout, getUser, isLoggedIn
// Token disimpan di cookie agar bisa dibaca oleh middleware
// ============================================================

import { apiPost } from '@/src/lib/api';
import { useUserStore } from '@/src/stores/useUserStore';
import type { LoginRequest, LoginResponse } from '@/src/types/auth';

const TOKEN_KEY  = 'auth_token';

// ── Cookie helpers ───────────────────────────────────────────

// Session cookie (tanpa Max-Age/Expires) → otomatis terhapus saat browser
// ditutup, sehingga sesi tidak persisten dan user harus login ulang.
export const setAuthCookie = (token: string): void => {
  document.cookie = [
    `${TOKEN_KEY}=${encodeURIComponent(token)}`,
    'Path=/',
    'SameSite=Lax',
    // 'Secure', // aktifkan saat production (HTTPS)
  ].join('; ');
};

export const clearAuthCookie = (): void => {
  document.cookie = `${TOKEN_KEY}=; Max-Age=0; Path=/`;
};

export const getTokenFromCookie = (): string | null => {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.match(/(?:^|;\s*)auth_token=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : null;
};

// ── Auth actions ─────────────────────────────────────────────

/**
 * Login memanggil endpoint autentikasi Laravel yang aktif.
 * Menyimpan token ke cookie dan user ke localStorage
 */
export const login = async (credentials: LoginRequest): Promise<LoginResponse> => {
  const response = await apiPost<LoginResponse>('/login', credentials);

  setAuthCookie(response.token);

  return response;
};

export const resendEmailVerification = async (): Promise<string> => {
  const response = await apiPost<{ message: string }>('/email/verification-notification', {});
  return response.message;
};

export const clearLocalAuth = (): void => {
  clearAuthCookie();
  useUserStore.getState().clearUser();
  try { localStorage.removeItem('auth_user_storage'); } catch {}
  try { sessionStorage.setItem('tdr_is_logging_out', 'true'); } catch {}
};

export const logout = async (): Promise<void> => {
  try {
    await apiPost('/logout', {});
  } catch {
    // Network failure must not prevent signing out on this browser.
  } finally {
    clearLocalAuth();
  }
};

/**
 * Cek apakah user sudah login (token ada di cookie)
 */
export const isLoggedIn = (): boolean => {
  return Boolean(getTokenFromCookie());
};
