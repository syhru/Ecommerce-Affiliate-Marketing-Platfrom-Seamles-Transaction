'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiPost, apiPut } from '@/src/lib/api';
import { useUserStore } from '@/src/stores/useUserStore';
import type { User } from '@/src/types/user';
import { KeyRound, Send, UserCircle } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

export default function ProfilePage() {
  const router = useRouter();
  const { user, setUser, isLoading: isStoreLoading } = useUserStore();
  const [isLoading, setIsLoading] = useState(true);

  // ── Profile Form State ──
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [isSavingProfile, setIsSavingProfile] = useState(false);
  const [isEditingProfile, setIsEditingProfile] = useState(false);

  // ── Telegram Form State ──
  const [telegramId, setTelegramId] = useState('');
  const [isSavingTelegram, setIsSavingTelegram] = useState(false);
  const [isEditingTelegram, setIsEditingTelegram] = useState(false);

  // ── Password Form State ──
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [isSavingPassword, setIsSavingPassword] = useState(false);

  // ── 1. Fetch User Data ──
  useEffect(() => {
    if (isStoreLoading) return;
    if (!user) {
      router.push('/login?redirect=/profile');
      return;
    }

    if (isLoading) {
      setName(user.name);
      setEmail(user.email);
      setTelegramId(user.telegram_chat_id || '');
      setIsLoading(false);
    }
  }, [user, isStoreLoading, router, isLoading]);

  // ── 2. Handlers ──
  const handleUpdateProfile = async () => {
    setIsSavingProfile(true);
    try {
      const res = await apiPut<{ message: string; user: User }>('/user/profile', { name, email });
      setUser(res.user);
      toast.success(res.message || 'Profil berhasil diperbarui.');
      setIsEditingProfile(false);
    } catch (err: any) {
      toast.error(err.message || 'Gagal memperbarui profil.');
    } finally {
      setIsSavingProfile(false);
    }
  };

  const handleLinkTelegram = async () => {
    setIsSavingTelegram(true);
    const isEditingNow = Boolean(user?.telegram_chat_id);
    try {
      const payload = { telegram_chat_id: telegramId || null };
      const res = await apiPost<{ message: string; user: User }>('/user/link-telegram', payload);
      
      const successMessage = isEditingNow ? 'ID Telegram berhasil diperbarui!' : 'Telegram berhasil dihubungkan!';
      toast.success(successMessage);
      
      const updatedUser = { ...user, telegram_chat_id: telegramId } as User;
      setUser(updatedUser);
      
      setIsEditingTelegram(false);
    } catch (err: any) {
      toast.error(err.message || 'Gagal menautkan Telegram.');
    } finally {
      setIsSavingTelegram(false);
    }
  };

  const handleUpdatePassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (newPassword !== passwordConfirmation) {
      toast.error('Password baru dan konfirmasinya tidak cocok.');
      return;
    }
    setIsSavingPassword(true);
    try {
      const payload = {
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: passwordConfirmation,
      };
      await apiPut<{ message: string }>('/user/password', payload);
      toast.success('Password berhasil diperbarui.');
      // Reset form sandi
      setCurrentPassword('');
      setNewPassword('');
      setPasswordConfirmation('');
    } catch (err: any) {
      toast.error(err.message || 'Gagal memperbarui password.');
    } finally {
      setIsSavingPassword(false);
    }
  };


  if (isLoading || !user) {
    return (
      <main className="min-h-screen bg-[#f8f9fa] flex items-center justify-center pt-16">
        <Navbar />
        <div className="flex flex-col items-center gap-4 text-amber-500">
          <svg className="animate-spin h-8 w-8" viewBox="0 0 24 24" fill="none">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          <span className="font-bold text-slate-700">Memuat info profil...</span>
        </div>
      </main>
    );
  }

  return (
    <main className="min-h-screen bg-[#f8f9fa] font-sans pt-16 flex flex-col">
      <Navbar />

      <div className="max-w-4xl mx-auto space-y-8 flex-1 w-full px-4 py-10 mt-2">
        
        {/* Header */}
        <div className="pb-4">
          <h1 className="text-slate-900 font-extrabold text-3xl tracking-tight flex items-center gap-3">
            <span className="w-12 h-12 rounded-xl bg-orange-100 flex items-center justify-center text-orange-600 border border-orange-200 shadow-sm">
              <UserCircle className="w-7 h-7" />
            </span>
            Profil Saya
          </h1>
          <p className="text-slate-500 font-medium text-sm mt-3 md:ml-[60px] max-w-xl leading-relaxed">
            Kelola informasi data dirimu, kelola notifikasi pintar via Telegram, dan perkuat keamanan akun dengan pembaruan sandi reguler.
          </p>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
          
          <div className="space-y-8">
            {/* ── 1. Update Profile Card ── */}
            <Card className="bg-white border-slate-200 shadow-sm rounded-2xl overflow-hidden">
              <CardHeader className="bg-slate-50/50 border-b border-slate-100 pb-4">
                <CardTitle className="text-lg font-bold text-slate-900 flex items-center gap-2">
                  Detail Profil
                </CardTitle>
                <CardDescription>
                  Perbarui informasi nama dan alamat email kamu.
                </CardDescription>
              </CardHeader>
              <CardContent className="p-6">
                <form onSubmit={(e) => e.preventDefault()} className="space-y-5">
                  <div className="space-y-2">
                    <Label htmlFor="name" className="text-slate-700 font-bold text-xs uppercase tracking-wider">Nama Lengkap</Label>
                    <Input
                      id="name"
                      type="text"
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                      disabled={!isEditingProfile || isSavingProfile}
                      required
                      className="bg-slate-50 border-slate-200 text-slate-900 font-medium py-5 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="email" className="text-slate-700 font-bold text-xs uppercase tracking-wider">Email Utama</Label>
                    <Input
                      id="email"
                      type="email"
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      disabled={!isEditingProfile || isSavingProfile}
                      required
                      className="bg-slate-50 border-slate-200 text-slate-900 font-medium py-5 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                    />
                  </div>
                  <Button
                    type="button"
                    onClick={(e) => {
                      e.preventDefault();
                      if (!isEditingProfile) {
                        setIsEditingProfile(true);
                      } else {
                        handleUpdateProfile();
                      }
                    }}
                    disabled={isSavingProfile}
                    className="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold py-6 rounded-xl transition-all shadow-md"
                  >
                    {isSavingProfile ? 'Menyimpan...' : (!isEditingProfile ? 'Edit Profil' : 'Simpan Profil')}
                  </Button>
                </form>
              </CardContent>
            </Card>

            {/* ── 2. Telegram Integration Card ── */}
            <Card className="bg-white border-blue-200 shadow-sm rounded-2xl overflow-hidden relative">
              <div className="absolute top-0 right-0 w-32 h-32 bg-blue-500/5 rounded-bl-full pointer-events-none" />
              <CardHeader className="bg-blue-50/50 border-b border-blue-100 pb-4">
                <CardTitle className="text-lg font-bold text-slate-900 flex items-center gap-2">
                  <Send className="w-5 h-5 text-blue-500" /> Notifikasi Telegram
                </CardTitle>
                <CardDescription>
                  Hubungkan dengan ID Chat Telegram untuk _update_ pesanan cepat secara realtime.
                </CardDescription>
              </CardHeader>
              <CardContent className="p-6">
                <form onSubmit={(e) => e.preventDefault()} className="space-y-5">
                  <div className="space-y-2">
                    <div className="flex justify-between items-center mb-1">
                      <Label htmlFor="telegram_id" className="text-slate-700 font-bold text-xs uppercase tracking-wider">Chat ID (Opsional)</Label>
                      {user.telegram_chat_id ? (
                        <span className="bg-emerald-100 text-emerald-700 text-[10px] font-bold px-2 py-0.5 rounded-md uppercase tracking-wide">Terhubung</span>
                      ) : (
                        <span className="bg-amber-100 text-amber-700 text-[10px] font-bold px-2 py-0.5 rounded-md uppercase tracking-wide">Belum Ada</span>
                      )}
                    </div>
                    <Input
                      id="telegram_id"
                      type="text"
                      placeholder="e.g. 123456789"
                      value={telegramId}
                      onChange={(e) => setTelegramId(e.target.value)}
                      disabled={!isEditingTelegram}
                      className="bg-slate-50 border-blue-200 text-slate-900 font-medium py-5 px-4 focus:ring-2 focus:ring-blue-500 focus:border-transparent rounded-xl"
                    />
                    <p className="text-slate-500 text-[11px] font-medium leading-relaxed pt-1">
                      Kirim '/start' ke bot <a href="https://t.me/userinfobot" target="_blank" className="text-blue-600 font-bold hover:underline">@userinfobot</a> untuk mendeteksi ID khusus Anda.
                    </p>
                  </div>
                  <Button
                    type="button"
                    onClick={(e) => {
                      e.preventDefault();
                      if (!isEditingTelegram) {
                        setIsEditingTelegram(true);
                      } else {
                        handleLinkTelegram();
                      }
                    }}
                    variant="outline"
                    disabled={isSavingTelegram}
                    className="w-full bg-white border-blue-200 text-blue-700 hover:bg-blue-50 font-bold py-6 rounded-xl shadow-sm transition-all"
                  >
                    {isSavingTelegram ? 'Memproses...' : (!isEditingTelegram ? 'Edit Tautan Telegram' : 'Simpan ID Telegram')}
                  </Button>
                </form>
              </CardContent>
            </Card>
          </div>

          <div className="space-y-8">
            {/* ── 3. Update Password Card ── */}
            <Card className="bg-white border-slate-200 shadow-sm rounded-2xl overflow-hidden">
              <CardHeader className="bg-amber-50/50 border-b border-amber-100 pb-4">
                <CardTitle className="text-lg font-bold text-slate-900 flex items-center gap-2">
                  <KeyRound className="w-5 h-5 text-amber-600" /> Keamanan Sandi
                </CardTitle>
                <CardDescription>
                  Ganti password lama Anda untuk mempertahankan keamanan tinggi di akun ini.
                </CardDescription>
              </CardHeader>
              <CardContent className="p-6">
                <form onSubmit={handleUpdatePassword} className="space-y-5">
                  <div className="space-y-2">
                    <Label htmlFor="current_pwd" className="text-slate-700 font-bold text-xs uppercase tracking-wider">Password Lama</Label>
                    <Input
                      id="current_pwd"
                      type="password"
                      value={currentPassword}
                      onChange={(e) => setCurrentPassword(e.target.value)}
                      disabled={isSavingPassword}
                      required
                      placeholder="••••••••"
                      className="bg-slate-50 border-slate-200 text-slate-900 font-medium py-5 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                    />
                  </div>
                  
                  <div className="w-full h-px bg-slate-100 my-2" />

                  <div className="space-y-2">
                    <Label htmlFor="new_pwd" className="text-slate-700 font-bold text-xs uppercase tracking-wider">Password Baru</Label>
                    <Input
                      id="new_pwd"
                      type="password"
                      value={newPassword}
                      onChange={(e) => setNewPassword(e.target.value)}
                      disabled={isSavingPassword}
                      required
                      placeholder="Minimal 8 Karakter"
                      className="bg-slate-50 border-slate-200 text-slate-900 font-medium py-5 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="new_pwd_conf" className="text-slate-700 font-bold text-xs uppercase tracking-wider">Konfirmasi Password Baru</Label>
                    <Input
                      id="new_pwd_conf"
                      type="password"
                      value={passwordConfirmation}
                      onChange={(e) => setPasswordConfirmation(e.target.value)}
                      disabled={isSavingPassword}
                      required
                      placeholder="Ulangi Password Baru"
                      className="bg-slate-50 border-slate-200 text-slate-900 font-medium py-5 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                    />
                  </div>
                  <div className="pt-2">
                    <Button
                      type="submit"
                      disabled={isSavingPassword}
                      className="w-full bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold py-6 rounded-xl shadow-lg shadow-amber-500/20 transition-all text-base"
                    >
                      {isSavingPassword ? 'Menyimpan Sandi...' : 'Ubah Password'}
                    </Button>
                  </div>
                </form>
              </CardContent>
            </Card>
          </div>

        </div>

      </div>
      <Footer />
    </main>
  );
}
