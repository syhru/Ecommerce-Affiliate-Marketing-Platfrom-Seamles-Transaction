'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiPost } from '@/src/lib/api';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

// ─── Types ────────────────────────────────────────────────────
interface AffiliateRegisterPayload {
  bank_name: string;
  bank_account_number: string;
  bank_account_holder: string;
}
interface AffiliateRegisterResponse {
  message: string;
  profile: {
    referral_code: string;
    commission_rate: number;
    status: string;
  };
}

// ─── Bank / E-Wallet options ──────────────────────────────────
const PAYMENT_OPTIONS = {
  'E-Wallet': ['OVO', 'GoPay', 'DANA', 'ShopeePay'],
  'Transfer Bank': ['BCA', 'BRI', 'BNI', 'Mandiri'],
  'Lainnya': ['Lainnya'],
} as const;

const isEwallet = (v: string) => ['OVO', 'GoPay', 'DANA', 'ShopeePay'].includes(v);

import { useUserStore } from '@/src/stores/useUserStore';

// ─── Token / User helpers ─────────────────────────────────────
function getToken(): string | null {
  if (typeof document === 'undefined') return null;
  const m = document.cookie.match(/(?:^|;\s*)auth_token=([^;]*)/);
  return m ? decodeURIComponent(m[1]) : null;
}

// ─── Step indicator ────────────────────────────────────────────
const HOW_IT_WORKS = [
  { step: 1, color: 'bg-red-50 text-red-500 border border-red-200',    title: 'Daftar & Verifikasi', desc: 'Isi data rekening, tunggu approval admin (maks. 1×24 jam)' },
  { step: 2, color: 'bg-blue-50 text-blue-500 border border-blue-200',  title: 'Bagikan Link Unik',   desc: 'Sebar link referral ke media sosial, grup wa, tiktok, dll.' },
  { step: 3, color: 'bg-emerald-50 text-emerald-500 border border-emerald-200', title: 'Dapat Komisi', desc: '10% dari setiap transaksi, langsung masuk saldo + notif Telegram' },
  { step: 4, color: 'bg-amber-50 text-amber-500 border border-amber-200', title: 'Cairkan Komisi',  desc: 'Request pencairan ke rekening yang sudah didaftarkan' },
];

// ─── Main Component ────────────────────────────────────────────
export default function AffiliateRegisterPage() {
  const router = useRouter();
  const { user, fetchUser, isLoading: isStoreLoading } = useUserStore();
  const [bankName, setBankName]         = useState('');
  const [accountNumber, setAccountNumber] = useState('');
  const [accountHolder, setAccountHolder] = useState('');
  const [isLoading, setIsLoading] = useState(false);

  // Guard: must be logged in & status check
  useEffect(() => {
    if (isStoreLoading) return;

    if (!user || !getToken()) {
      toast.info('Kamu perlu login dulu untuk mendaftar affiliate.');
      router.push('/login?redirect=/affiliate/register');
      return;
    }

    if (user.role === 'affiliate' && user.affiliate_profile?.status === 'pending') {
      router.replace('/affiliate/pending');
      return;
    }

    if (user.role === 'affiliate' && user.affiliate_profile?.status === 'active') {
      router.replace('/affiliate/dashboard');
      return;
    }

    // Pre-fill account holder dengan nama user
    setAccountHolder(user.name);
  }, [router, user, isStoreLoading]);

  const accountLabel = isEwallet(bankName) ? 'Nomor HP / Akun E-Wallet' : 'Nomor Rekening';

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    // Client-side validation
    if (!bankName)         { toast.error('Pilih bank atau e-wallet terlebih dahulu.'); return; }
    if (!accountNumber.trim()) { toast.error(`${accountLabel} wajib diisi.`); return; }
    if (!accountHolder.trim()) { toast.error('Nama pemilik akun wajib diisi.'); return; }

    const payload: AffiliateRegisterPayload = {
      bank_name:           bankName,
      bank_account_number: accountNumber.trim(),
      bank_account_holder: accountHolder.trim(),
    };

    setIsLoading(true);
    try {
      const res = await apiPost<AffiliateRegisterResponse>('/affiliate/register', payload);

      toast.success('Pendaftaran Berhasil! Selamat datang di program Affiliate.');

      // Fetch profil lengkap dari server (termasuk affiliate_profile yang baru dibuat)
      await fetchUser();

      if (res.profile?.status === 'pending') {
        router.replace('/affiliate/pending');
      } else {
        router.replace('/affiliate/dashboard');
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Terjadi kesalahan.';
      if (msg.includes('422') || msg.toLowerCase().includes('sudah terdaftar')) {
        toast.warning('Kamu sudah terdaftar sebagai affiliate. Tunggu persetujuan admin.');
        router.push('/affiliate/pending');
      } else {
        toast.error(msg);
      }
    } finally {
      setIsLoading(false);
    }
  };

  if (!user) return null; // loading / redirect in progress

  return (
    <main className="min-h-screen bg-[#f8f9fa] flex flex-col pt-16">
      <Navbar />

      <div className="flex-1 w-full max-w-xl mx-auto py-10 px-4">

        {/* ── Hero ──────────────────────────────────────── */}
        <div className="text-center mb-10">
          <div className="inline-flex items-center justify-center w-20 h-20 rounded-2xl bg-amber-100 border-2 border-amber-200 mb-5 shadow-sm transform transition-transform hover:scale-105">
            <span className="text-4xl shadow-amber-500 drop-shadow-sm">💰</span>
          </div>
          <h1 className="text-slate-900 font-extrabold text-3xl">Daftar Program Affiliate</h1>
          <p className="text-slate-600 font-medium text-sm mt-3 px-4">
            Dapatkan komisi <span className="text-amber-500 font-extrabold">10%</span> dari setiap pembelian via link referral kamu
          </p>
        </div>

        {/* ── User Info Card ─────────────────────────────── */}
        <div className="flex items-center gap-4 p-5 rounded-2xl bg-white border border-slate-200 shadow-sm mb-7">
          <div className="w-12 h-12 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-800 font-black text-lg shrink-0">
            {user.name.charAt(0).toUpperCase()}
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-slate-900 font-bold text-sm truncate">{user.name}</p>
            <p className="text-slate-500 font-medium text-xs truncate mt-0.5">{user.email}</p>
          </div>
          {/* Telegram status */}
          {user.telegramChatId ? (
            <span className="text-[11px] px-3 py-1.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-600 font-bold shrink-0">
              ✅ Terhubung
            </span>
          ) : (
            <span className="text-[11px] px-3 py-1.5 rounded-full bg-amber-50 border border-amber-200 text-amber-600 font-bold shrink-0">
              ⚠️ No Telegram
            </span>
          )}
        </div>

        {/* ── Telegram Warning ──────────────────────────── */}
        {!user.telegramChatId && (
          <div className="flex gap-4 p-5 rounded-2xl bg-amber-50 border border-amber-200 mb-7 shadow-inner">
            <span className="text-2xl shrink-0 mt-0.5">🔔</span>
            <div>
              <p className="text-amber-700 font-extrabold text-sm mb-1">Sambungkan Telegram kamu</p>
              <p className="text-amber-800/80 font-medium text-xs leading-relaxed">
                Agar notifikasi komisi real-time berjalan, sambungkan bot Telegram melalui <span className="text-amber-600 font-bold">Pengaturan Profil</span> setelah mendaftar.
              </p>
            </div>
          </div>
        )}

        {/* ── Registration Form ─────────────────────────── */}
        <Card className="bg-white border-slate-200 shadow-lg shadow-slate-200/50 rounded-2xl mb-8 overflow-hidden">
          <CardHeader className="pb-5 border-b border-slate-100">
            <CardTitle className="text-slate-900 font-extrabold text-lg flex items-center gap-2">
               <span className="text-amber-500">🏦</span> Data Pencairan Komisi
            </CardTitle>
            <CardDescription className="text-slate-500 font-medium text-sm mt-1">
              Komisi akan dikreditkan ke rekening / e-wallet ini.
            </CardDescription>
          </CardHeader>
          <CardContent className="pt-6">
            <form onSubmit={handleSubmit} className="space-y-6">

              {/* Bank / E-Wallet select */}
              <div className="space-y-2">
                <Label htmlFor="bankName" className="text-slate-700 font-bold text-xs uppercase tracking-wider">
                  Nama Bank / E-Wallet <span className="text-red-500">*</span>
                </Label>
                <select
                  title='bankName'
                  id="bankName"
                  value={bankName}
                  onChange={(e) => setBankName(e.target.value)}
                  required
                  className="w-full rounded-xl border border-slate-200 bg-slate-50 text-slate-900 px-4 py-3 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-shadow disabled:opacity-50"
                >
                  <option value="">-- Pilih metode pencairan --</option>
                  {Object.entries(PAYMENT_OPTIONS).map(([group, options]) => (
                    <optgroup key={group} label={group}>
                      {options.map((opt) => (
                        <option key={opt} value={opt}>{opt}</option>
                      ))}
                    </optgroup>
                  ))}
                </select>
              </div>

              {/* Account Number */}
              <div className="space-y-2">
                <Label htmlFor="accountNumber" className="text-slate-700 font-bold text-xs uppercase tracking-wider">
                  {accountLabel} <span className="text-red-500">*</span>
                </Label>
                <Input
                  id="accountNumber"
                  value={accountNumber}
                  onChange={(e) => setAccountNumber(e.target.value)}
                  placeholder={isEwallet(bankName) ? 'Contoh: 08123456789' : 'Contoh: 1234567890'}
                  required
                  className="bg-slate-50 border-slate-200 text-slate-900 placeholder:text-slate-400 font-medium py-5 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                />
              </div>

              {/* Account Holder */}
              <div className="space-y-2">
                <Label htmlFor="accountHolder" className="text-slate-700 font-bold text-xs uppercase tracking-wider">
                  Nama Pemilik Akun <span className="text-red-500">*</span>
                </Label>
                <Input
                  id="accountHolder"
                  value={accountHolder}
                  onChange={(e) => setAccountHolder(e.target.value)}
                  placeholder="Sesuai nama di buku tabungan / e-wallet"
                  required
                  className="bg-slate-50 border-slate-200 text-slate-900 placeholder:text-slate-400 font-medium py-5 px-4 focus:ring-2 focus:ring-amber-500 focus:border-transparent rounded-xl"
                />
                <p className="text-slate-500 font-medium text-[11px] flex items-center gap-1.5 mt-2">
                  <span>ℹ️</span> Pastikan identitas cocok agar pencairan lancar!
                </p>
              </div>

              {/* Admin review notice */}
              <div className="flex gap-4 p-4 rounded-xl bg-blue-50 border border-blue-200 shadow-inner mt-4">
                <span className="shrink-0 text-lg mt-0.5">🛡️</span>
                <p className="text-blue-900/80 font-medium text-xs leading-relaxed">
                  Pendaftaran akan <strong className="text-blue-900">ditinjau oleh admin</strong> dalam 1×24 jam.
                  Kamu akan mendapat notifikasi via Telegram setelah diapprove.
                </p>
              </div>

              {/* Submit */}
              <div className="flex flex-col gap-3 pt-4">
                <Button
                  type="submit"
                  disabled={isLoading}
                  className="w-full bg-slate-900 hover:bg-slate-800 text-white shadow-xl shadow-slate-900/10 font-bold py-6 rounded-xl transition-all"
                >
                  {isLoading ? (
                    <span className="flex items-center justify-center gap-2">
                      <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24" fill="none">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                      </svg>
                      Memproses Pendaftaran...
                    </span>
                  ) : '👥 Submit Pendaftaran'}
                </Button>
                <Button
                  type="button"
                  onClick={() => router.push('/shop')}
                  className="w-full bg-white border-2 border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 font-bold py-6 rounded-xl transition-all"
                >
                  Kembali Belanja
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>

        {/* ── How It Works ──────────────────────────────── */}
        <Card className="bg-white border-slate-200 shadow-sm rounded-2xl mb-6">
          <CardHeader className="pb-4 border-b border-slate-100">
            <CardTitle className="text-slate-900 font-bold text-sm uppercase tracking-wider">Cara Kerja Affiliate TDR</CardTitle>
          </CardHeader>
          <CardContent className="space-y-5 pt-5">
            {HOW_IT_WORKS.map(({ step, color, title, desc }) => (
              <div key={step} className="flex items-start gap-4">
                <div className={`w-9 h-9 rounded-full shrink-0 flex items-center justify-center text-sm font-black shadow-sm ${color}`}>
                  {step}
                </div>
                <div>
                  <p className="text-slate-900 text-sm font-bold mb-1">{title}</p>
                  <p className="text-slate-500 font-medium text-xs leading-relaxed">{desc}</p>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>

        <p className="text-center text-slate-400 font-medium text-xs mt-8 mb-4">
          © {new Date().getFullYear()} TDR HPZ. Program affiliate subject to terms &amp; conditions.
        </p>

      </div>
      <Footer />
    </main>
  );
}
