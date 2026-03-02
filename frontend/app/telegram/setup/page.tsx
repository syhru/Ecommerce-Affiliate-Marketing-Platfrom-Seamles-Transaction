'use client';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiPost } from '@/src/lib/api';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

// ─── Constants ────────────────────────────────────────────────
const BOT_USERNAME = 'TDRHPZ_bot'; 
// Di versi blade ini didapat dari config('services.telegram.bot_username').
// Kita hardcode sementara atau bisa menggunakan ENV variables NEXT_PUBLIC_TELEGRAM_BOT_USERNAME.

import { useUserStore } from '@/src/stores/useUserStore';

// ─── Main Component ────────────────────────────────────────────
export default function TelegramSetupPage() {
  const router = useRouter();
  const { user, setUser, isLoading: isStoreLoading } = useUserStore();
  const [chatId, setChatId] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    if (isStoreLoading) return;
    if (!user) {
      window.location.replace('/login?redirect=/telegram/setup');
      return;
    }
    
    // Proteksi: Jika user sudah terhubung telegram
    if (user.telegramChatId || user.telegram_chat_id) {
      window.location.replace('/');
      return;
    }
  }, [user, isStoreLoading, router]);

  const handleLinkTelegram = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!chatId.trim()) {
      toast.error('Chat ID tidak boleh kosong.');
      return;
    }

    setIsSubmitting(true);
    try {
      const payload = { telegram_chat_id: chatId.trim() };
      await apiPost('/user/link-telegram', payload);
      
      toast.success('🎉 Telegram berhasil dihubungkan!', {
        description: 'Mulai sekarang notifikasi akan dikirim ke Telegram kamu.'
      });
      
      // Update local storage via Zustand
      if (user) {
        const updated = { ...user, telegramChatId: chatId.trim(), telegram_chat_id: chatId.trim() };
        setUser(updated);
      }
      
      // Auto-redirect to home after delay to let user see the success toast
      setTimeout(() => {
        window.location.replace('/');
      }, 1500);
      
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Gagal menghubungkan Telegram.';
      toast.error(msg);
    } finally {
      setIsSubmitting(false);
    }
  };

  if (!user) {
    return (
      <main className="min-h-screen bg-slate-900 flex items-center justify-center">
        <div className="flex items-center gap-3 text-blue-500">
          <svg className="animate-spin h-6 w-6" viewBox="0 0 24 24" fill="none">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          <span className="font-semibold">Mempersiapkan...</span>
        </div>
      </main>
    );
  }

  const isConnected = Boolean(user?.telegramChatId);

  return (
    <main className="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 px-4 py-12 flex flex-col items-center">
      
      <div className="w-full max-w-lg animate-in fade-in slide-in-from-bottom-4 duration-500">
        <Card className="bg-slate-800/60 border-slate-700 shadow-2xl relative overflow-hidden">
          {/* Efek glow biru bg */}
          <div className="absolute top-0 left-1/2 -translate-x-1/2 w-64 h-64 bg-blue-500/10 rounded-full blur-3xl -mt-20 pointer-events-none" />
          
          <CardContent className="p-8 relative z-10">
            {/* ── Header ── */}
            <div className="text-center mb-8">
              <div className="w-20 h-20 rounded-full bg-blue-500/10 border-2 border-blue-500/30 flex items-center justify-center mx-auto mb-5 shadow-lg shadow-blue-500/10">
                <span className="text-4xl">✈️</span>
              </div>
              <h2 className="text-white font-bold text-2xl mb-2">Aktifkan Notifikasi Telegram</h2>
              <p className="text-slate-400 text-sm leading-relaxed max-w-[280px] mx-auto">
                Dapatkan update realtime di Telegram setiap pesananmu diproses, dikirim, hingga selesai.
              </p>
            </div>

            {/* ── Status Banner ── */}
            {isConnected && (
              <div className="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center gap-3 text-emerald-400">
                <span className="text-2xl">✅</span>
                <div className="text-left">
                  <p className="font-bold text-sm">Telegram Terhubung</p>
                  <p className="text-emerald-500/80 text-xs mt-0.5 font-mono">Chat ID: {user.telegramChatId}</p>
                </div>
              </div>
            )}

            {/* ── Steps ── */}
            <div className="space-y-0 rounded-xl bg-slate-900/40 border border-slate-700/50 p-2 mb-8">
              <div className="flex gap-4 p-4 border-b border-slate-700/50">
                <div className="w-8 h-8 rounded-full bg-amber-500/20 text-amber-500 border border-amber-500/40 flex items-center justify-center font-bold text-sm shrink-0">1</div>
                <div>
                  <h4 className="text-slate-200 font-semibold text-sm">Buka Bot Telegram</h4>
                  <p className="text-slate-400 text-xs mt-1">
                    Klik tombol di bawah ini lalu tekan tombol <strong className="text-white bg-slate-700 px-1 py-0.5 rounded">START</strong> di aplikasi Telegram.
                  </p>
                  <Button 
                    asChild 
                    className="mt-3 w-full sm:w-auto bg-[#0088cc] hover:bg-[#0077b5] text-white shadow-lg shadow-[#0088cc]/20 border-none"
                  >
                    <a href={`https://t.me/${BOT_USERNAME}`} target="_blank" rel="noopener noreferrer">
                      <span className="text-lg mr-2">↗️</span> Buka @{BOT_USERNAME}
                    </a>
                  </Button>
                </div>
              </div>
              
              <div className="flex gap-4 p-4 border-b border-slate-700/50">
                <div className="w-8 h-8 rounded-full bg-amber-500/20 text-amber-500 border border-amber-500/40 flex items-center justify-center font-bold text-sm shrink-0">2</div>
                <div>
                  <h4 className="text-slate-200 font-semibold text-sm">Dapatkan Chat ID</h4>
                  <p className="text-slate-400 text-xs mt-1">
                    Bot akan merespons pesanmu dan memberikan sebuah nomor <strong>(Chat ID)</strong> khusus untuk akun telegrammu.
                  </p>
                </div>
              </div>

              <div className="flex gap-4 p-4">
                <div className="w-8 h-8 rounded-full bg-amber-500/20 text-amber-500 border border-amber-500/40 flex items-center justify-center font-bold text-sm shrink-0">3</div>
                <div className="w-full">
                  <h4 className="text-slate-200 font-semibold text-sm">Tautkan Akun</h4>
                  <p className="text-slate-400 text-xs mt-1 mb-3">
                    Tempelkan <strong>Chat ID</strong> tersebut di bawah ini untuk menghubungkan bot.
                  </p>
                  
                  <form onSubmit={handleLinkTelegram} className="flex gap-2 w-full">
                    <div className="flex-1">
                      <Label htmlFor="chatId" className="sr-only">Chat ID</Label>
                      <Input 
                        id="chatId"
                        type="text" 
                        placeholder="Contoh: 123456789"
                        value={chatId}
                        onChange={(e) => setChatId(e.target.value)}
                        className="bg-slate-900 border-slate-600 focus:border-blue-500 text-white font-mono placeholder:font-sans"
                        required
                      />
                    </div>
                    <Button 
                      type="submit" 
                      disabled={isSubmitting || chatId === user.telegramChatId}
                      className="bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold px-6"
                    >
                      {isSubmitting ? 'Menyimpan...' : (isConnected && chatId === user.telegramChatId ? 'Tersimpan' : 'Simpan')}
                    </Button>
                  </form>
                </div>
              </div>
            </div>

            {/* ── Footer Actions ── */}
            <div className="flex flex-col sm:flex-row items-center justify-center gap-3 border-t border-slate-700/50 pt-6">
              <Button onClick={() => router.push('/profile')} variant="outline" className="w-full sm:w-auto bg-transparent border-slate-700 text-slate-300 hover:text-white hover:bg-slate-600">
                Atur di Profile
              </Button>
              <Button onClick={() => router.push('/')} variant="outline" className="w-full sm:w-auto bg-transparent border-slate-700 text-slate-300 hover:text-white hover:bg-slate-600">
                Lewati untuk sekarang
              </Button>
            </div>

          </CardContent>
        </Card>
      </div>

    </main>
  );
}
