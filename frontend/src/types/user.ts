// ============================================================
// Types: User
// Representasi data user dari Laravel API
// ============================================================

export interface User {
  id: number;
  name: string;
  email: string;
  role: 'admin' | 'affiliate' | 'customer';
  telegramChatId: string | null;
  telegram_chat_id?: string | null;
  affiliate_profile?: {
    status: string;
    referral_code?: string;
    commission_rate?: number;
  } | null;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
}

export interface LoginCredentials {
  email: string;
  password: string;
}

export interface LoginResponse {
  token: string;   // plainTextToken dari Laravel Sanctum
  user: User;
}
