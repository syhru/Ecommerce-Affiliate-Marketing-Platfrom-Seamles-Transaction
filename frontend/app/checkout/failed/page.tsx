'use client';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import Head from 'next/head';
import { useRouter } from 'next/navigation';
import { Suspense } from 'react';

function FailedContent() {
  const router = useRouter();

  return (
    <Card className="bg-slate-800/60 border-slate-700 shadow-2xl relative overflow-hidden animate-in zoom-in-95 duration-500 max-w-lg w-full">
      <div className="absolute top-0 left-1/2 -translate-x-1/2 w-64 h-64 bg-red-500/10 rounded-full blur-3xl -mt-20 pointer-events-none" />
      
      <CardContent className="p-8 relative z-10 text-center flex flex-col items-center">
        <div className="w-24 h-24 rounded-full bg-red-500/10 border-4 border-red-500/30 flex items-center justify-center mb-6 shadow-[0_0_40px_rgba(239,68,68,0.2)]">
          <span className="text-5xl">❌</span>
        </div>
        
        <h2 className="text-white font-bold text-2xl mb-2">Pembayaran Gagal</h2>
        <p className="text-slate-400 text-sm leading-relaxed mb-6">
          Maaf, terjadi kesalahan saat memproses pembayaranmu atau kamu membatalkan transaksi.
          Silakan coba lakukan pemesanan kembali.
        </p>

        <div className="flex flex-col w-full gap-3">
          <Button 
            onClick={() => router.push('/cart')} 
            className="w-full bg-red-500 hover:bg-red-400 text-white font-bold py-6 text-base shadow-lg shadow-red-500/20 border-none"
          >
            Coba Bayar Lagi
          </Button>
          <Button 
            onClick={() => router.push('/orders')} 
            variant="outline"
            className="w-full border-slate-600 text-slate-300 hover:text-white hover:bg-slate-700"
          >
            Lihat Histori Pesanan
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

export default function CheckoutFailedPage() {
  return (
    <main className="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 px-4 py-12 flex items-center justify-center">
      <Head>
        <title>Pembayaran Gagal - TDR HPZ</title>
      </Head>
      <Suspense fallback={
        <div className="flex items-center gap-3 text-red-500 animate-pulse">
          <span className="text-2xl">⚠️</span>
          <span className="font-semibold text-lg">Memverifikasi Status...</span>
        </div>
      }>
        <FailedContent />
      </Suspense>
    </main>
  );
}
