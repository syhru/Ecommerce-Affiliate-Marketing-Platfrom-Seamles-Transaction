'use client';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiGet, apiPost } from '@/src/lib/api';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

// ─── Types ────────────────────────────────────────────────────
interface AffiliateDashboardData {
  stats: {
    balance: number;
    total_commission: number;
  };
}

interface AffiliateProfile {
  bank_name: string;
  bank_account_number: string;
  bank_account_holder: string;
}

interface Withdrawal {
  id: number;
  amount: number;
  status: 'pending' | 'approved' | 'rejected';
  bank_name: string;
  bank_account_number: string;
  bank_account_holder: string;
  created_at: string;
}

interface WithdrawalsResponse {
  data: Withdrawal[];
  current_page: number;
  last_page: number;
  total: number;
}

// ─── Format & Helper ──────────────────────────────────────────
const formatRupiah = (amount: number) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(amount);

const formatDate = (dateString: string) => {
  return new Date(dateString).toLocaleDateString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit'
  }) + ' WIB';
};

import { useUserStore } from '@/src/stores/useUserStore';

// ─── Status Badge ──────────────────────────────────────────────
function StatusBadge({ status }: { status: string }) {
  switch (status) {
    case 'pending':
      return <span className="px-2.5 py-1 rounded-md bg-amber-500/15 text-amber-500 font-semibold text-[10px] uppercase tracking-wider border border-amber-500/30">Menunggu</span>;
    case 'approved':
      return <span className="px-2.5 py-1 rounded-md bg-emerald-500/15 text-emerald-400 font-semibold text-[10px] uppercase tracking-wider border border-emerald-500/30">Disetujui</span>;
    case 'rejected':
      return <span className="px-2.5 py-1 rounded-md bg-red-500/15 text-red-400 font-semibold text-[10px] uppercase tracking-wider border border-red-500/30">Ditolak</span>;
    default:
      return <span className="px-2.5 py-1 rounded-md bg-slate-700 text-slate-300 font-semibold text-[10px] uppercase tracking-wider">{status}</span>;
  }
}

// ─── Main Component ────────────────────────────────────────────
export default function AffiliateWithdrawPage() {
  const router = useRouter();
  const { user, isLoading: isStoreLoading } = useUserStore();
  
  // Data States
  const [balance, setBalance]             = useState<number>(0);
  const [profile, setProfile]             = useState<AffiliateProfile | null>(null);
  const [history, setHistory]             = useState<Withdrawal[]>([]);
  const [historyMeta, setHistoryMeta]     = useState({ current_page: 1, last_page: 1, total: 0 });
  const [isLoading, setIsLoading]         = useState(true);
  
  // Form States
  const [amountStr, setAmountStr]         = useState('');
  const [isSubmitting, setIsSubmitting]   = useState(false);

  // 1. Fetch requirements in parallel
  const loadInitialData = useCallback(async () => {
    setIsLoading(true);
    try {
      // Endpoint 1: Dashboard for balance
      const dashRes = await apiGet<AffiliateDashboardData>('/affiliate/dashboard');
      setBalance(dashRes.stats.balance);

      // Endpoint 2: Profile for bank details (assuming /api/affiliate/profile exists per routes/api.php)
      // Wait, there's `GET /api/affiliate/profile` in routes/api.php.
      const profRes = await apiGet<{ data: AffiliateProfile }>('/affiliate/profile');
      setProfile(profRes.data);

      // Endpoint 3: History
      const histRes = await apiGet<WithdrawalsResponse>('/affiliate/withdrawals?per_page=5');
      setHistory(histRes.data);
      setHistoryMeta({
        current_page: histRes.current_page,
        last_page: histRes.last_page,
        total: histRes.total
      });

    } catch (err: unknown) {
      toast.error('Gagal memuat data penarikan. Pastikan kamu adalah affiliate aktif.');
      router.push('/affiliate/dashboard');
    } finally {
      setIsLoading(false);
    }
  }, [router]);

  useEffect(() => {
    if (isStoreLoading) return;

    if (!user) {
      router.push('/login?redirect=/affiliate/withdraw');
      return;
    }
    loadInitialData();
  }, [loadInitialData, router, user, isStoreLoading]);

  // Handle Fetch More History
  const loadMoreHistory = async () => {
    if (historyMeta.current_page >= historyMeta.last_page) return;
    try {
      const nextPage = historyMeta.current_page + 1;
      const res = await apiGet<WithdrawalsResponse>(`/affiliate/withdrawals?per_page=5&page=${nextPage}`);
      setHistory(prev => [...prev, ...res.data]);
      setHistoryMeta({
        current_page: res.current_page,
        last_page: res.last_page,
        total: res.total
      });
    } catch {
      toast.error('Gagal memuat riwayat tambahan.');
    }
  };


  // 2. Submit Withdrawal
  const handleWithdraw = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!profile) return;

    // Remove non-numeric characters for parsing
    const rawAmount = amountStr.replace(/\D/g, '');
    const amountNum = parseInt(rawAmount || '0', 10);

    if (amountNum < 50000) {
      toast.warning('Nominal minimum penarikan adalah Rp 50.000');
      return;
    }
    if (amountNum > balance) {
      toast.warning('Nominal melebihi saldo komisi yang tersedia.');
      return;
    }

    setIsSubmitting(true);
    try {
      const payload = {
        amount: amountNum,
        bank_name: profile.bank_name,
        bank_account_number: profile.bank_account_number,
        bank_account_holder: profile.bank_account_holder,
      };

      await apiPost('/affiliate/withdraw', payload);
      
      toast.success('Permintaan pencairan berhasil diajukan!');
      
      // Reset form & reload data
      setAmountStr('');
      loadInitialData(); 
      
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Gagal mengajukan penarikan.';
      toast.error(msg);
    } finally {
      setIsSubmitting(false);
    }
  };

  // Helper for input nominal formatter (e.g. 50000 becomes 50.000)
  const handleAmountChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const raw = e.target.value.replace(/\D/g, '');
    if (!raw) {
      setAmountStr('');
      return;
    }
    const formatted = new Intl.NumberFormat('id-ID').format(parseInt(raw, 10));
    setAmountStr(formatted);
  };


  if (isLoading) {
    return (
      <main className="min-h-screen bg-slate-900 flex items-center justify-center">
        <div className="flex items-center gap-3 text-amber-500">
          <svg className="animate-spin h-6 w-6" viewBox="0 0 24 24" fill="none">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          <span className="font-semibold">Memuat Data Penarikan...</span>
        </div>
      </main>
    );
  }

  const hasPendingWithdrawal = history.some(w => w.status === 'pending');

  return (
    <main className="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 p-4 py-8">
      <div className="max-w-4xl mx-auto space-y-6">

        {/* ── Header ────────────────────────────────────────── */}
        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4 border-b border-slate-700/50 pb-6">
          <div>
            <h1 className="text-white font-bold text-2xl flex items-center gap-3">
              <span className="w-10 h-10 rounded-lg bg-amber-500/15 flex items-center justify-center text-xl border border-amber-500/30">
                💸
              </span>
              Tarik Komisi
            </h1>
            <p className="text-slate-400 text-sm mt-2">
              Cairkan saldo komisi ke rekening tabungan atau e-wallet kamu.
            </p>
          </div>
          <Button onClick={() => router.push('/affiliate/dashboard')} variant="outline" className="border-slate-600 text-slate-300 hover:text-white hover:bg-slate-700">
            Kembali ke Dashboard
          </Button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">

          {/* ── Kiri: Saldo & Form Withdraw ────────────────────── */}
          <div className="space-y-6">
            
            {/* Saldo Warning if pending exists */}
            {hasPendingWithdrawal && (
              <div className="flex gap-3 p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/30">
                <span className="shrink-0 text-xl">⏳</span>
                <p className="text-amber-400 text-xs leading-relaxed font-medium">
                  Ada pencairan dana yang sedang menunggu persetujuan admin. Kamu tidak bisa melakukan penarikan baru hingga dana tersebut diproses.
                </p>
              </div>
            )}

            {/* Form Card */}
            <Card className="bg-slate-800/50 border-slate-700 shadow-xl overflow-hidden">
              <div className="h-1 bg-amber-500 w-full" />
              <CardHeader className="pb-2">
                <CardTitle className="text-slate-300 text-sm font-medium">Saldo Tersedia</CardTitle>
                <div className="text-3xl font-bold text-amber-500 mt-1">
                  {formatRupiah(balance)}
                </div>
              </CardHeader>
              <CardContent>
                <form onSubmit={handleWithdraw} className="mt-4 space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="amount" className="text-slate-300">Nominal Penarikan</Label>
                    <div className="relative">
                      <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <span className="text-slate-500 font-medium">Rp</span>
                      </div>
                      <Input
                        id="amount"
                        type="text"
                        value={amountStr}
                        onChange={handleAmountChange}
                        placeholder="Minimal 50.000"
                        className="pl-10 bg-slate-900/50 border-slate-600 text-white placeholder:text-slate-600 focus:border-amber-500 font-semibold text-lg py-6"
                        disabled={isSubmitting || hasPendingWithdrawal || balance < 50000}
                        required
                      />
                    </div>
                    {/* Helper shortcut */}
                    {balance >= 50000 && !hasPendingWithdrawal && (
                      <div className="flex justify-end pt-1">
                        <button 
                          type="button" 
                          onClick={() => setAmountStr(new Intl.NumberFormat('id-ID').format(balance))}
                          className="text-amber-500 hover:text-amber-400 text-xs font-semibold underline-offset-4 hover:underline transition-all"
                        >
                          Tarik Semua
                        </button>
                      </div>
                    )}
                  </div>

                  <Button
                    type="submit"
                    disabled={isSubmitting || hasPendingWithdrawal || balance < 50000 || !amountStr}
                    className={`w-full py-6 font-bold text-base transition-all ${
                       hasPendingWithdrawal || balance < 50000 || !amountStr
                        ? 'bg-slate-700 text-slate-500 cursor-not-allowed border border-slate-600 hover:bg-slate-700'
                        : 'bg-amber-500 hover:bg-amber-400 text-slate-900 shadow-lg shadow-amber-500/20'
                    }`}
                  >
                    {isSubmitting ? 'Memproses...' : 'Ajukan Penarikan'}
                  </Button>
                </form>
              </CardContent>
            </Card>
          </div>

          {/* ── Kanan: Info Bank & Riwayat ────────────────────── */}
          <div className="space-y-6">
            
            {/* Info Rekening Tujuan */}
            <Card className="bg-slate-800/30 border-slate-700 border-dashed">
              <CardHeader className="pb-2">
                <CardTitle className="text-slate-300 text-sm font-medium flex items-center gap-2">
                  <span className="text-blue-400">🏦</span> Rekening Pencairan
                </CardTitle>
              </CardHeader>
              <CardContent>
                {profile ? (
                  <div className="bg-slate-900/40 rounded-lg p-4 border border-slate-700/50">
                    <p className="text-white font-semibold text-lg">{profile.bank_name}</p>
                    <p className="text-slate-300 font-mono tracking-widest text-lg mt-1 mb-2">
                      {profile.bank_account_number}
                    </p>
                    <p className="text-slate-500 text-xs uppercase tracking-wide">
                      A.N. <span className="text-slate-300 font-medium">{profile.bank_account_holder}</span>
                    </p>
                  </div>
                ) : (
                  <div className="text-slate-500 text-sm italic py-4 text-center">Data rekening tidak ditemukan.</div>
                )}
                <p className="text-[11px] text-slate-500 mt-3 italic leading-relaxed">
                  *Dana hanya akan ditransfer ke rekening di atas. Pastikan data sudah benar. Jika ingin mengganti rekening, hubungi Admin TDR HPZ.
                </p>
              </CardContent>
            </Card>

            {/* Riwayat Penarikan */}
            <Card className="bg-slate-800/50 border-slate-700">
              <CardHeader className="pb-3 border-b border-slate-700/50">
                <CardTitle className="text-white text-base">Riwayat Penarikan</CardTitle>
              </CardHeader>
              <CardContent className="p-0">
                {history.length > 0 ? (
                  <div className="divide-y divide-slate-700/50">
                    {history.map((wd) => (
                      <div key={wd.id} className="p-4 flex items-center justify-between hover:bg-slate-800/80 transition-colors">
                        <div>
                          <p className="text-white font-bold">{formatRupiah(wd.amount)}</p>
                          <p className="text-slate-500 text-xs mt-1">{formatDate(wd.created_at)}</p>
                        </div>
                        <div className="text-right">
                          <StatusBadge status={wd.status} />
                          <p className="text-slate-400 text-[10px] mt-1.5 truncate max-w-[120px]">
                            {wd.bank_name} - {wd.bank_account_number.slice(-4).padStart(wd.bank_account_number.length, '*')}
                          </p>
                        </div>
                      </div>
                    ))}
                    {historyMeta.current_page < historyMeta.last_page && (
                      <div className="p-3 text-center">
                        <button 
                          onClick={loadMoreHistory}
                          className="text-amber-500 hover:text-amber-400 text-xs font-semibold transition-colors"
                        >
                          Muat lebih banyak...
                        </button>
                      </div>
                    )}
                  </div>
                ) : (
                  <div className="p-8 text-center text-slate-500">
                    <p className="text-3xl mb-2">📄</p>
                    <p className="text-sm">Belum ada riwayat penarikan.</p>
                  </div>
                )}
              </CardContent>
            </Card>

          </div>
        </div>
      </div>
    </main>
  );
}
