'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { apiGet } from '@/src/lib/api';
import type { Product } from '@/src/types/product';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

// ─── Types ────────────────────────────────────────────────────
interface AffiliateDashboardData {
  stats: {
    total_clicks: number;
    total_conversions: number;
    conversion_rate: number;
    total_commission: number;
    balance: number;
  };
  chartData: {
    labels: string[];
    clicks: number[];
    convs: number[];
  };
}

import { useUserStore } from '@/src/stores/useUserStore';

const formatRupiah = (amount: number) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(amount);

// ─── Subcomponents ────────────────────────────────────────────
function StatCard({ title, value, icon, colorClass, isCurrency = false }: { title: string, value: number | string, icon: string, colorClass: string, isCurrency?: boolean }) {
  return (
    <Card className="bg-white border-slate-200 shadow-sm hover:border-amber-400/50 hover:shadow-lg transition-all rounded-2xl group cursor-default">
      <CardContent className="p-6 flex items-center gap-5">
        <div className={`w-14 h-14 rounded-xl flex items-center justify-center text-3xl shrink-0 border group-hover:scale-110 transition-transform ${colorClass}`}>
          {icon}
        </div>
        <div>
          <p className="text-slate-500 text-xs font-bold uppercase tracking-wider mb-1 mt-0.5">{title}</p>
          <h3 className={`text-2xl font-extrabold ${isCurrency ? 'text-amber-500' : 'text-slate-900'}`}>
            {value}
          </h3>
        </div>
      </CardContent>
    </Card>
  );
}

// ─── Main Component ────────────────────────────────────────────
export default function AffiliateDashboardPage() {
  const router = useRouter();
  const { user, isLoading: isStoreLoading } = useUserStore();
  const [data, setData] = useState<AffiliateDashboardData | null>(null);
  const [products, setProducts] = useState<Product[]>([]);
  const [selectedProductSlug, setSelectedProductSlug] = useState<string>('');
  const [isLoading, setIsLoading] = useState(true);

  // 1. Cek autentikasi & fetch data
  useEffect(() => {
    if (isStoreLoading) return;

    if (!user) {
      router.push('/login?redirect=/affiliate/dashboard');
      return;
    }
    
    // Guard: hanya affiliate aktif yang boleh masuk
    const affiliateStatus = user.affiliate_profile?.status;

    if (affiliateStatus === 'pending') {
      router.replace('/affiliate/pending');
      return;
    }

    if (affiliateStatus === 'rejected') {
      router.replace('/affiliate/rejected');
      return;
    }

    // Jika bukan affiliate aktif (customer biasa, inactive, atau tanpa profil) → redirect ke beranda
    if (user.role !== 'affiliate' || affiliateStatus !== 'active') {
      router.replace('/');
      return;
    }

    const loadDashboard = async () => {
      try {
        const res = await apiGet<AffiliateDashboardData>('/affiliate/dashboard');
        setData(res);
      } catch (err: unknown) {
        const msg = err instanceof Error ? err.message : 'Terjadi kesalahan memuat dashboard';
        // Handle 403 Affiliate Not Active or pending
        if (msg.includes('403') || msg.toLowerCase().includes('menunggu persetujuan')) {
          toast.warning('Akun affiliate kamu masih menunggu persetujuan admin atau ditangguhkan.');
          router.push('/');
        } else {
          toast.error(msg);
        }
      } finally {
        setIsLoading(false);
      }
    };

    const loadProducts = async () => {
      try {
        const res = await apiGet<{ data: Product[] }>('/products?limit=100');
        setProducts(res.data || []);
      } catch (err) {
        console.error('Failed to load products', err);
      }
    };

    loadDashboard();
    loadProducts();
  }, [router, user, isStoreLoading]);

  // 2. Action Handlers
  const handleCopyLink = () => {
    const refCode = `AFF${user?.id}X8`; 
    const link = selectedProductSlug 
      ? `${window.location.origin}/products/${selectedProductSlug}?ref=${refCode}`
      : `${window.location.origin}/shop?ref=${refCode}`;
    
    navigator.clipboard.writeText(link).then(() => {
      toast.success('Link referral berhasil disalin!');
    }).catch(() => {
      toast.error('Gagal menyalin link.');
    });
  };

  const handleWithdrawRequest = () => {
    toast.info('Fitur penarikan (withdraw) segera hadir di versi terbaru.');
    // Integrasi /api/affiliate/withdraw 
  };

  return (
    <main className="min-h-screen bg-[#f8f9fa] font-sans pt-16 flex flex-col">
      <Navbar />

      {!user || isStoreLoading || isLoading ? (
        <div className="flex-1 flex items-center justify-center">
          <div className="flex items-center gap-3 text-amber-500">
            <svg className="animate-spin h-6 w-6" viewBox="0 0 24 24" fill="none">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            <span className="font-semibold text-slate-700">Memuat Dashboard...</span>
          </div>
        </div>
      ) : !data ? null : (
        <div className="max-w-6xl mx-auto space-y-8 flex-1 w-full px-4 py-10 mt-2">

          {/* ── Header ────────────────────────────────────────── */}
          <div className="flex flex-col md:flex-row md:items-end justify-between gap-4 pb-4">
            <div>
              <h1 className="text-slate-900 font-extrabold text-3xl tracking-tight flex items-center gap-3">
                <span className="w-12 h-12 rounded-xl bg-amber-100 flex items-center justify-center text-2xl border border-amber-200 shadow-sm">
                  🚀
                </span>
                Dashboard Affiliate
              </h1>
              <p className="text-slate-500 font-medium text-sm mt-3 md:ml-[60px]">
                Pantau performa komisi dan konversi dari traffic referral kamu secara transparan.
              </p>
            </div>
            <div className="flex gap-3 mt-4 md:mt-0">
              <Button variant="outline" onClick={() => router.push('/shop')} className="border-slate-200 text-slate-600 hover:text-slate-900 bg-white hover:bg-slate-50 font-bold rounded-full px-6 shadow-sm">
                Lihat Katalog Produk
              </Button>
            </div>
          </div>

          {/* ── Stat Cards ────────────────────────────────────── */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <StatCard 
              title="Total Klik Masuk" 
              value={data.stats.total_clicks} 
              icon="🖱️" 
              colorClass="bg-blue-50 text-blue-500 border-blue-100/50" 
            />
            <StatCard 
              title="Konversi Berhasil" 
              value={data.stats.total_conversions} 
              icon="🛒" 
              colorClass="bg-emerald-50 text-emerald-500 border-emerald-100/50" 
            />
            <StatCard 
              title="Tingkat Konversi" 
              value={`${data.stats.conversion_rate}%`} 
              icon="📈" 
              colorClass="bg-purple-50 text-purple-500 border-purple-100/50" 
            />
            <StatCard 
              title="Total Estimasi Komisi" 
              value={formatRupiah(data.stats.total_commission)} 
              icon="💰" 
              colorClass="bg-amber-100 text-amber-500 border-amber-200/50" 
              isCurrency
            />
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-8 pb-10">
            
            {/* ── Kiri: Saldo & Link Referral ────────────────── */}
            <div className="lg:col-span-1 space-y-6">
              
              {/* Saldo Komisi Aktif */}
              <Card className="bg-white border-slate-200 shadow-sm rounded-2xl overflow-hidden">
                <div className="h-1.5 bg-amber-500 w-full" />
                <CardContent className="p-7">
                  <p className="text-slate-500 mb-2 text-sm font-bold uppercase tracking-wider">Saldo Komisi Aktif</p>
                  <p className="text-4xl font-extrabold text-slate-900 mb-5 tracking-tight">
                    {formatRupiah(data.stats.balance)}
                  </p>
                  <div className="space-y-4">
                    <div className="flex items-center justify-between text-xs font-semibold text-slate-500 border-b border-slate-100 pb-3">
                      <span>Minimum Pencairan</span>
                      <span className="text-slate-900 font-extrabold">Rp 50.000</span>
                    </div>
                    <Button 
                      onClick={handleWithdrawRequest}
                      disabled={data.stats.balance < 50000}
                      className={`w-full font-bold px-4 py-6 rounded-xl transition-all ${
                        data.stats.balance < 50000 
                          ? 'bg-slate-100 text-slate-400 cursor-not-allowed border border-slate-200'
                          : 'bg-slate-900 hover:bg-amber-500 hover:text-slate-900 text-white shadow-lg'
                      }`}
                    >
                      {data.stats.balance < 50000 ? 'Saldo Belum Mencukupi' : 'Tarik Komisi Sekarang'}
                    </Button>
                  </div>
                </CardContent>
              </Card>

              {/* Link Referral Utama & Generator */}
              <Card className="bg-white border-slate-200 shadow-sm rounded-2xl relative overflow-hidden">
                <div className="absolute top-0 right-0 w-32 h-32 bg-amber-500/5 blur-3xl -z-10 rounded-full mix-blend-multiply" />
                <CardHeader className="pb-4 pt-6 px-7">
                  <CardTitle className="text-slate-900 font-extrabold text-lg flex items-center gap-2">🔗 Link Referral Aktif</CardTitle>
                  <CardDescription className="text-slate-500 text-xs font-medium leading-relaxed">Sebarkan link ini di media sosial kamu demi meraup komisi sebanyak-banyaknya atas setiap transaksi!</CardDescription>
                </CardHeader>
                <CardContent className="pt-0 px-7 pb-7">
                  <div className="flex flex-col gap-4 mt-1">

                    <div className="space-y-1.5">
                      <label className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Pilih Produk Spesifik (Opsional)</label>
                      <select 
                        value={selectedProductSlug}
                        onChange={(e) => setSelectedProductSlug(e.target.value)}
                        className="flex h-10 w-full items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 ring-offset-white focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-colors font-medium appearance-none cursor-pointer shadow-sm"
                        style={{ backgroundImage: `url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e")`, backgroundPosition: `right 0.75rem center`, backgroundRepeat: `no-repeat`, backgroundSize: `1.5em 1.5em` }}
                      >
                        <option value="">-- Semua Produk (Default) --</option>
                        {products.map(p => (
                          <option key={p.id} value={p.slug}>{p.name}</option>
                        ))}
                      </select>
                    </div>

                    <div className="flex-1 truncate bg-slate-50 border border-slate-200 text-slate-700 text-xs px-4 py-3 rounded-xl font-mono shadow-inner font-semibold transition-all">
                      {selectedProductSlug 
                        ? `${window.location.origin}/products/${selectedProductSlug}?ref=AFF${user.id}X8`
                        : `${window.location.origin}/shop?ref=AFF${user.id}X8`}
                    </div>
                    <Button 
                      variant="outline" 
                      onClick={handleCopyLink} 
                      className="w-full bg-amber-50 text-amber-600 hover:bg-amber-100 border border-amber-200 font-bold rounded-xl"
                    >
                      Salin Link Referral
                    </Button>
                  </div>
                </CardContent>
              </Card>

            </div>

            {/* ── Kanan: Aktivitas Performa Chart Table ──────── */}
            <div className="lg:col-span-2">
              <Card className="bg-white border-slate-200 shadow-sm h-full rounded-2xl">
                <CardHeader className="pb-5 pt-7 px-7 border-b border-slate-100">
                  <CardTitle className="text-slate-900 font-extrabold text-lg">📈 Performa 7 Hari Terakhir</CardTitle>
                  <CardDescription className="text-slate-500 font-medium text-xs mt-1">Ringkasan matrik interaksi pelanggan via link kamu.</CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                  <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm text-slate-600">
                      <thead className="bg-slate-50 border-b border-slate-100">
                        <tr>
                          <th className="px-7 py-5 font-bold text-slate-500 uppercase tracking-wider text-[11px]">Tanggal Rekap</th>
                          <th className="px-7 py-5 font-bold text-slate-500 uppercase tracking-wider text-[11px]">User Klik</th>
                          <th className="px-7 py-5 font-bold text-slate-500 uppercase tracking-wider text-[11px]">Transaksi Masuk</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100/80">
                        {data.chartData.labels && data.chartData.labels.length > 0 ? data.chartData.labels.map((date, idx) => (
                          <tr key={date} className="hover:bg-amber-50/40 transition-colors">
                            <td className="px-7 py-5 text-slate-900 font-bold">{date}</td>
                            <td className="px-7 py-5">
                              <span className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-blue-50 text-blue-600 font-extrabold text-xs border border-blue-100">
                                <span className="opacity-70">🖱️</span> {data.chartData.clicks[idx] || 0}
                              </span>
                            </td>
                            <td className="px-7 py-5">
                              {data.chartData.convs[idx] > 0 ? (
                                <span className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-600 font-extrabold text-xs border border-emerald-100">
                                  <span className="opacity-70">🛒</span> {data.chartData.convs[idx]}
                                </span>
                              ) : (
                                <span className="text-slate-400 text-xs px-3 font-medium">—</span>
                              )}
                            </td>
                          </tr>
                        )) : (
                          <tr>
                            <td colSpan={3} className="px-7 py-16 text-center text-slate-500">
                              <p className="text-4xl mb-4 opacity-30">📊</p>
                              <p className="font-semibold text-slate-700">Belum ada data rekapan hari ini.</p>
                              <p className="text-xs mt-1 font-medium">Bagikan link referral kamu sekarang juga!</p>
                            </td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </CardContent>
              </Card>
            </div>

          </div>
        </div>
      )}

      <Footer />
    </main>
  );
}
