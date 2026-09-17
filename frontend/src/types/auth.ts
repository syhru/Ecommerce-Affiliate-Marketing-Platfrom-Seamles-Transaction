// ============================================================
// Types: Auth & User
// sesuai dengan API response dari Laravel Sanctum
// ============================================================

export interface User {
  id: number;
  name: string;
  email: string;
  role: 'superadmin' | 'affiliate' | 'customer';
  telegram_chat_id: string | null;
  email_verified: boolean;
  is_active: boolean;
  created_at: string;
}

export interface LoginRequest {
  email: string;
  password: string;
}

export interface LoginResponse {
  token: string;       // plainTextToken dari Sanctum
  user: User;
}

export interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
}
