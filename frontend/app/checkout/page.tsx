'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { apiPost } from '@/src/lib/api';
import { useUserStore } from '@/src/stores/useUserStore';
import { useRouter } from 'next/navigation';
import Script from 'next/script';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import type { CartItem } from '../cart/page';

// ─── Constants ────────────────────────────────────────────────
const COURIERS = [
  { id: 'jne_reg', label: 'JNE Reguler (2–3 hari)', cost: 15000 },
  { id: 'jne_yes', label: 'JNE YES (1 hari)', cost: 25000 },
  { id: 'jnt_reg', label: 'J&T Reguler (2–3 hari)', cost: 13000 },
  { id: 'sicepat', label: 'SiCepat Halu (1–2 hari)', cost: 14000 },
  { id: 'pos_biasa', label: 'Pos Indonesia Biasa', cost: 10000 },
];

const formatRupiah = (amount: number) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(amount);

// ─── Form State Type ──────────────────────────────────────────
interface CheckoutForm {
  customer_name: string;
  customer_phone: string;
  customer_email: string;
  shipping_address: string;
  shipping_city: string;
  shipping_province: string;
  shipping_postal_code: string;
  shipping_courier: string;
  notes: string;
}

// ─── Component ────────────────────────────────────────────────
export default function CheckoutPage() {
  const router = useRouter();
  const { user, isLoading: isStoreLoading } = useUserStore();
  const [cart, setCart] = useState<CartItem[]>([]);
  const [isMounted, setIsMounted] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const [form, setForm] = useState<CheckoutForm>({
    customer_name: '',
    customer_phone: '',
    customer_email: '',
    shipping_address: '',
    shipping_city: '',
    shipping_province: '',
    shipping_postal_code: '',
    shipping_courier: 'jne_reg',
    notes: '',
  });

  useEffect(() => {
    if (isStoreLoading) return;
    if (!user) {
      router.push('/login?redirect=/checkout');
      return;
    }

    if (!isMounted) {
      // Initial prefill
      setForm(prev => ({
        ...prev,
        customer_name: user?.name || '',
        customer_email: user?.email || '',
      }));

    // Load Cart
    try {
      const urlParams = new URLSearchParams(window.location.search);
      const isBuyNow = urlParams.get('buy_now') === 'true';
      const storageKey = isBuyNow ? 'tdr_buy_now' : 'tdr_cart';
      
      const localCart = JSON.parse(localStorage.getItem(storageKey) || '[]') as CartItem[];
      if (localCart.length === 0) {
        toast.info(isBuyNow ? 'Terjadi kesalahan sistem, silakan ulangi Beli Sekarang.' : 'Keranjang kosong, silakan belanja dulu.');
        router.push('/shop');
        return;
      }
      setCart(localCart);
    } catch {
      router.push('/shop');
    }

      setIsMounted(true);
    }
  }, [router, user, isStoreLoading, isMounted]);

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    setForm({ ...form, [e.target.name]: e.target.value });
  };

  const handleCourierSelect = (courierId: string) => {
    setForm({ ...form, shipping_courier: courierId });
  };

  const processPayment = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);

    try {
      // 1. Build Payload for POST /api/orders
      const combinedAddress = [
        form.customer_name,
        form.customer_phone,
        form.shipping_address,
        form.shipping_city,
        form.shipping_province,
        form.shipping_postal_code
      ].filter(Boolean).join(', ');

      const reqPayload = {
        shipping_courier: form.shipping_courier,
        shipping_address: combinedAddress,
        notes: form.notes,
        payment_method: 'midtrans',
        items: cart.map(item => ({
          product_id: item.product_id,
          quantity: item.quantity,
          affiliate_code: item.affiliate_code || null
        }))
      };

      // 2. Call API
      const res = await apiPost<{ message: string, payment_url: string, order: { order_number: string } }>('/orders', reqPayload);
      const snapTokenOrUrl = res.payment_url;
      const orderNumber = res.order?.order_number;

      // Ensure snap is available
      if (typeof window !== 'undefined' && (window as any).snap) {
        // Clear locally first since order is successfully created
        const urlParams = new URLSearchParams(window.location.search);
        const isBuyNow = urlParams.get('buy_now') === 'true';
        if (isBuyNow) {
          localStorage.removeItem('tdr_buy_now');
        } else {
          localStorage.removeItem('tdr_cart');
          window.dispatchEvent(new Event('cart_updated'));
        }

        // Midtrans Snap Popup Call
        if (snapTokenOrUrl.startsWith('http')) {
          window.location.href = snapTokenOrUrl;
        } else {
          (window as any).snap.pay(snapTokenOrUrl, {
            onSuccess: function() {
              router.push(`/checkout/success?order_number=${orderNumber}`);
            },
            onPending: function() {
              router.push(`/checkout/success?order_number=${orderNumber}`);
            },
            onError: function() {
              router.push('/checkout/failed');
            },
            onClose: function () {
              toast.error('Kamu menutup popup sebelum menyelesaikan pembayaran.');
              router.push(`/checkout/success?order_number=${orderNumber}`); 
            }
          });
        }
      } else {
        // Fallback SDK not loaded
        if (snapTokenOrUrl.startsWith('http')) {
          const urlParams = new URLSearchParams(window.location.search);
          const isBuyNow = urlParams.get('buy_now') === 'true';
          if (isBuyNow) {
            localStorage.removeItem('tdr_buy_now');
          } else {
            localStorage.removeItem('tdr_cart');
            window.dispatchEvent(new Event('cart_updated'));
          }
          window.location.href = snapTokenOrUrl;
        } else {
          toast.error('Gateway pembayaran sedang gangguan. Coba lagi.');
        }
      }

    } catch (err: any) {
      toast.error(err.message || 'Gagal membuat pesanan. Periksa data kembali.');
    } finally {
      setIsSubmitting(false);
    }
  };

  if (!isMounted) return null;

  // Calculations
  const cartTotal = cart.reduce((acc, item) => acc + (item.product_price * item.quantity), 0);
  const selectedCourierObj = COURIERS.find(c => c.id === form.shipping_courier);
  const shippingCost = selectedCourierObj?.cost || 0;
  const grandTotal = cartTotal + shippingCost;

  return (
    <main className="min-h-screen bg-[#f8f9fa] flex flex-col pt-16">
      <Navbar />

      {/* Midtrans Snap SDK */}
      <Script 
        src="https://app.sandbox.midtrans.com/snap/snap.js" 
        data-client-key={process.env.NEXT_PUBLIC_MIDTRANS_CLIENT_KEY || 'SB-Mid-client-XXXX'} 
        strategy="lazyOnload"
      />

      <div className="flex-1 w-full max-w-6xl mx-auto space-y-6 px-4 py-8 md:py-12">
        
        <div className="border-b border-slate-200 pb-5">
          <h1 className="text-slate-900 font-extrabold text-2xl flex items-center gap-3">
            <span className="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center text-xl border border-amber-200 shadow-sm">
              💳
            </span>
            Form Checkout
          </h1>
          <p className="text-slate-500 font-medium text-sm mt-3 ml-[52px]">
            Lengkapi data pengiriman kamu di bawah ini untuk proses pemesanan.
          </p>
        </div>

        <form onSubmit={processPayment} className="grid grid-cols-1 lg:grid-cols-12 gap-8">
          
          {/* ── Kiri: Form Pengiriman ──────────────────────────── */}
          <div className="lg:col-span-7 space-y-6">
            
            {/* Alamat Pengiriman */}
            <Card className="bg-white border-slate-200 shadow-sm rounded-2xl overflow-hidden">
              <CardContent className="p-7">
                <h3 className="text-slate-900 font-extrabold text-lg mb-6 flex items-center gap-2">
                  <span className="text-amber-500 text-xl">📍</span> Alamat Pengiriman
                </h3>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                  <div className="space-y-1.5">
                    <Label className="text-slate-700 font-semibold text-xs uppercase tracking-wider">Nama Penerima</Label>
                    <Input name="customer_name" value={form.customer_name} onChange={handleInputChange} required className="bg-slate-50 border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-900 rounded-xl" />
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-slate-700 font-semibold text-xs uppercase tracking-wider">Nomor HP</Label>
                    <Input name="customer_phone" value={form.customer_phone} onChange={handleInputChange} required className="bg-slate-50 border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-900 rounded-xl" />
                  </div>
                  <div className="sm:col-span-2 space-y-1.5">
                    <Label className="text-slate-700 font-semibold text-xs uppercase tracking-wider">Email Utama</Label>
                    <Input type="email" name="customer_email" value={form.customer_email} onChange={handleInputChange} required className="bg-slate-50 border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-900 rounded-xl" />
                  </div>
                  <div className="sm:col-span-2 space-y-1.5 mt-2">
                    <Label className="text-slate-700 font-semibold text-xs uppercase tracking-wider">Alamat Lengkap (Jalan, No, RT/RW)</Label>
                    <Textarea name="shipping_address" value={form.shipping_address} onChange={handleInputChange} required className="bg-slate-50 border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-900 rounded-xl resize-none shadow-inner" rows={3} placeholder="Mulai ketikkan alamat jalan..." />
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-slate-700 font-semibold text-xs uppercase tracking-wider">Kota / Kab.</Label>
                    <Input name="shipping_city" value={form.shipping_city} onChange={handleInputChange} required className="bg-slate-50 border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-900 rounded-xl" />
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-slate-700 font-semibold text-xs uppercase tracking-wider">Provinsi</Label>
                    <Input name="shipping_province" value={form.shipping_province} onChange={handleInputChange} required className="bg-slate-50 border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-900 rounded-xl" />
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-slate-700 font-semibold text-xs uppercase tracking-wider">Kode Pos</Label>
                    <Input name="shipping_postal_code" value={form.shipping_postal_code} onChange={handleInputChange} required className="bg-slate-50 border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-900 rounded-xl" />
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Pilihan Ekspedisi */}
            <Card className="bg-white border-slate-200 shadow-sm rounded-2xl overflow-hidden">
              <CardContent className="p-7">
                <h3 className="text-slate-900 font-extrabold text-lg mb-5 flex items-center gap-2">
                  <span className="text-amber-500 text-xl">🚚</span> Kurir Ekspedisi
                </h3>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {COURIERS.map(courier => {
                    const isSelected = form.shipping_courier === courier.id;
                    return (
                      <div 
                        key={courier.id} 
                        onClick={() => handleCourierSelect(courier.id)}
                        className={`flex items-center justify-between p-4 rounded-xl border-2 cursor-pointer transition-all ${
                          isSelected ? 'bg-amber-50 border-amber-500 shadow-md' : 'bg-slate-50 border-slate-200 hover:border-slate-300 hover:bg-slate-100'
                        }`}
                      >
                        <div className="flex items-center gap-3">
                          <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center transition-colors ${isSelected ? 'border-amber-500' : 'border-slate-400 bg-white'}`}>
                            {isSelected && <div className="w-2.5 h-2.5 rounded-full bg-amber-500" />}
                          </div>
                          <span className={`${isSelected ? 'text-amber-600 font-bold' : 'text-slate-700 font-medium'} text-sm`}>{courier.label}</span>
                        </div>
                        <span className={`text-xs font-extrabold ${isSelected ? 'text-amber-600' : 'text-slate-500'}`}>{formatRupiah(courier.cost)}</span>
                      </div>
                    );
                  })}
                </div>
              </CardContent>
            </Card>

            {/* Catatan (Opsional) */}
            <Card className="bg-white border-slate-200 shadow-sm rounded-2xl overflow-hidden">
              <CardContent className="p-7">
                <h3 className="text-slate-900 font-extrabold text-lg mb-4 flex items-center gap-2">
                  <span className="text-slate-400 text-xl">📝</span> Catatan Pesanan
                </h3>
                <Textarea name="notes" value={form.notes} onChange={handleInputChange} className="bg-slate-50 border-slate-200 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 text-slate-900 rounded-xl resize-none shadow-inner" rows={2} placeholder="Opsional: Detail warna, catatan khusus, dll." />
              </CardContent>
            </Card>

          </div>

          {/* ── Kanan: Ringkasan & Submit ──────────────────────── */}
          <div className="lg:col-span-5">
            <Card className="bg-white border-slate-200 shadow-xl shadow-slate-200/50 rounded-2xl sticky top-28 overflow-hidden z-10 transition-all hover:-translate-y-1 hover:shadow-2xl">
              <div className="h-1.5 bg-amber-500 w-full" />
              <CardContent className="p-7">
                <div className="flex justify-between items-center mb-6">
                  <h3 className="text-slate-900 font-extrabold text-lg">Ringkasan Pesanan</h3>
                  <Button type="button" variant="link" onClick={() => router.push('/cart')} className="text-amber-500 hover:text-amber-600 font-bold h-auto p-0">Edit Keranjang</Button>
                </div>

                {/* Items preview */}
                <div className="space-y-4 mb-7 max-h-64 overflow-y-auto pr-2 custom-scrollbar">
                  {cart.map(item => (
                    <div key={item.product_id} className="flex justify-between items-start text-sm pb-4 border-b border-slate-100 last:border-0 last:pb-0">
                      <div>
                        <p className="text-slate-900 font-bold line-clamp-1 pr-3">{item.product_name}</p>
                        <p className="text-slate-500 text-xs mt-1 font-semibold">{item.quantity} x <span className="text-slate-700">{formatRupiah(item.product_price)}</span></p>
                      </div>
                      <p className="text-slate-900 font-extrabold whitespace-nowrap bg-slate-50 px-2 py-1 rounded border border-slate-100">
                        {formatRupiah(item.product_price * item.quantity)}
                      </p>
                    </div>
                  ))}
                </div>

                <div className="bg-slate-50 rounded-xl p-5 space-y-4 mb-7 border border-slate-200 shadow-inner">
                  <div className="flex justify-between text-sm font-semibold">
                    <span className="text-slate-500">Subtotal</span>
                    <span className="text-slate-900">{formatRupiah(cartTotal)}</span>
                  </div>
                  <div className="flex justify-between text-sm border-b border-slate-200 border-dashed pb-4 font-semibold">
                    <span className="text-slate-500">Ongkos Kirim ({selectedCourierObj?.label?.split(' ')[0]})</span>
                    <span className="text-slate-900">{formatRupiah(shippingCost)}</span>
                  </div>
                  <div className="flex justify-between items-end pt-2">
                    <span className="text-slate-900 font-extrabold uppercase tracking-widest text-[11px]">Total Tagihan</span>
                    <span className="text-3xl font-extrabold text-amber-500">{formatRupiah(grandTotal)}</span>
                  </div>
                </div>

                <Button 
                  type="submit" 
                  disabled={isSubmitting || cart.length === 0}
                  className="w-full bg-slate-900 hover:bg-slate-800 text-white shadow-xl shadow-slate-900/10 font-bold py-6 text-base group rounded-xl transition-all"
                >
                  <span className="flex items-center gap-2">
                    {isSubmitting ? 'Memproses Gateway...' : 'Lanjutkan ke Pembayaran'}
                    {!isSubmitting && <span className="transform transition-transform text-amber-500 text-xl group-hover:translate-x-1">→</span>}
                  </span>
                </Button>

                <p className="text-center text-[11px] font-semibold tracking-wider text-slate-400 mt-5 flex items-center justify-center gap-2 uppercase">
                  <span>🔒</span> Pembayaran aman dienkripsi oleh Midtrans
                </p>
              </CardContent>
            </Card>
          </div>

        </form>
      </div>
      <Footer />
    </main>
  );
}
