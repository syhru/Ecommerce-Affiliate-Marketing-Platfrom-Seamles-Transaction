// ============================================================
// Auth Cookie — SATU-SATUNYA writer/clearer credential di repo.
//
// Credential tetap JS-readable karena arsitektur bearer-token saat ini
// membacanya untuk header `Authorization` dan untuk gate route.
// `Secure` aktif otomatis pada HTTPS; `SameSite` selalu eksplisit.
// Migrasi ke HttpOnly/server-managed = follow-up arsitektur keamanan.
// ============================================================

const TOKEN_KEY = 'auth_token';

const isHttps = (): boolean =>
  typeof window !== 'undefined' && window.location.protocol === 'https:';

/**
 * Session cookie (tanpa Max-Age/Expires) → otomatis terhapus saat browser
 * ditutup, sehingga sesi tidak persisten dan user harus login ulang.
 */
export const setAuthCookie = (token: string): void => {
  if (typeof document === 'undefined') return;

  const attributes = [`${TOKEN_KEY}=${encodeURIComponent(token)}`, 'Path=/', 'SameSite=Lax'];
  if (isHttps()) attributes.push('Secure');

  document.cookie = attributes.join('; ');
};

export const clearAuthCookie = (): void => {
  if (typeof document === 'undefined') return;

  const attributes = [`${TOKEN_KEY}=`, 'Max-Age=0', 'Path=/', 'SameSite=Lax'];
  if (isHttps()) attributes.push('Secure');

  document.cookie = attributes.join('; ');
};

export const getTokenFromCookie = (): string | null => {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.match(/(?:^|;\s*)auth_token=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : null;
};
