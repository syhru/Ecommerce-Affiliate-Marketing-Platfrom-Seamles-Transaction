'use client';

import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiPost } from '@/src/lib/api';
import { ArrowLeft, KeyRound } from 'lucide-react';
import Link from 'next/link';
import { useState } from 'react';
import { toast } from 'sonner';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isSuccess, setIsSuccess] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email) {
      toast.warning('Harap masukkan alamat email.');
      return;
    }

    setIsSubmitting(true);
    try {
      const res = await apiPost<{ message: string }>('/forgot-password', { email });
      toast.success(res.message || 'Tautan reset telah dikirim ke email kamu.');
      setIsSuccess(true);
    } catch (err: any) {
      const msg = err.message || 'Gagal mengirim tautan reset. Pastikan email terdaftar.';
      toast.error(msg);
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <main className="min-h-screen bg-[#f8f9fa] font-sans pt-16 flex flex-col items-center justify-center p-4">
      <Navbar />

      <div className="w-full max-w-md animate-in fade-in slide-in-from-bottom-8 duration-700">
        
        <div className="flex justify-center mb-6">
          <Link href="/login" className="inline-flex items-center text-sm font-semibold text-slate-500 hover:text-slate-900 transition-colors">
            <ArrowLeft className="w-4 h-4 mr-2" />
            Kembali ke Halaman Masuk
          </Link>
        </div>

        <Card className="bg-white border-slate-200 shadow-xl shadow-slate-200/50 rounded-3xl overflow-hidden relative">
          {/* Aksen atas */}
          <div className="absolute top-0 inset-x-0 h-1.5 bg-gradient-to-r from-amber-400 to-amber-500" />
          
          <CardHeader className="text-center pt-10 pb-4">
            <div className="mx-auto w-14 h-14 rounded-full bg-amber-100 flex items-center justify-center mb-5 border border-amber-200 shadow-sm">
              <KeyRound className="w-7 h-7 text-amber-600" />
            </div>
            <CardTitle className="text-2xl font-extrabold text-slate-900">
              Lupa Password?
            </CardTitle>
            <CardDescription className="text-slate-500 font-medium px-4 mt-2">
              Jangan khawatir! Masukkan email yang terhubung dengan akun kamu dan kami akan mengirimkan tautan pemulihan.
            </CardDescription>
          </CardHeader>

          <CardContent className="p-8 pt-4">
            {isSuccess ? (
              <div className="bg-emerald-50 border border-emerald-200 rounded-2xl p-6 text-center space-y-4 shadow-sm animate-in zoom-in-95 duration-500">
                <div className="mx-auto w-12 h-12 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center text-xl">
                  📨
                </div>
                <div>
                  <h3 className="text-emerald-800 font-bold mb-1">Cek Kotak Masuk Kamu!</h3>
                  <p className="text-xs font-medium text-emerald-700/80 leading-relaxed">
                    Kami telah mengirimkan instruksi untuk me-reset password ke <strong>{email}</strong>. Periksa juga folder Spam/Junk jika perlu.
                  </p>
                </div>
                <Button 
                  onClick={() => setIsSuccess(false)}
                  variant="outline" 
                  className="w-full bg-white border-emerald-200 text-emerald-700 font-bold rounded-xl mt-4"
                >
                  Kirim Ulang Email
                </Button>
              </div>
            ) : (
              <form onSubmit={handleSubmit} className="space-y-6">
                <div className="space-y-2">
                  <Label htmlFor="email" className="text-slate-700 font-bold text-xs uppercase tracking-wider ml-1">
                    Alamat Email
                  </Label>
                  <Input
                    id="email"
                    type="email"
                    placeholder="nama@email.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    required
                    disabled={isSubmitting}
                    className="bg-slate-50 border-slate-200 text-slate-900 font-medium h-14 px-5 rounded-2xl focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all"
                  />
                </div>

                <Button
                  type="submit"
                  disabled={isSubmitting}
                  className="w-full bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold h-14 text-base rounded-2xl shadow-lg shadow-amber-500/30 transition-all active:scale-[0.98]"
                >
                  {isSubmitting ? 'Memproses...' : 'Kirim Tautan Reset'}
                </Button>
              </form>
            )}
          </CardContent>
        </Card>
        
        <p className="text-center text-slate-500 font-medium text-sm mt-8">
          Belum punya akun?{' '}
          <Link href="/register" className="text-amber-600 font-bold hover:underline underline-offset-4">
            Daftar Sekarang
          </Link>
        </p>

      </div>
    </main>
  );
}
