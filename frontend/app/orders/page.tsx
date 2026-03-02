'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { apiGet } from '@/src/lib/api';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

// ─── Types ────────────────────────────────────────────────────
interface TrackingLog {
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
}

interface Order {
  id: number;
  order_number: string;
  status: string;
  total_amount: number;
  created_at: string;
  items: OrderItem[];
  tracking_logs: TrackingLog[]; // Assumed format from resource
}

interface OrdersResponse {
  data: Order[];
  current_page: number;
  last_page: number;
  total: number;
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
    'pending': { label: 'Menunggu', color: 'bg-amber-100 text-amber-600 border-amber-200', icon: '⏱️' },
    'verified': { label: 'Dikonfirmasi', color: 'bg-blue-100 text-blue-600 border-blue-200', icon: '✅' },
    'processing': { label: 'Diproses', color: 'bg-indigo-100 text-indigo-600 border-indigo-200', icon: '⚙️' },
    'shipped': { label: 'Dikirim', color: 'bg-emerald-100 text-emerald-600 border-emerald-200', icon: '🚚' },
    'completed': { label: 'Selesai', color: 'bg-emerald-100 text-emerald-700 border-emerald-200 font-extrabold', icon: '📦' },
    'cancelled': { label: 'Dibatalkan', color: 'bg-red-100 text-red-600 border-red-200', icon: '✖️' }
  };
  const badge = map[status] || { label: status, color: 'bg-slate-100 text-slate-500 border-slate-200', icon: '◾' };
  
  return (
    <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider border shadow-sm ${badge.color}`}>
      <span>{badge.icon}</span> {badge.label}
    </span>
  );
};

// ─── Component ────────────────────────────────────────────────
export default function OrdersPage() {
  const router = useRouter();
  const { user, isLoading: isStoreLoading } = useUserStore();
  const [orders, setOrders] = useState<Order[]>([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    if (isStoreLoading) return;
    if (!user) {
      const isLoggingOut = typeof sessionStorage !== 'undefined' && sessionStorage.getItem('tdr_is_logging_out') === 'true';
      if (!isLoggingOut) {
        router.push('/login?redirect=/orders');
      }
      return;
    }
    loadOrders(1);
  }, [router, user, isStoreLoading]);

  const loadOrders = async (page: number) => {
    setIsLoading(true);
    try {
      const res = await apiGet<OrdersResponse>(`/user/orders?page=${page}`);
      if (page === 1) {
        setOrders(res.data);
      } else {
        setOrders(prev => [...prev, ...res.data]);
      }
      setMeta({ current_page: res.current_page, last_page: res.last_page });
    } catch (err: unknown) {
      toast.error('Gagal memuat histori pesanan.');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <main className="min-h-screen bg-[#f8f9fa] font-sans pt-16 flex flex-col">
      <Navbar />

      {isLoading && orders.length === 0 ? (
        <div className="flex-1 flex items-center justify-center">
          <div className="flex items-center gap-3 text-amber-500">
            <svg className="animate-spin h-6 w-6" viewBox="0 0 24 24" fill="none">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            <span className="font-semibold text-slate-700">Memuat Pesanan...</span>
          </div>
        </div>
      ) : (
        <div className="max-w-4xl mx-auto space-y-6 px-4 py-8 flex-1 w-full mt-4">
          
          {/* Header */}
          <div className="flex flex-col md:flex-row md:items-end justify-between gap-4 pb-6">
            <div>
              <h1 className="text-slate-900 font-extrabold text-3xl tracking-tight flex items-center gap-3">
                <span className="w-12 h-12 rounded-xl bg-amber-100 flex items-center justify-center text-2xl border border-amber-200">
                  🧾
                </span>
                Histori Pesanan
              </h1>
              <p className="text-slate-500 font-medium text-sm mt-3 lg:ml-[60px]">
                Seluruh pesanan yang pernah kamu buat terekap transparan di sini.
              </p>
            </div>
            <Button onClick={() => router.push('/shop')} variant="outline" className="border-slate-200 text-slate-600 hover:text-slate-900 bg-white hover:bg-slate-50 shadow-sm rounded-full font-semibold">
              Kembali ke Katalog
            </Button>
          </div>

          {/* Orders List */}
          {orders.length === 0 ? (
            <Card className="bg-white border-slate-100 shadow-sm rounded-3xl">
              <CardContent className="flex flex-col items-center justify-center py-20 text-center space-y-4">
                <span className="text-6xl opacity-40">🛍️</span>
                <h3 className="text-slate-900 font-bold text-xl">Belum ada pesanan</h3>
                <p className="text-slate-500 font-medium text-sm max-w-sm">
                  Yuk mulai belanja di toko kami dan nikmati suku cadang asli dengan pengiriman cepat!
                </p>
                <Button onClick={() => router.push('/shop')} className="mt-4 bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold rounded-full px-8 shadow-sm">
                  Lihat Katalog Produk
                </Button>
              </CardContent>
            </Card>
          ) : (
            <div className="space-y-5">
              {orders.map(order => (
                <Card 
                  key={order.id} 
                  className="bg-white border-slate-200 shadow-sm hover:border-amber-400/50 hover:shadow-lg transition-all cursor-pointer block group rounded-2xl overflow-hidden"
                  onClick={() => router.push(`/orders/${order.order_number}`)}
                >
                  <CardContent className="p-6 flex flex-col md:flex-row gap-5 items-start md:items-center justify-between">
                    
                    {/* Info Kiri */}
                    <div className="flex items-start gap-5 flex-1 w-full">
                      <div className="w-14 h-14 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-center text-2xl shrink-0 group-hover:scale-110 transition-transform">
                        📦
                      </div>
                      <div className="w-full">
                        <div className="flex flex-wrap items-center gap-3 mb-1.5">
                          <span className="text-slate-900 font-extrabold font-mono text-sm tracking-wide">
                            {order.order_number}
                          </span>
                          {getStatusBadge(order.status)}
                        </div>
                        <p className="text-slate-500 font-medium text-xs mb-3">
                          {formatDate(order.created_at)}
                        </p>

                        {/* Products preview */}
                        <div className="text-sm text-slate-600 line-clamp-2 leading-relaxed bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                          {order.items.slice(0, 2).map(item => (
                            <span key={item.id} className="mr-2 after:content-[','] last:after:content-[''] font-medium">
                              <span className="text-amber-600 font-bold">{item.quantity}x</span> {item.product_name}
                            </span>
                          ))}
                          {order.items.length > 2 && (
                            <span className="italic text-slate-400 font-medium">+ {order.items.length - 2} produk lainnya</span>
                          )}
                        </div>
                      </div>
                    </div>

                    {/* Harga Kanan */}
                    <div className="flex w-full md:w-auto flex-row md:flex-col justify-between items-center md:items-end gap-2 border-t md:border-t-0 border-slate-100 pt-4 md:pt-0">
                      <div className="text-slate-500 font-medium text-xs md:hidden">Total Belanja</div>
                      <div className="text-slate-900 font-extrabold text-lg md:text-xl">
                        {formatRupiah(order.total_amount)}
                      </div>
                      <div className="text-amber-600 hover:text-amber-500 text-sm font-bold flex items-center gap-1.5 group-hover:translate-x-1 transition-transform bg-amber-50 px-3 py-1 rounded-full md:bg-transparent md:px-0 md:py-0 md:justify-end w-max">
                        Lacak Pesanan 
                        <span>→</span>
                      </div>
                    </div>

                  </CardContent>
                </Card>
              ))}

              {meta.current_page < meta.last_page && (
                <div className="flex justify-center pt-6 pb-4">
                  <Button 
                    onClick={() => loadOrders(meta.current_page + 1)} 
                    variant="outline" 
                    disabled={isLoading}
                    className="border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 bg-white font-semibold rounded-full px-6 shadow-sm"
                  >
                    {isLoading ? 'Memuat...' : 'Tampilkan Lebih Banyak'}
                  </Button>
                </div>
              )}
            </div>
          )}

        </div>
      )}

      <Footer />
    </main>
  );
}
