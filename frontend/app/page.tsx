'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { apiGet } from '@/src/lib/api';
import { useUserStore } from '@/src/stores/useUserStore';
import type { Product } from '@/src/types/product';
import { ArrowRight, Bot, ChevronRight, Gift, ShoppingBag, Truck, UserPlus } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';

// Interfaces similar to shop
interface PaginatedResponse<T> {
  data: T[];
}

const formatRupiah = (n: number) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(n);

function ProductCard({ product }: { product: Product }) {
  const router = useRouter();
  return (
    <Card className="bg-white border-slate-200 hover:border-amber-500/50 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl flex flex-col overflow-hidden group">
      {/* Thumbnail */}
      <div className="relative h-48 bg-slate-100 overflow-hidden shrink-0">
        {product.thumbnailUrl ? (
          /* eslint-disable-next-line @next/next/no-img-element */
          <img
            src={product.thumbnailUrl.startsWith('http') ? product.thumbnailUrl : `http://localhost:8000/storage/${product.thumbnailUrl}`}
            alt={product.name}
            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
          />
        ) : (
          <div className="w-full h-full flex flex-col items-center justify-center text-slate-400">
            <span className="text-4xl mb-2">📦</span>
            <span className="text-xs font-medium uppercase tracking-wider">{product.brand}</span>
          </div>
        )}
        <div className="absolute top-3 left-3">
          <span className="text-[10px] px-2.5 py-1 rounded-full font-bold uppercase tracking-wider bg-white/95 text-slate-800 shadow-sm backdrop-blur-sm">
            {product.category}
          </span>
        </div>
      </div>
      
      {/* Content */}
      <CardContent className="flex-1 pt-4 pb-2 px-5">
        <p className="text-[11px] text-slate-400 mb-1.5 font-bold uppercase tracking-wider">{product.brand} · {product.type}</p>
        <h3 className="text-slate-900 font-bold text-base leading-snug line-clamp-2">
          {product.name}
        </h3>
      </CardContent>

      <CardFooter className="px-5 pb-5 pt-0 flex items-center justify-between gap-2 border-t border-slate-100 mt-4">
        <div>
          <p className="text-amber-500 font-extrabold text-lg leading-tight mt-3">{formatRupiah(product.price)}</p>
        </div>
        <Button 
          size="sm" 
          onClick={() => router.push(`/products/${product.slug}`)}
          className="mt-3 bg-slate-900 hover:bg-slate-800 text-white font-semibold shadow-md rounded-full px-4"
        >
          Lihat
        </Button>
      </CardFooter>
    </Card>
  );
}

export default function LandingPage() {
  const router = useRouter();
  const { user } = useUserStore();
  const [featured, setFeatured] = useState<Product[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  const isAffiliate = user?.role === 'affiliate';
  const hasTelegram = Boolean(user?.telegram_chat_id || user?.telegramChatId);
  const showAffiliateBanner = !isAffiliate;
  const showTelegramBanner = !hasTelegram;
  const showSection = showAffiliateBanner || showTelegramBanner;

  useEffect(() => {
    async function loadFeatured() {
      try {
        const res = await apiGet<PaginatedResponse<Product>>('/products?limit=4');
        setFeatured((res.data || []).slice(0, 4));
      } catch (err) {
        console.error('Failed to load products', err);
      } finally {
        setIsLoading(false);
      }
    }
    loadFeatured();
  }, []);

  return (
    <main className="min-h-screen bg-[#f8f9fa] selection:bg-amber-200 selection:text-amber-900 font-sans pt-16">
      <Navbar />

      {/* ── 1. Hero Section ────────────────────────────────────────── */}
      <section className="relative pt-32 pb-24 md:pt-40 md:pb-36 overflow-hidden px-4">
        <div className="absolute top-0 left-1/2 w-full -translate-x-1/2 h-[500px] bg-gradient-to-b from-white to-[#f8f9fa] -z-10" />
        <div className="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-slate-200 to-transparent" />
        
        <div className="max-w-5xl mx-auto text-center animate-in fade-in slide-in-from-bottom-8 duration-1000">
          <div className="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white border border-slate-200 shadow-sm text-xs font-semibold text-slate-600 mb-8">
            <span className="relative flex h-2 w-2">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
              <span className="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
            </span>
            Platform Transaksi Suku Cadang Modern
          </div>
          <h1 className="text-5xl md:text-7xl font-extrabold text-[#1a1a1a] tracking-tight mb-6 leading-[1.1]">
            TDR HPZ Store: <br className="hidden md:block" />
            <span className="text-transparent bg-clip-text bg-gradient-to-r from-amber-500 to-orange-500">
              Seamless Transaction
            </span>
          </h1>
          <p className="text-lg md:text-xl text-slate-500 max-w-2xl mx-auto mb-10 leading-relaxed font-medium">
            Temukan performa sesungguhnya dari kuda besimu. Dapatkan suku cadang balap asli dengan pengalaman belanja yang cepat, aman, dan tanpa hambatan.
          </p>
          <div className="flex flex-col sm:flex-row items-center justify-center gap-4">
            <Button 
              onClick={() => router.push('/shop')}
              className="w-full sm:w-auto bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold px-8 py-6 text-lg rounded-full shadow-xl shadow-amber-500/20 transition-all hover:scale-105"
            >
              Mulai Belanja <ShoppingBag className="ml-2 w-5 h-5" />
            </Button>
          </div>
        </div>
      </section>

      {/* ── 2. Featured Products ───────────────────────────────────── */}
      <section className="py-24 bg-white border-y border-slate-100">
        <div className="max-w-7xl mx-auto px-4 md:px-8">
          <div className="flex flex-col sm:flex-row justify-between items-end gap-4 mb-12">
            <div>
              <h2 className="text-3xl font-extrabold text-slate-900 tracking-tight">Produk Unggulan</h2>
              <p className="text-slate-500 mt-2 font-medium">Rilisan terbaru dari High Performance Zone.</p>
            </div>
            <Button variant="ghost" className="text-amber-600 hover:text-amber-700 hover:bg-amber-50 font-bold group" onClick={() => router.push('/shop')}>
              Lihat Semua <ArrowRight className="ml-2 w-4 h-4 transition-transform group-hover:translate-x-1" />
            </Button>
          </div>

          {isLoading ? (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
              {[...Array(4)].map((_, i) => (
                <div key={i} className="h-96 rounded-2xl bg-slate-50 border border-slate-100 animate-pulse" />
              ))}
            </div>
          ) : featured.length > 0 ? (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
              {featured.map(p => <ProductCard key={p.id} product={p} />)}
            </div>
          ) : (
             <div className="text-center py-12 text-slate-400 font-medium">Belum ada produk untuk ditampilkan.</div>
          )}
        </div>
      </section>

      {/* ── 3. Kenapa Pilih TDR HPZ? ─────────────────────────────── */}
      <section className="py-24 bg-[#f8f9fa] relative overflow-hidden text-center">
        <div className="max-w-7xl mx-auto px-4 md:px-8">
          <div className="mb-16">
            <h2 className="text-3xl font-extrabold text-slate-900 tracking-tight">Kenapa Pilih TDR HPZ?</h2>
            <p className="text-slate-500 mt-3 font-medium max-w-xl mx-auto leading-relaxed">
              Kami berkomitmen memberikan pengalaman belanja terbaik untuk kebutuhan motor Anda
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-4 gap-8">
            {[
              { icon: '🏆', title: 'Produk Original', desc: '100% produk asli bersertifikat, kami tidak jual produk KW' },
              { icon: '🚚', title: 'Pengiriman Cepat', desc: 'Same-day processing, estimasi 1-3 hari ke seluruh Indonesia' },
              { icon: '🔔', title: 'Notifikasi Real-Time', desc: 'Update pesanan langsung ke Telegram Anda, 24/7' },
              { icon: '🔒', title: 'Pembayaran Aman', desc: 'Diproses via Midtrans — bank transfer, QRIS & e-wallet' },
            ].map((feat, idx) => (
              <Card key={idx} className="bg-white border-slate-200 shadow-sm hover:shadow-lg hover:-translate-y-1 transition-all rounded-2xl overflow-hidden group">
                <CardContent className="p-8 flex flex-col items-center">
                  <div className="w-16 h-16 rounded-2xl bg-slate-50 border border-slate-100 flex items-center justify-center text-3xl mb-6 group-hover:bg-amber-50 group-hover:border-amber-200 transition-colors">
                    {feat.icon}
                  </div>
                  <h4 className="text-lg font-bold text-slate-900 mb-2">{feat.title}</h4>
                  <p className="text-slate-500 text-sm font-medium leading-relaxed bg-white">
                    {feat.desc}
                  </p>
                </CardContent>
              </Card>
            ))}
          </div>
        </div>
      </section>

      {/* ── 4. Testimonials ───────────────────────────────────────── */}
      <section className="py-24 bg-white border-y border-slate-100">
        <div className="max-w-7xl mx-auto px-4 md:px-8">
          <div className="text-center mb-16">
            <h2 className="text-3xl font-extrabold text-slate-900 tracking-tight">Apa Kata Pelanggan Kami</h2>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
            {[
              { name: 'Budi Santoso', role: 'Mekanik Bengkel', text: 'Sudah 3 tahun langganan di sini. Kualitas spare part selalu konsisten dan harga jauh lebih murah dari toko offline.' },
              { name: 'Siti Rahayu', role: 'Pengguna Honda Beat', text: 'Pemesanan gampang, langsung beli dan bayar. Notif Telegram-nya keren, bisa tahu status pesanan real-time!' },
              { name: 'Dimas Prasetyo', role: 'Mekanik Freelance', text: 'Program affiliate TDR-HPZ bantu penghasilan tambahan. Komisi 10% cair otomatis tanpa ribet follow-up admin.' },
            ].map((testimonial, idx) => (
              <Card key={idx} className="bg-white border-slate-200 shadow-sm hover:shadow-lg transition-shadow border-l-4 border-l-amber-500 rounded-2xl">
                <CardContent className="p-8 flex flex-col h-full text-left">
                  <div className="flex gap-1 mb-4 text-amber-400 text-sm">
                    {[1,2,3,4,5].map(i => <span key={i}>★</span>)}
                  </div>
                  <p className="text-slate-600 text-sm font-medium leading-relaxed italic mb-6 flex-1">
                    &quot;{testimonial.text}&quot;
                  </p>
                  <div>
                    <h4 className="font-bold text-slate-900">{testimonial.name}</h4>
                    <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">{testimonial.role}</span>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </div>
      </section>

      {/* ── 5. Step-by-Step Guide ─────────────────────────────────── */}
      <section className="py-24 bg-[#f8f9fa] relative overflow-hidden">
        <div className="max-w-7xl mx-auto px-4 md:px-8">
          <div className="text-center mb-16">
            <h2 className="text-3xl font-extrabold text-slate-900 tracking-tight">Cara Berbelanja</h2>
            <p className="text-slate-500 mt-3 font-medium max-w-xl mx-auto leading-relaxed">
              Bertransaksi di platform kami sangat mudah dan cepat. Ikuti langkah sederhana berikut untuk mendapatkan part idamanmu.
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-4 gap-8 relative">
            {/* Connecting lines for desktop */}
            <div className="hidden md:block absolute top-[45px] left-[12%] right-[12%] h-0.5 bg-gradient-to-r from-slate-200 via-slate-300 to-slate-200 z-0" />

            {[
              { icon: UserPlus, title: 'Buat Akun', desc: 'Daftarkan datamu untuk melacak pesanan.' },
              { icon: ShoppingBag, title: 'Pilih Produk', desc: 'Jelajahi katalog dan masukkan ke keranjang.' },
              { icon: Truck, title: 'Bayar & Kirim', desc: 'Selesaikan transaksi dengan sistem aman.' },
              { icon: Gift, title: 'Terima Pesanan', desc: 'Barang unggulan sampai di tanganmu.' },
            ].map((step, idx) => (
              <div key={idx} className="relative z-10 flex flex-col items-center text-center group">
                <div className="w-24 h-24 rounded-full bg-white border-8 border-[#f8f9fa] shadow-xl flex items-center justify-center mb-6 text-slate-300 group-hover:text-amber-500 group-hover:scale-110 group-hover:shadow-2xl transition-all duration-300">
                  <step.icon className="w-8 h-8" />
                </div>
                <h4 className="text-lg font-extrabold text-slate-900 mb-2">{step.title}</h4>
                <p className="text-slate-500 text-sm leading-relaxed max-w-[200px] font-medium">{step.desc}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── 6 & 7. Affiliate Teaser & Telegram Banner ──────────────── */}
      {showSection && (
        <section className="py-24 bg-white">
          <div className={`max-w-7xl mx-auto px-4 md:px-8 grid grid-cols-1 ${showAffiliateBanner && showTelegramBanner ? 'md:grid-cols-2' : ''} gap-8`}>
            
            {/* Affiliate Banner */}
            {showAffiliateBanner && (
              <div className="rounded-3xl bg-amber-50 border border-amber-100 p-8 md:p-12 relative overflow-hidden group">
                <div className="absolute -right-4 -bottom-4 bg-amber-200 rounded-full w-48 h-48 opacity-40 blur-2xl group-hover:scale-150 transition-transform duration-700" />
                <div className="relative z-10">
                  <div className="w-14 h-14 bg-amber-500 rounded-2xl flex items-center justify-center text-white mb-6 shadow-lg shadow-amber-500/30">
                    <Gift className="w-7 h-7" />
                  </div>
                  <h3 className="text-2xl font-extrabold text-slate-900 mb-3">Gabung Affiliate</h3>
                  <p className="text-slate-600 mb-8 leading-relaxed font-medium">
                    Dapatkan <strong className="text-amber-600">komisi 10%</strong> dari setiap pembelian menggunakan link referral unik milikmu. Cuan makin kencang bersama TDR HPZ!
                  </p>
                  <Button 
                    onClick={() => router.push('/affiliate/register')}
                    className="bg-slate-900 hover:bg-slate-800 text-white rounded-full px-6 py-6 font-bold shadow-md hover:shadow-xl transition-all"
                  >
                    Daftar Sekarang <ChevronRight className="ml-1 w-4 h-4" />
                  </Button>
                </div>
              </div>
            )}

            {/* Telegram Banner */}
            {showTelegramBanner && (
              <div className="rounded-3xl bg-blue-50 border border-blue-100 p-8 md:p-12 relative overflow-hidden group">
                <div className="absolute -right-4 -bottom-4 bg-blue-200 rounded-full w-48 h-48 opacity-40 blur-2xl group-hover:scale-150 transition-transform duration-700" />
                <div className="relative z-10">
                  <div className="w-14 h-14 bg-blue-500 rounded-2xl flex items-center justify-center text-white mb-6 shadow-lg shadow-blue-500/30">
                    <Bot className="w-7 h-7" />
                  </div>
                  <h3 className="text-2xl font-extrabold text-slate-900 mb-3">Telegram Notif</h3>
                  <p className="text-slate-600 mb-8 leading-relaxed font-medium">
                    Pantau pesanan atau komisi affiliatemu secara <strong className="text-blue-600">real-time</strong>. Integrasikan akunmu sekarang dengan Telegram Bot resmi kami.
                  </p>
                  <Button 
                    onClick={() => router.push('/telegram/setup')}
                    className="bg-blue-600 hover:bg-blue-700 text-white rounded-full px-6 py-6 font-bold shadow-md shadow-blue-600/20 hover:shadow-xl transition-all"
                  >
                    Hubungkan Bot <ChevronRight className="ml-1 w-4 h-4" />
                  </Button>
                </div>
              </div>
            )}

          </div>
        </section>
      )}

      {/* ── Footer ─────────────────────────────────────────────────── */}
      <Footer />
    </main>
  );
}
