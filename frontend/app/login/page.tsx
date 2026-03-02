'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiPost } from '@/src/lib/api';
import type { LoginCredentials, LoginResponse } from '@/src/types/user';
import { useRouter, useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

// ── Cookie helper (client-side) ──────────────────────────────
function setAuthCookie(token: string): void {
  const maxAge = 60 * 60 * 24 * 7; // 7 hari
  document.cookie = `auth_token=${encodeURIComponent(token)}; Max-Age=${maxAge}; Path=/; SameSite=Lax`;
}

import { useUserStore } from '@/src/stores/useUserStore';

// ── Component ─────────────────────────────────────────────────
export default function LoginPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { fetchUser, user } = useUserStore();

  useEffect(() => {
    // Hindari redirect otomatis sesaat setelah logout
    if (typeof sessionStorage !== 'undefined' && sessionStorage.getItem('tdr_is_logging_out')) {
      return;
    }
    
    // Jika token sudah ada, cegah render dan arahkan kembali ke home/dashboard
    if (typeof document !== 'undefined' && document.cookie.includes('auth_token=')) {
      window.location.replace('/');
    }
  }, [router]);

  const [email, setEmail]       = useState('');
  const [password, setPassword] = useState('');
  const [isLoading, setIsLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();

    if (!email || !password) {
      toast.error('Email dan password wajib diisi.');
      return;
    }

    setIsLoading(true);

    try {
      const credentials: LoginCredentials = { email, password };
      const response = await apiPost<LoginResponse>('/login', credentials);

      // Simpan token ke cookie
      setAuthCookie(response.token);

      // SWR: Tarik kembali data _user_ bulat-bulat dari endpoint /user 
      // yang memuat relasi lengkap (seperti affiliate_profile) ke dalam Zustand
      await fetchUser();

      toast.success(`Selamat datang, ${response.user.name}!`);

      // Redirect berdasarkan role
      const redirectUrl = searchParams.get('redirect');
      if (redirectUrl) {
        window.location.replace(redirectUrl);
      } else if (response.user.role === 'admin') {
        window.location.replace('/admin/dashboard');
      } else {
        window.location.replace('/');
      }
    } catch (error: unknown) {
      const message =
        error instanceof Error ? error.message : 'Terjadi kesalahan. Coba lagi.';

      if (message.toLowerCase().includes('401') || message.toLowerCase().includes('invalid') || message.toLowerCase().includes('these credentials')) {
        toast.error('Email atau password salah.');
      } else if (message.toLowerCase().includes('network') || message.toLowerCase().includes('fetch')) {
        toast.error('Tidak dapat terhubung ke server. Pastikan backend berjalan.');
      } else {
        toast.error(message);
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <main className="min-h-screen flex flex-col bg-white">
      <Navbar />
      
      <div className="flex-1 grid grid-cols-1 lg:grid-cols-2 mt-[72px] min-h-[calc(100vh-72px)]">
        
        {/* Sisi Kiri: Form Area */}
        <div className="flex items-center justify-center p-6 sm:p-12 lg:p-16 bg-white">
          <div className="w-full max-w-md">
            
            {/* Logo / Brand */}
            <div className="text-center mb-8">
              <div className="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-amber-500 mb-4 shadow-lg shadow-amber-500/20">
                <span className="text-2xl font-bold text-white">T</span>
              </div>
              <h1 className="text-2xl font-bold text-slate-900">TDR Seamless</h1>
              <p className="text-slate-500 text-sm mt-1">Transaction Platform</p>
            </div>

            {/* Form Container */}
            <div className="bg-white">
              <div className="mb-8 text-center">
                <h2 className="text-xl font-bold text-slate-900">Masuk ke Akun</h2>
                <p className="text-slate-500 text-sm mt-1">Masukkan email dan password kamu di bawah</p>
              </div>

              <form onSubmit={handleSubmit} className="space-y-5 text-left">
                {/* Email */}
                <div className="space-y-2">
                  <Label htmlFor="email" className="text-slate-700 font-bold text-xs uppercase tracking-wider">Email <span className="text-red-500">*</span></Label>
                  <Input
                    id="email"
                    type="email"
                    placeholder="admin@tdr-hpz.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    disabled={isLoading}
                    required
                    className="bg-slate-50 border-slate-200 text-slate-900 placeholder:text-slate-400 font-medium py-6 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                  />
                </div>

                {/* Password */}
                <div className="space-y-2">
                  <Label htmlFor="password" className="text-slate-700 font-bold text-xs uppercase tracking-wider">Password <span className="text-red-500">*</span></Label>
                  <Input
                    id="password"
                    type="password"
                    placeholder="••••••••"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    disabled={isLoading}
                    required
                    className="bg-slate-50 border-slate-200 text-slate-900 placeholder:text-slate-400 font-medium py-6 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                  />
                </div>

                {/* Submit Button */}
                <div className="pt-2">
                  <Button
                    type="submit"
                    disabled={isLoading}
                    className="w-full bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold py-6 rounded-xl shadow-lg shadow-amber-500/20 transition-all text-base"
                  >
                    {isLoading ? (
                      <span className="flex items-center justify-center gap-2">
                        <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24" fill="none">
                          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        Memproses...
                      </span>
                    ) : (
                      'Masuk Sekarang'
                    )}
                  </Button>
                </div>
              </form>

              <div className="mt-8 text-center mt-6">
                <p className="text-slate-500 text-sm font-medium">
                  Belum punya akun?{' '}
                  <a href="/register" className="text-amber-600 hover:text-amber-700 font-bold hover:underline transition-colors">
                    Daftar di sini
                  </a>
                </p>
              </div>
            </div>
            
          </div>
        </div>

        {/* Sisi Kanan: Visual Area (Hidden on Mobile) */}
        <div className="hidden lg:block relative bg-slate-100 overflow-hidden lg:m-4 lg:rounded-[2rem]">
          <img 
            src="https://images.unsplash.com/photo-1600880292203-757bb62b4baf?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80" 
            alt="Corporate Environment" 
            className="absolute inset-0 w-full h-full object-cover"
          />
          <div className="absolute inset-0 bg-gradient-to-t from-slate-900/80 via-slate-900/20 to-transparent mix-blend-multiply" />
          
          <div className="absolute top-10 left-10 right-10 flex flex-col gap-4">
             <div className="bg-white/10 backdrop-blur-md border border-white/20 p-4 rounded-2xl w-max shadow-2xl">
               <div className="flex items-center gap-3">
                 <div className="w-10 h-10 bg-amber-500 rounded-full flex items-center justify-center text-white font-bold">✓</div>
                 <div>
                   <h4 className="font-bold text-white text-sm">Transaksi Lancar</h4>
                   <p className="text-white text-xs">Update real-time</p>
                 </div>
               </div>
             </div>
             <div className="bg-white/10 backdrop-blur-md p-4 rounded-2xl w-max shadow-2xl ml-10 mt-2">
               <div className="flex items-center gap-3">
                 <div className="w-10 h-10 bg-blue-500 rounded-full flex items-center justify-center text-white font-bold">⚡</div>
                 <div>
                   <h4 className="font-bold text-white text-sm">Sistem Terpusat</h4>
                   <p className="text-slate-300 text-xs">Manajemen mudah</p>
                 </div>
               </div>
             </div>
          </div>

          <div className="absolute bottom-10 left-10 right-10 p-6 bg-white/10 backdrop-blur-md border border-white/20 rounded-2xl max-w-sm">
             <h3 className="text-white font-bold text-xl mb-2">TDR Seamless Transaction</h3>
             <p className="text-white/80 text-sm leading-relaxed">
               Kelola seluruh operasional toko, penjualan, afiliasi, hingga inventory dengan satu pintu yang terintegrasi penuh.
             </p>
          </div>
        </div>

      </div>
      
      <Footer />
    </main>
  );
}
