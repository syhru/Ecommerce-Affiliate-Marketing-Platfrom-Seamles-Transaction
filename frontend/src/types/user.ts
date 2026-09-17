// ============================================================
// Types: User
// Representasi data user dari Laravel API
// ============================================================

export interface User {
  id: number;
  name: string;
  email: string;
  role: 'superadmin' | 'affiliate' | 'customer';
  telegram_chat_id: string | null;
  email_verified: boolean;
  affiliate_profile?: {
    status: string;
    referral_code?: string;
    commission_rate?: number;
  } | null;
  is_active: boolean;
  created_at: string;
}

export interface LoginCredentials {
  email: string;
  password: string;
}

export interface LoginResponse {
  token: string;   // plainTextToken dari Laravel Sanctum
  user: User;
}
