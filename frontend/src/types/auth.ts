// ============================================================
// Types: Auth & User
// sesuai dengan API response dari Laravel Sanctum
// ============================================================

export interface User {
  id: number;
  name: string;
  email: string;
  role: 'admin' | 'affiliate' | 'customer';
  telegramChatId: string | null;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
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
