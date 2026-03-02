'use client';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useRouter } from 'next/navigation';
import { useEffect } from 'react';
import { toast } from 'sonner';

import { useUserStore } from '@/src/stores/useUserStore';

export default function AdminDashboardPage() {
  const router = useRouter();
  const { user, clearUser, isLoading: isStoreLoading } = useUserStore();

  useEffect(() => {
    if (isStoreLoading) return;
    if (!user) {
      router.push('/login');
      return;
    }
  }, [router, user, isStoreLoading]);

  const handleLogout = () => {
    clearUser();
    toast.success('Berhasil logout.');
    router.push('/login');
  };

  if (!user) return null;

  return (
    <main className="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 p-6">
      {/* Header */}
      <div className="max-w-6xl mx-auto">
        <div className="flex items-center justify-between mb-8">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-amber-500 flex items-center justify-center">
              <span className="text-lg font-bold text-slate-900">T</span>
            </div>
            <div>
              <h1 className="text-white font-bold text-lg leading-none">TDR Seamless</h1>
              <p className="text-slate-400 text-xs">Admin Dashboard</p>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <span className="text-slate-400 text-sm">Halo, {user.name}</span>
            <Button
              variant="outline"
              size="sm"
              onClick={handleLogout}
              className="border-slate-600 text-slate-300 hover:text-white hover:bg-slate-700"
            >
              Logout
            </Button>
          </div>
        </div>

        {/* Welcome Banner */}
        <div className="rounded-2xl bg-amber-500/10 border border-amber-500/20 p-6 mb-8">
          <h2 className="text-amber-400 font-semibold text-xl mb-1">
            Selamat datang kembali, {user.name}! 👋
          </h2>
          <p className="text-slate-400 text-sm">
            Role: <span className="text-amber-400 font-medium capitalize">{user.role}</span> ·{' '}
            {user.email}
          </p>
        </div>

        {/* Quick Links */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <Card className="bg-slate-800/50 border-slate-700 hover:border-amber-500/50 transition-colors cursor-pointer">
            <CardHeader className="pb-2">
              <CardTitle className="text-white text-base flex items-center gap-2">
                <span>🛍️</span> Katalog Produk
              </CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-slate-400 text-sm">Kelola produk motor &amp; shockbreaker.</p>
            </CardContent>
          </Card>

          <Card className="bg-slate-800/50 border-slate-700 hover:border-amber-500/50 transition-colors cursor-pointer">
            <CardHeader className="pb-2">
              <CardTitle className="text-white text-base flex items-center gap-2">
                <span>🛒</span> Pesanan
              </CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-slate-400 text-sm">Lihat dan kelola semua transaksi.</p>
            </CardContent>
          </Card>

          <Card className="bg-slate-800/50 border-slate-700 hover:border-amber-500/50 transition-colors cursor-pointer">
            <CardHeader className="pb-2">
              <CardTitle className="text-white text-base flex items-center gap-2">
                <span>👥</span> Affiliate
              </CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-slate-400 text-sm">Pantau program referral &amp; komisi.</p>
            </CardContent>
          </Card>
        </div>

        {/* Link ke Filament Admin */}
        <div className="mt-6 text-center">
          <a
            href="http://localhost:8000/admin"
            target="_blank"
            rel="noopener noreferrer"
            className="text-slate-500 text-sm hover:text-amber-400 transition-colors"
          >
            Buka Filament Admin Panel →
          </a>
        </div>
      </div>
    </main>
  );
}
