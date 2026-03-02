'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';

import { useUserStore } from '@/src/stores/useUserStore';

import { apiGet } from '@/src/lib/api';
import { toast } from 'sonner';

export default function AffiliatePendingPage() {
  const router = useRouter();
  const { user, setUser, isLoading: isStoreLoading } = useUserStore();
  const [isApproved, setIsApproved] = useState(false);
  const [isVerifying, setIsVerifying] = useState(true);

  useEffect(() => {
    if (isStoreLoading) return;

    // 0. Guard Cepat via Zustand Store
    if (user) {
      if (user.role === 'affiliate' && user.affiliate_profile?.status === 'active') {
        router.replace('/affiliate/dashboard');
        return;
      }
      if (user.role === 'customer' && !user.affiliate_profile) {
        // Jika belum pernah daftar sama sekali
        router.replace('/affiliate/register');
        return;
      }
    } else {
      router.replace('/login?redirect=/affiliate/pending');
      return;
    }

    // Lolos pengecekan, user berhak berada di halaman ini
    setTimeout(() => setIsVerifying(false), 0);

    // 1. Logika Pencegahan Back Navigation (Mencegah user kembali ke /register)
    const handlePopState = () => {
      router.push('/');
    };
    window.addEventListener('popstate', handlePopState);

    // 2. Logika Polling /api/user setiap 3 detik
    let pollInterval: NodeJS.Timeout;
    
    const checkStatus = async () => {
      try {
        const res = await apiGet<any>('/user');
        const updatedUser = res?.data || res?.user || res;

        if (updatedUser) {
          setUser(updatedUser);

          // Cek kondisi sukses dengan optional chaining
          if (updatedUser.role === 'affiliate' && updatedUser.affiliate_profile?.status === 'active') {
            clearInterval(pollInterval); // Stop polling langsung
            setIsApproved(true);
            toast.success('Akun Anda sudah aktif! Mengalihkan ke Dashboard...');
            
            setTimeout(() => {
              router.replace('/affiliate/dashboard');
            }, 1000);
          } else if (updatedUser.affiliate_profile?.status === 'rejected') {
             clearInterval(pollInterval);
             router.replace('/affiliate/rejected');
          } else if (updatedUser.role === 'customer' && !updatedUser.affiliate_profile) {
             clearInterval(pollInterval);
             router.replace('/affiliate/register');
          }
        }
      } catch (err) {
        console.error('Polling error:', err);
      }
    };

    // Jalankan polling setiap 3 detik
    pollInterval = setInterval(checkStatus, 3000);

    return () => {
      window.removeEventListener('popstate', handlePopState);
      clearInterval(pollInterval);
    };
  }, [router, user, isStoreLoading, setUser]);

  if (isVerifying) {
    return (
      <main className="min-h-screen bg-white flex items-center justify-center">
        <div className="flex items-center gap-3 text-amber-500">
           <svg className="animate-spin h-6 w-6" viewBox="0 0 24 24" fill="none">
             <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
             <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
           </svg>
        </div>
      </main>
    );
  }

  return (
    <main className="min-h-screen bg-white flex flex-col pt-16">
      <Navbar />

      <div className="flex-1 w-full max-w-xl mx-auto py-16 px-4 flex flex-col items-center justify-center text-center">
        
        {/* ── Illustration / Icon ───────────────────── */}
        <div className={`w-24 h-24 rounded-full border-4 flex items-center justify-center mb-8 shadow-sm transition-colors duration-500 ${isApproved ? 'border-emerald-100 bg-emerald-50' : 'border-amber-100 bg-amber-50'}`}>
          <span className={`text-5xl ${!isApproved && 'animate-pulse opacity-80'}`}>
            {isApproved ? '🎉' : '⏳'}
          </span>
        </div>

        {/* ── Text Content ────────────────────────────── */}
        <h1 className="text-slate-900 font-extrabold text-2xl md:text-3xl mb-4 tracking-tight">
          Pendaftaran Berhasil!
        </h1>
        <p className="text-slate-600 font-medium text-sm md:text-base leading-relaxed max-w-md mx-auto px-4">
          Akun affiliate atas nama <strong className="text-slate-900">{user?.name || 'kamu'}</strong> sedang ditinjau oleh tim kami. Mohon tunggu notifikasi selanjutnya di Telegram.
        </p>

        {/* ── Reactive Status Indicator ────────────────── */}
        <div className="mt-8 mb-4">
          {isApproved ? (
            <div className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-50 border border-emerald-200 text-emerald-700 font-bold rounded-full text-sm animate-in fade-in slide-in-from-bottom-2 duration-500">
              <span className="flex h-2 w-2 relative">
                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
              </span>
              Status: Pendaftaran Disetujui!
            </div>
          ) : (
            <div className="inline-flex items-center gap-2 px-4 py-2 bg-amber-50 border border-amber-200 text-amber-700 font-bold rounded-full text-sm">
              <svg className="animate-spin -ml-1 mr-1 h-4 w-4 text-amber-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              Status: Menunggu Persetujuan Admin...
            </div>
          )}
        </div>

        {/* ── Action ──────────────────────────────────── */}
        <div className="w-full max-w-sm px-4 space-y-3">
          <Button 
            onClick={() => router.push('/')}
            className="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold py-6 rounded-xl transition-all shadow-md shadow-slate-900/10"
          >
            🏠 Kembali ke Beranda
          </Button>
        </div>

      </div>

      <Footer />
    </main>
  );
}
