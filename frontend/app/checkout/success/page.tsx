'use client';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import Head from 'next/head';
import { useRouter, useSearchParams } from 'next/navigation';
import { Suspense } from 'react';

function SuccessContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const orderNumber = searchParams.get('order_number') || searchParams.get('order_id');

  return (
    <Card className="bg-slate-800/60 border-slate-700 shadow-2xl relative overflow-hidden animate-in zoom-in-95 duration-500 max-w-lg w-full">
      <div className="absolute top-0 left-1/2 -translate-x-1/2 w-64 h-64 bg-emerald-500/10 rounded-full blur-3xl -mt-20 pointer-events-none" />
      
      <CardContent className="p-8 relative z-10 text-center flex flex-col items-center">
        <div className="w-24 h-24 rounded-full bg-emerald-500/20 border-4 border-emerald-500/40 flex items-center justify-center mb-6 shadow-[0_0_40px_rgba(16,185,129,0.3)] animate-pulse">
          <span className="text-5xl">🎉</span>
        </div>
        
        <h2 className="text-white font-bold text-2xl mb-2">Yeay! Pesanan Berhasil Dibuat</h2>
        <p className="text-slate-400 text-sm leading-relaxed mb-6">
          Terima kasih telah berbelanja suku cadang motor di TDR HPZ. 
          Pesananmu akan segera kami proses dan kirimkan ke alamat tujuan.
        </p>

        {orderNumber && (
          <div className="bg-slate-900/50 border border-slate-700 w-full p-4 rounded-xl mb-6 flex flex-col items-center">
            <span className="text-slate-500 text-xs uppercase tracking-wider mb-1 font-semibold">Nomor Pesanan</span>
            <span className="text-amber-500 font-mono text-xl font-bold tracking-widest">{orderNumber}</span>
          </div>
        )}

        <div className="flex flex-col w-full gap-3">
          <Button 
            onClick={() => router.push('/orders')} 
            className="w-full bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold py-6 text-base"
          >
            Lacak Pesanan Saya
          </Button>
          <Button 
            onClick={() => router.push('/shop')} 
            variant="outline"
            className="w-full border-slate-600 text-slate-300 hover:text-white hover:bg-slate-700"
          >
            Lanjut Belanja
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

export default function CheckoutSuccessPage() {
  return (
    <main className="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 px-4 py-12 flex items-center justify-center">
      <Head>
        <title>Pesanan Berhasil - TDR HPZ</title>
      </Head>
      <Suspense fallback={
        <div className="flex flex-col items-center gap-4 text-emerald-500">
          <svg className="animate-spin h-8 w-8" viewBox="0 0 24 24" fill="none">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          <span className="font-semibold text-lg">Memverifikasi Pembayaran...</span>
        </div>
      }>
        <SuccessContent />
      </Suspense>
    </main>
  );
}
