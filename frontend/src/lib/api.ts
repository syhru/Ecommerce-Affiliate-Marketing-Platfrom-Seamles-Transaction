// ============================================================
// API Client — utility fetcher untuk semua request ke Laravel
// Base URL: http://localhost:8000/api
// ============================================================

const BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api';

// Ambil token dari cookies (berjalan di server & client)
const getToken = (): string | null => {
  if (typeof document === 'undefined') return null; // server-side: tidak ada document
  const match = document.cookie.match(/(?:^|;\s*)auth_token=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : null;
};

// Tipe untuk options fetch
interface ApiOptions extends RequestInit {
  token?: string; // override token jika diperlukan
}

// Error custom agar bisa tangkap HTTP status
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly message: string,
    public readonly data?: unknown,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

// ── Core fetcher ────────────────────────────────────────────
async function apiFetch<T>(endpoint: string, options: ApiOptions = {}): Promise<T> {
  const { token: overrideToken, headers: customHeaders, ...restOptions } = options;

  const token = overrideToken ?? getToken();

  const headers: HeadersInit = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(customHeaders as Record<string, string> ?? {}),
  };

  const response = await fetch(`${BASE_URL}${endpoint}`, {
    ...restOptions,
    headers,
  });

  // Parse body sekali saja
  const contentType = response.headers.get('content-type') ?? '';
  const body = contentType.includes('application/json') ? await response.json() : await response.text();

  if (!response.ok) {
    const message =
      (typeof body === 'object' && body !== null && 'message' in body)
        ? String((body as Record<string, unknown>).message)
        : `HTTP ${response.status}`;
    throw new ApiError(response.status, message, body);
  }

  return body as T;
}

// ── Shorthand methods ────────────────────────────────────────

export const apiGet = <T>(endpoint: string, options?: ApiOptions) =>
  apiFetch<T>(endpoint, { method: 'GET', ...options });

export const apiPost = <T>(endpoint: string, body: unknown, options?: ApiOptions) =>
  apiFetch<T>(endpoint, { method: 'POST', body: JSON.stringify(body), ...options });

export const apiPut = <T>(endpoint: string, body: unknown, options?: ApiOptions) =>
  apiFetch<T>(endpoint, { method: 'PUT', body: JSON.stringify(body), ...options });

export const apiDelete = <T>(endpoint: string, options?: ApiOptions) =>
  apiFetch<T>(endpoint, { method: 'DELETE', ...options });

export default apiFetch;
