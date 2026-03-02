'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { apiGet } from '@/src/lib/api';
import { ArrowLeft, Coins } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

// ── Types ──
interface Order {
  id: number;
  order_number: string;
}

interface Commission {
  id: number;
  order_id: number;
  amount: number;
  status: string;
  created_at: string;
  order?: Order;
}

interface PaginatedCommissions {
  data: Commission[];
  current_page: number;
  last_page: number;
  total: number;
}

// ── Helpers ──
import { useUserStore } from '@/src/stores/useUserStore';

const formatRupiah = (amount: number) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(amount);

const formatDate = (dateStr: string) => {
  return new Date(dateStr).toLocaleDateString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit'
  });
};

const getStatusBadge = (status: string) => {
  const map: Record<string, string> = {
    'pending': 'bg-amber-100 text-amber-700',
    'approved': 'bg-emerald-100 text-emerald-700',
    'rejected': 'bg-red-100 text-red-700',
  };
  const color = map[status] || 'bg-slate-100 text-slate-700';
  const label = status === 'approved' ? 'Disetujui' : status === 'pending' ? 'Tertunda' : 'Ditolak';
  return (
    <span className={`px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider rounded-md ${color}`}>
      {label}
    </span>
  );
};

export default function CommissionsPage() {
  const router = useRouter();
  const { user, isLoading: isStoreLoading } = useUserStore();
  const [data, setData] = useState<Commission[]>([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    if (isStoreLoading) return;

    if (!user) {
      router.push('/login?redirect=/affiliate/commissions');
      return;
    }
    loadCommissions(1);
  }, [router, user, isStoreLoading]);

  const loadCommissions = async (page: number) => {
    setIsLoading(true);
    try {
      const res = await apiGet<PaginatedCommissions>(`/affiliate/commissions?page=${page}`);
      setData(res.data);
      setMeta({ current_page: res.current_page, last_page: res.last_page, total: res.total });
    } catch (err: unknown) {
      toast.error('Gagal memuat histori komisi.');
    } finally {
      setIsLoading(false);
    }
  };

  if (isLoading && data.length === 0) {
    return (
      <main className="min-h-screen bg-[#f8f9fa] flex items-center justify-center pt-16">
        <Navbar />
        <div className="flex flex-col items-center gap-4 text-amber-500">
          <svg className="animate-spin h-8 w-8" viewBox="0 0 24 24" fill="none">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          <span className="font-bold text-slate-700">Memuat data komisi...</span>
        </div>
      </main>
    );
  }

  return (
    <main className="min-h-screen bg-[#f8f9fa] font-sans pt-16 flex flex-col">
      <Navbar />

      <div className="max-w-5xl mx-auto space-y-8 flex-1 w-full px-4 py-10 mt-2">
        
        {/* Header */}
        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4 pb-4">
          <div>
            <Button variant="ghost" className="mb-4 text-slate-500 hover:text-slate-900 -ml-4" onClick={() => router.push('/affiliate/dashboard')}>
              <ArrowLeft className="w-4 h-4 mr-2" />
              Kembali ke Dashboard
            </Button>
            <h1 className="text-slate-900 font-extrabold text-3xl tracking-tight flex items-center gap-3">
              <span className="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600 border border-emerald-200 shadow-sm">
                <Coins className="w-7 h-7" />
              </span>
              Histori Komisi
            </h1>
            <p className="text-slate-500 font-medium text-sm mt-3 md:ml-[60px] max-w-xl">
              Rincian seluruh komisi yang Anda peroleh dari pesanan yang sukses dikonversi.
            </p>
          </div>
        </div>

        {/* Table Card */}
        <Card className="bg-white border-slate-200 shadow-sm rounded-2xl overflow-hidden">
          <CardHeader className="bg-slate-50/50 border-b border-slate-100">
            <CardTitle className="text-base font-bold text-slate-900 flex justify-between items-center">
              <span>Data Transaksi ({meta.total})</span>
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full text-sm text-left">
                <thead className="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider font-bold">
                  <tr>
                    <th className="px-6 py-4 border-b border-slate-200">Pesanan (Order)</th>
                    <th className="px-6 py-4 border-b border-slate-200">Nominal Komisi</th>
                    <th className="px-6 py-4 border-b border-slate-200">Tanggal Transaksi</th>
                    <th className="px-6 py-4 border-b border-slate-200">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {data.length === 0 ? (
                    <tr>
                      <td colSpan={4} className="px-6 py-12 text-center text-slate-400 font-medium">
                        Belum ada data komisi tercatat.
                      </td>
                    </tr>
                  ) : (
                    data.map((item) => (
                      <tr key={item.id} className="hover:bg-slate-50/50 transition-colors">
                        <td className="px-6 py-4">
                          <span className="font-mono font-bold text-slate-900 bg-slate-100 px-2 py-1 rounded">
                            {item.order?.order_number || `#${item.order_id}`}
                          </span>
                        </td>
                        <td className="px-6 py-4 font-extrabold text-emerald-600">
                          {formatRupiah(item.amount)}
                        </td>
                        <td className="px-6 py-4 text-slate-500 font-medium">
                          {formatDate(item.created_at)}
                        </td>
                        <td className="px-6 py-4">
                          {getStatusBadge(item.status)}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            {/* Pagination Controls */}
            {meta.last_page > 1 && (
              <div className="p-4 border-t border-slate-100 flex items-center justify-between bg-slate-50/50">
                <Button 
                  variant="outline" 
                  size="sm"
                  disabled={meta.current_page === 1}
                  onClick={() => loadCommissions(meta.current_page - 1)}
                >
                  Sebelumnya
                </Button>
                <span className="text-xs font-bold text-slate-500">
                  Hal {meta.current_page} dari {meta.last_page}
                </span>
                <Button 
                  variant="outline" 
                  size="sm"
                  disabled={meta.current_page === meta.last_page}
                  onClick={() => loadCommissions(meta.current_page + 1)}
                >
                  Selanjutnya
                </Button>
              </div>
            )}
          </CardContent>
        </Card>

      </div>
      <Footer />
    </main>
  );
}
