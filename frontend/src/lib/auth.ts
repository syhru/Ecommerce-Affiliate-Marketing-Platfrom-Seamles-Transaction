// ============================================================
// Auth Actions — login, register, logout, verification, cleanup.
//
// Truth: `GET /user` (divalidasi server). Kehadiran cookie hanya HINT untuk
// memvalidasi, tidak pernah bukti authenticated. Credential stale/expired/revoked
// dibersihkan otomatis sehingga aplikasi converge ke guest tanpa intervensi
// manual pengguna.
// ============================================================

import { apiPost } from '@/src/lib/api';
import { clearAuthCookie, getTokenFromCookie, setAuthCookie } from '@/src/lib/auth-cookie';
import { suspendUserValidation, useUserStore } from '@/src/stores/useUserStore';
import type { LoginCredentials, LoginResponse, RegisterPayload, RegisterResponse } from '@/src/types/user';

/**
 * Login memanggil endpoint autentikasi Laravel yang aktif.
 * Menyimpan token ke cookie dan user ke localStorage
 */
export const login = async (credentials: LoginCredentials): Promise<LoginResponse> => {
  const response = await apiPost<LoginResponse>('/login', credentials);

  setAuthCookie(response.token);

  return response;
};

export const register = async (payload: RegisterPayload): Promise<RegisterResponse> => {
  const response = await apiPost<RegisterResponse>('/register', payload);

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

let logoutInFlight: Promise<void> | null = null;

export const logout = (): Promise<void> => {
  if (logoutInFlight) return logoutInFlight;
  const credential = getTokenFromCookie();
  const resumeUserValidation = suspendUserValidation();
  logoutInFlight = (async () => {
    await apiPost('/logout', {});
    if (credential !== getTokenFromCookie()) {
      throw new Error('Session changed while signing out. Please try again.');
    }
    clearLocalAuth();
  })().finally(() => {
    resumeUserValidation();
    logoutInFlight = null;
  });
  return logoutInFlight;
};

/**
 * Apakah ada credential tersimpan. Ini HINT untuk memicu validasi ke
 * `GET /user`, bukan bukti authenticated.
 */
export const hasAuthCredential = (): boolean => Boolean(getTokenFromCookie());
