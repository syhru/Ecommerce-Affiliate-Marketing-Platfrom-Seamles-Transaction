// ============================================================
// Auth Helpers — login, logout, getUser, isLoggedIn
// Token disimpan di cookie agar bisa dibaca oleh middleware
// ============================================================

import { apiPost } from '@/src/lib/api';
import type { LoginRequest, LoginResponse } from '@/src/types/auth';

const TOKEN_KEY  = 'auth_token';
const COOKIE_MAX_AGE = 60 * 60 * 24 * 7; // 7 hari

// ── Cookie helpers ───────────────────────────────────────────

export const setAuthCookie = (token: string): void => {
  document.cookie = [
    `${TOKEN_KEY}=${encodeURIComponent(token)}`,
    `Max-Age=${COOKIE_MAX_AGE}`,
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
 * Login — memanggil POST /api/auth/login
 * Menyimpan token ke cookie dan user ke localStorage
 */
export const login = async (credentials: LoginRequest): Promise<LoginResponse> => {
  const response = await apiPost<LoginResponse>('/auth/login', credentials);

  setAuthCookie(response.token);

  return response;
};

/**
 * Logout — menghapus token & user dari storage
 */
export const logout = (): void => {
  clearAuthCookie();
};

/**
 * Cek apakah user sudah login (token ada di cookie)
 */
export const isLoggedIn = (): boolean => {
  return Boolean(getTokenFromCookie());
};
