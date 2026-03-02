'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { apiGet } from '@/src/lib/api';
import type { User } from '@/src/types/user';
import { useParams, useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

// ─── Types ────────────────────────────────────────────────────
interface TrackingLog {
  id: number;
  status: string;
  status_title: string;
  description: string | null;
  created_at: string;
}

interface OrderItem {
  id: number;
  product_name: string;
  quantity: number;
  subtotal: number;
  affiliate_code: string | null;
}

interface OrderDetail {
  id: number;
  order_number: string;
  status: 'pending' | 'verified' | 'processing' | 'shipped' | 'completed' | 'cancelled';
  total_amount: number;
  payment_method: string | null;
  payment_verified_at: string | null;
  shipping_courier: string | null;
  shipping_tracking_number: string | null;
  shipping_address: string | null;
  created_at: string;
  items: OrderItem[];
  trackingLogs: TrackingLog[]; // Returned as trackingLogs camelCase or tracking_logs in API? In Laravel show() it says trackingLogs. We will handle both safely.
  customer: User;
}

import { useUserStore } from '@/src/stores/useUserStore';

const formatRupiah = (amount: number) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(amount);

const formatDate = (dateStr: string) => {
  return new Date(dateStr).toLocaleDateString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit'
  }) + ' WIB';
};

const getStatusBadge = (status: string) => {
  const map: Record<string, { label: string, color: string, icon: string }> = {
    'pending': { label: 'Menunggu Pembayaran', color: 'bg-amber-100 text-amber-600 border-amber-300', icon: '⏱️' },
    'verified': { label: 'Pembayaran Dikonfirmasi', color: 'bg-blue-100 text-blue-600 border-blue-300', icon: '✅' },
    'processing': { label: 'Sedang Diproses', color: 'bg-indigo-100 text-indigo-600 border-indigo-300', icon: '⚙️' },
    'shipped': { label: 'Dikirim', color: 'bg-emerald-100 text-emerald-600 border-emerald-300', icon: '🚚' },
    'completed': { label: 'Selesai', color: 'bg-emerald-100 text-emerald-600 border-emerald-400', icon: '📦' },
    'cancelled': { label: 'Dibatalkan', color: 'bg-red-100 text-red-600 border-red-300', icon: '✖️' }
  };
  const badge = map[status] || { label: status, color: 'bg-slate-100 text-slate-600 border-slate-300', icon: '◾' };
  
  return (
    <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider border ${badge.color}`}>
      <span>{badge.icon}</span> {badge.label}
    </span>
  );
};

// ─── Main Component ────────────────────────────────────────────
export default function OrderTrackingPage() {
  const router = useRouter();
  const params = useParams();
  const orderNumber = params.orderNumber as string;
  
  const { user, isLoading: isStoreLoading } = useUserStore();
  const [order, setOrder] = useState<OrderDetail | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    if (isStoreLoading) return;
    if (!user) {
      router.push(`/login?redirect=/orders/${orderNumber}`);
      return;
    }

    const loadOrderDetail = async () => {
      try {
        // We use ANY to handle JSON since standard Laravel Resource returns inside { data: ... }
        const res = await apiGet<{ data: OrderDetail }>(`/orders/${orderNumber}`);
        setOrder(res.data);
      } catch (err: unknown) {
        toast.error('Gagal memuat detail pesanan. Pastikan kamu memiliki akses.');
        router.push('/orders');
      } finally {
        setIsLoading(false);
      }
    };

    loadOrderDetail();
  }, [orderNumber, router, user, isStoreLoading]);


  if (isLoading) {
    return (
      <main className="min-h-screen bg-slate-50 flex items-center justify-center">
        <div className="flex items-center gap-3 text-amber-500 bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
          <svg className="animate-spin h-6 w-6" viewBox="0 0 24 24" fill="none">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          <span className="font-semibold text-slate-700">Mencari Pesanan...</span>
        </div>
      </main>
    );
  }

  if (!order) return null;

  // Handle resource property keys properly
  const logs = (order as unknown as { tracking_logs?: TrackingLog[] }).tracking_logs || order.trackingLogs || [];

  return (
    <main className="min-h-screen flex flex-col bg-slate-50">
      <Navbar />
      <div className="flex-grow px-4 pt-28 pb-12">
        <div className="max-w-5xl mx-auto space-y-6">

        {/* ── Header ────────────────────────────────────────── */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-200 pb-6">
          <div className="flex items-center gap-4">
            <Button onClick={() => router.push('/orders')} variant="outline" size="icon" className="bg-white border-slate-200 text-slate-600 hover:text-slate-900 shrink-0 shadow-sm rounded-full">
              &larr;
            </Button>
            <div>
              <h1 className="text-slate-900 font-bold text-xl md:text-3xl tracking-tight">
                Pesanan <span className="text-amber-500 ml-1 font-mono">{order.order_number}</span>
              </h1>
              <p className="text-slate-500 text-sm mt-1 font-medium">
                Dipesan: {formatDate(order.created_at)}
              </p>
            </div>
          </div>
          <div className="flex md:block">
            {getStatusBadge(order.status)}
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">

          {/* ── Kiri: Ringkasan & Detail Produk ──────────────── */}
          <div className="space-y-6">
            <Card className="bg-white border-slate-200 shadow-sm rounded-2xl overflow-hidden">
              <CardHeader className="pb-3 border-b border-slate-100 bg-slate-50/50">
                <CardTitle className="text-slate-800 text-base">Informasi Pembayaran &amp; Pengiriman</CardTitle>
              </CardHeader>
              <CardContent className="pt-4 space-y-3">
                
                <div className="flex justify-between text-sm">
                  <span className="text-slate-500">Metode Pembayaran</span>
                  <span className="text-slate-800 font-medium">{order.payment_method || 'Midtrans'}</span>
                </div>
                
                {order.payment_verified_at && (
                  <div className="flex justify-between text-sm">
                    <span className="text-slate-500">Dikonfirmasi</span>
                    <span className="text-slate-800 font-medium">{formatDate(order.payment_verified_at)}</span>
                  </div>
                )}
                
                {order.shipping_courier && (
                  <div className="flex justify-between text-sm">
                    <span className="text-slate-500">Kurir Pengiriman</span>
                    <span className="text-amber-600 font-semibold uppercase">{order.shipping_courier}</span>
                  </div>
                )}

                {order.shipping_tracking_number && (
                  <div className="flex justify-between items-center text-sm pt-2 border-t border-slate-100 mt-2">
                    <span className="text-slate-500">Nomor Resi</span>
                    <span className="bg-slate-100 border border-slate-200 text-amber-600 font-mono text-xs px-2 py-1 rounded font-bold">
                      {order.shipping_tracking_number}
                    </span>
                  </div>
                )}

                {order.shipping_address && (
                  <>
                    <div className="w-full h-px bg-slate-100 my-3" />
                    <div className="text-sm">
                      <p className="text-slate-500 mb-1 font-medium">Alamat Pengiriman</p>
                      <p className="text-slate-700 leading-relaxed">{order.shipping_address}</p>
                    </div>
                  </>
                )}
              </CardContent>
            </Card>

            <Card className="bg-white border-slate-200 shadow-sm rounded-2xl overflow-hidden">
              <CardHeader className="pb-3 border-b border-slate-100 bg-slate-50/50">
                <CardTitle className="text-slate-800 text-base">Daftar Produk ({order.items.length})</CardTitle>
              </CardHeader>
              <CardContent className="pt-4 p-0">
                <div className="divide-y divide-slate-100">
                  {order.items.map(item => (
                    <div key={item.id} className="p-4 flex flex-col md:flex-row justify-between md:items-center gap-3">
                      <div>
                        <p className="text-slate-800 font-medium">{item.product_name}</p>
                        <div className="flex items-center gap-2 mt-1">
                          <span className="text-slate-500 text-sm">x {item.quantity}</span>
                          {item.affiliate_code && (
                            <span className="px-1.5 py-0.5 rounded bg-amber-50 border border-amber-200 text-amber-600 text-[10px] uppercase font-bold tracking-wider flex items-center gap-1">
                              <span>🏷️</span> REF: {item.affiliate_code}
                            </span>
                          )}
                        </div>
                      </div>
                      <div className="text-slate-700 font-semibold md:text-right">
                        {formatRupiah(item.subtotal)}
                      </div>
                    </div>
                  ))}
                </div>
                
                <div className="bg-slate-50 p-4 border-t border-slate-100 flex justify-between items-center">
                  <span className="text-slate-500 font-medium">Total Pesanan</span>
                  <span className="text-amber-500 font-bold text-xl">{formatRupiah(order.total_amount)}</span>
                </div>
              </CardContent>
            </Card>
          </div>

          {/* ── Kanan: Lacak / Tracking Timeline ──────────────── */}
          <div className="space-y-6">
            
            <Card className="bg-white border-slate-200 shadow-sm rounded-2xl relative overflow-hidden">
              <div className="absolute top-0 right-0 w-32 h-32 bg-amber-500/10 rounded-full blur-3xl -mr-10 -mt-10" />
              
              <CardHeader className="pb-4 border-b border-slate-100 bg-slate-50/50">
                <CardTitle className="text-slate-800 text-base flex items-center gap-2">
                  <span className="text-amber-500">📍</span> Lacak Pesanan
                </CardTitle>
                <CardDescription className="text-slate-500 text-xs">Riwayat status pemrosesan pesanan.</CardDescription>
              </CardHeader>
              
              <CardContent className="pt-6 relative">
                {logs.length === 0 ? (
                  <div className="text-center py-10">
                    <span className="text-4xl text-slate-200 mb-3 block">⏳</span>
                    <p className="text-slate-400 text-sm">Belum ada riwayat update pesanan.</p>
                  </div>
                ) : (
                  <div className="relative pl-6 space-y-8">
                    {/* Vertical line passing through */}
                    <div className="absolute left-[11px] top-2 bottom-6 w-px bg-slate-200" />
                    
                    {logs.map((log: TrackingLog, idx: number) => {
                      const isLatest = idx === logs.length - 1;
                      return (
                        <div key={log.id || idx} className="relative z-10 w-full">
                          {/* Dot indicator */}
                          <div className={`absolute -left-6 w-5 h-5 rounded-full flex items-center justify-center border-2 border-white ${
                            isLatest ? 'bg-amber-500 text-white ring-4 ring-amber-500/20' : 'bg-emerald-500 text-white'
                          }`}>
                            {isLatest ? '▾' : '✓'}
                          </div>

                          <div className="pl-4">
                            <h4 className={`text-sm font-bold ${isLatest ? 'text-amber-600' : 'text-slate-700'}`}>
                              {log.status_title}
                            </h4>
                            {log.description && (
                              <p className="text-slate-600 text-xs mt-1.5 leading-relaxed bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                                {log.description}
                              </p>
                            )}
                            <p className="text-slate-400 text-[10px] mt-2 font-mono font-medium">
                              {formatDate(log.created_at)}
                            </p>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Telegram Reminder */}
            {user?.telegramChatId ? (
              <div className="bg-blue-50 border border-blue-100 rounded-2xl p-4 flex gap-3 items-start shadow-sm">
                <span className="text-blue-500 text-xl">📱</span>
                <div>
                  <p className="text-blue-700 text-sm font-bold">Notifikasi Aktif</p>
                  <p className="text-blue-600/70 text-xs mt-1 leading-relaxed font-medium">
                    Setiap perubahan status dari pesanan ini akan otomatis dikirim ke akun Telegram kamu secara real-time.
                  </p>
                </div>
              </div>
            ) : (
              <div className="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex flex-col sm:flex-row justify-between items-center gap-4 shadow-sm">
                <div className="flex gap-3 items-start">
                  <span className="text-amber-500 text-xl">⚠️</span>
                  <div>
                    <p className="text-amber-700 text-sm font-bold">Telegram Belum Terhubung</p>
                    <p className="text-amber-600/80 text-xs mt-1 leading-relaxed font-medium">
                      Lacak paket instan tanpa buka web? Hubungkan bot Telegram kami sekarang di pengaturan profilmu!
                    </p>
                  </div>
                </div>
                <Button onClick={() => router.push('/profile')} size="sm" className="bg-amber-500 hover:bg-amber-400 text-white shadow shadow-amber-500/30 font-bold whitespace-nowrap rounded-full px-5">
                  Hubungkan
                </Button>
              </div>
            )}

          </div>

        </div>
      </div>
      </div>
      <Footer />
    </main>
  );
}
