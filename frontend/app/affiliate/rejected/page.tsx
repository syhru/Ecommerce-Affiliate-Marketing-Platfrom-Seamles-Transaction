'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';

import { useUserStore } from '@/src/stores/useUserStore';

export default function AffiliateRejectedPage() {
  const router = useRouter();
  const { user, isLoading } = useUserStore();
  const [isVerifying, setIsVerifying] = useState(true);

  useEffect(() => {
    if (isLoading) return;

    if (!user) {
      router.replace('/login');
      return;
    }

    // Jika sudah jadi affiliate aktif, redirect ke dashboard
    if (user.role === 'affiliate' && user.affiliate_profile?.status === 'active') {
      router.replace('/affiliate/dashboard');
      return;
    }

    // Jika masih pending, redirect ke pending page
    if (user.affiliate_profile?.status === 'pending') {
      router.replace('/affiliate/pending');
      return;
    }

    setIsVerifying(false);
  }, [router, user, isLoading]);

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
        
        {/* Icon */}
        <div className="w-24 h-24 rounded-full border-4 border-red-100 bg-red-50 flex items-center justify-center mb-8 shadow-sm">
          <span className="text-5xl">😔</span>
        </div>

        {/* Text */}
        <h1 className="text-slate-900 font-extrabold text-2xl md:text-3xl mb-4 tracking-tight">
          Pendaftaran Ditolak
        </h1>
        <p className="text-slate-600 font-medium text-sm md:text-base leading-relaxed max-w-md mx-auto px-4">
          Maaf, pendaftaran affiliate atas nama <strong className="text-slate-900">{user?.name || 'kamu'}</strong> telah ditolak oleh tim kami. Jika kamu merasa ini adalah kesalahan, silakan hubungi admin melalui <i><strong className="text-slate-900">CustomerService@gmail.com</strong>.</i>
        </p>

        {/* Status Badge */}
        <div className="mt-8 mb-4">
          <div className="inline-flex items-center gap-2 px-4 py-2 bg-red-50 border border-red-200 text-red-700 font-bold rounded-full text-sm">
            <span className="inline-flex rounded-full h-2 w-2 bg-red-500"></span>
            Status: Pendaftaran Ditolak
          </div>
        </div>

        {/* Actions */}
        <div className="w-full max-w-sm px-4 space-y-3 mt-4">
          <Button 
            onClick={() => router.push('/affiliate/register')}
            className="w-full bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold py-6 rounded-xl transition-all shadow-md shadow-amber-500/10"
          >
            📝 Daftar Ulang
          </Button>
          <Button 
            onClick={() => router.push('/')}
            variant="outline"
            className="w-full font-bold py-6 rounded-xl"
          >
            🏠 Kembali ke Beranda
          </Button>
        </div>

      </div>

      <Footer />
    </main>
  );
}
