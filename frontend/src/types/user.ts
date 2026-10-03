import type { AffiliateProfile } from './affiliate';

// ============================================================
// Types: User
// Cerminan wire-format API Resource Laravel (snake_case).
// UserResource adalah source of truth; jangan menambah field yang
// tidak pernah diemitted.
// ============================================================

export interface User {
  id: number;
  name: string;
  email: string;
  role: 'superadmin' | 'affiliate' | 'customer';
  telegram_chat_id: string | null;
  email_verified: boolean;
  // Hanya ada ketika relasi affiliateProfile di-load server.
  affiliate_profile?: AffiliateProfile | null;
  is_active: boolean;
  created_at: string | null;
}

export interface LoginCredentials {
  email: string;
  password: string;
}

export interface RegisterPayload {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  telegram_chat_id: string | null;
}

/** Bentuk yang dikembalikan POST /login dan POST /register. */
export interface LoginResponse {
  token: string;   // plainTextToken dari Laravel Sanctum
  user: User;
}

export type RegisterResponse = LoginResponse;
