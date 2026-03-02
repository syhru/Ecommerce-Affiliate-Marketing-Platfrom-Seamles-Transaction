'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiPost } from '@/src/lib/api';
import type { LoginResponse } from '@/src/types/user'; // Menggunakan Login Response karena kembaliannya mirip (user & token)
import { Settings } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

// ── Cookie helper (client-side) ──────────────────────────────
function setAuthCookie(token: string): void {
  const maxAge = 60 * 60 * 24 * 7; // 7 hari
  document.cookie = `auth_token=${encodeURIComponent(token)}; Max-Age=${maxAge}; Path=/; SameSite=Lax`;
}

import { useUserStore } from '@/src/stores/useUserStore';

export default function RegisterPage() {
  const router = useRouter();
  const { fetchUser, user } = useUserStore();

  useEffect(() => {
    // Hindari redirect otomatis sesaat setelah logout
    if (typeof sessionStorage !== 'undefined' && sessionStorage.getItem('tdr_is_logging_out')) {
      return;
    }
    
    // Jika token sudah ada, cegah render registrasi dan arahkan kembali ke home/dashboard
    if (typeof document !== 'undefined' && document.cookie.includes('auth_token=')) {
      window.location.replace('/');
    }
  }, [router]);

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [telegramChatId, setTelegramChatId] = useState('');
  const [isLoading, setIsLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();

    if (password !== passwordConfirmation) {
      toast.error('Password dan Konfirmasi Password tidak cocok.');
      return;
    }

    setIsLoading(true);

    try {
      const payload = {
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
        telegram_chat_id: telegramChatId || null,
      };

      const response = await apiPost<LoginResponse>('/register', payload);

      // Simpan token ke cookie
      setAuthCookie(response.token);

      // SWR: Tarik kembali data _user_ dengan profil data utuh dari server
      await fetchUser();

      toast.success(`Pendaftaran berhasil! Selamat datang, ${response.user.name}.`);

      // Redirect berdasarkan role
      if (response.user.role === 'admin') {
        window.location.replace('/admin/dashboard');
      } else if (!telegramChatId) {
        window.location.replace('/telegram/setup');
      } else {
        window.location.replace('/');
      }
    } catch (error: unknown) {
      const message = error instanceof Error ? error.message : 'Terjadi kesalahan. Coba lagi.';
      toast.error(message);
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
                <Settings className="w-8 h-8 text-white" />
              </div>
              <h1 className="text-2xl font-bold text-slate-900">Buat Akun Baru</h1>
              <p className="text-slate-500 text-sm mt-1">
                Gunakan email<strong className="bg-slate-100 px-1.5 py-0.5 rounded text-slate-700">@tdr-hpz.com</strong>akan otomatis menjadi <strong className="text-slate-700">Admin</strong>. Selain itu menjadi <strong className="text-slate-700">customer</strong>.
              </p>
            </div>

            {/* Form */}
            <div className="bg-white">
              <form onSubmit={handleSubmit} className="space-y-5 text-left">
                {/* Nama Lengkap */}
                <div className="space-y-2">
                  <Label htmlFor="name" className="text-slate-700 font-bold text-xs uppercase tracking-wider">Nama Lengkap <span className="text-red-500">*</span></Label>
                  <Input
                    id="name"
                    type="text"
                    placeholder="Nama lengkap Anda"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    disabled={isLoading}
                    required
                    autoFocus
                    className="bg-slate-50 border-slate-200 text-slate-900 placeholder:text-slate-400 font-medium py-6 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                  />
                </div>

                {/* Email */}
                <div className="space-y-2">
                  <Label htmlFor="email" className="text-slate-700 font-bold text-xs uppercase tracking-wider">Email <span className="text-red-500">*</span></Label>
                  <Input
                    id="email"
                    type="email"
                    placeholder="email@contoh.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    disabled={isLoading}
                    required
                    className="bg-slate-50 border-slate-200 text-slate-900 placeholder:text-slate-400 font-medium py-6 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                  />
               
                </div>

                {/* Passwords */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                  <div className="space-y-2">
                    <Label htmlFor="password" className="text-slate-700 font-bold text-xs uppercase tracking-wider">Password <span className="text-red-500">*</span></Label>
                    <Input
                      id="password"
                      type="password"
                      placeholder="Minimal 8 karakter"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      disabled={isLoading}
                      required
                      className="bg-slate-50 border-slate-200 text-slate-900 placeholder:text-slate-400 font-medium py-6 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="passwordConfirmation" className="text-slate-700 font-bold text-xs uppercase tracking-wider">Ketik Ulang <span className="text-red-500">*</span></Label>
                    <Input
                      id="passwordConfirmation"
                      type="password"
                      placeholder="Ulangi password"
                      value={passwordConfirmation}
                      onChange={(e) => setPasswordConfirmation(e.target.value)}
                      disabled={isLoading}
                      required
                      className="bg-slate-50 border-slate-200 text-slate-900 placeholder:text-slate-400 font-medium py-6 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                    />
                  </div>
                </div>

                {/* Telegram Chat ID */}
                <div className="space-y-2 pt-2">
                  <Label htmlFor="telegram" className="text-slate-700 font-bold text-xs uppercase tracking-wider">
                    Telegram Chat ID <span className="text-slate-400 font-medium normal-case">(opsional)</span>
                  </Label>
                  <Input
                    id="telegram"
                    type="text"
                    placeholder="Contoh: 123456789"
                    value={telegramChatId}
                    onChange={(e) => setTelegramChatId(e.target.value)}
                    disabled={isLoading}
                    className="bg-slate-50 border-slate-200 text-slate-900 placeholder:text-slate-400 font-medium py-6 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                  />
                  <div className="bg-blue-50 border border-blue-100 p-3 rounded-xl mt-2">
                    <p className="text-blue-800 text-xs font-medium leading-relaxed">
                      <span className="mr-1">💬</span> Aktifkan notifikasi pesanan via Telegram. Cara mendapatkan ID: Buka Telegram, cari bot <a href="https://t.me/userinfobot" target="_blank" rel="noreferrer" className="text-blue-600 font-bold hover:underline">@userinfobot</a>.
                      klik <i>Start</i>
                    </p>
                  </div>
                </div>

                {/* Submit */}
                <div className="pt-4">
                  <Button
                    type="submit"
                    disabled={isLoading}
                    className="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold py-6 rounded-xl shadow-lg shadow-slate-900/10 transition-all text-base"
                  >
                    {isLoading ? (
                      <span className="flex items-center justify-center gap-2">
                        <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24" fill="none">
                          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        Mendaftar...
                      </span>
                    ) : (
                      'Daftar Sekarang'
                    )}
                  </Button>
                </div>
              </form>

              <div className="mt-8 text-center mt-6">
                <p className="text-slate-500 text-sm font-medium">
                  Sudah punya akun?{' '}
                  <a href="/login" className="text-amber-600 hover:text-amber-700 font-bold hover:underline transition-colors">
                    Masuk di sini
                  </a>
                </p>
              </div>
            </div>

            <p className="text-center text-slate-400 text-xs font-medium mt-10">
              © {new Date().getFullYear()} TDR HPZ. All rights reserved.
            </p>
          </div>
        </div>

        {/* Sisi Kanan: Visual Area (Hidden on Mobile) */}
        <div className="hidden lg:block relative bg-slate-100 overflow-hidden lg:m-4 lg:rounded-[2rem]">
          <img 
            src="/assets/box-51.webp" 
            alt="Business Collaboration" 
            className="absolute inset-0 w-full h-full object-cover"
          />
          <div className="absolute inset-0 bg-gradient-to-t from-slate-900/80 via-slate-900/20 to-transparent mix-blend-multiply" />
          
          <div className="absolute top-10 right-10 flex gap-4">
             <div className=" bg-white/10 backdrop-blur-md px-6 py-4 rounded-2xl shadow-2xl">
                <div className="text-white font-bold text-2xl ">+500</div>
                <div className="text-white/80 text-sm font-bold">Partner Aktif</div>
             </div>
          </div>

          <div className="absolute bottom-10 left-10 p-6 bg-white/10 backdrop-blur-md border border-white/20 rounded-2xl max-w-sm">
             <h3 className="text-white font-bold text-xl mb-2">Peluang Tanpa Batas</h3>
             <p className="text-white/80 text-sm leading-relaxed">
               Bergabunglah dengan program afiliasi kami dan kelola aktivitas operasional Anda dari dashboard yang tangguh dan mudah digunakan.
             </p>
          </div>
        </div>

      </div>
      
      <Footer />
    </main>
  );
}
