'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import Image from 'next/image';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

// ─── Interfaces ───────────────────────────────────────────────
export interface CartItem {
  product_id: number;
  product_name: string;
  product_price: number;
  product_slug: string;
  thumbnail_url: string | null;
  stock: number;
  quantity: number;
  affiliate_code: string | null;
}

// ─── Helpers ──────────────────────────────────────────────────
const formatRupiah = (amount: number) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(amount);

// ─── Context/Hooks untuk Cart ────────────────────────────────
function getLocalCart(): CartItem[] {
  if (typeof window === 'undefined') return [];
  try {
    return JSON.parse(localStorage.getItem('tdr_cart') || '[]');
  } catch {
    return [];
  }
}

function saveLocalCart(cart: CartItem[]) {
  if (typeof window !== 'undefined') {
    localStorage.setItem('tdr_cart', JSON.stringify(cart));
    // Trigger custom event so header could catch it (optional, but good practice)
    window.dispatchEvent(new Event('cart_updated'));
  }
}

// ─── Component ────────────────────────────────────────────────
export default function CartPage() {
  const router = useRouter();
  const [cart, setCart] = useState<CartItem[]>([]);
  const [isMounted, setIsMounted] = useState(false);

  useEffect(() => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-argument
    setCart(getLocalCart() as CartItem[]);
    setIsMounted(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const updateQuantity = (productId: number, newQty: number) => {
    const updated = cart.map(item => {
      if (item.product_id === productId) {
        // limit by stock
        const validQty = Math.max(1, Math.min(newQty, item.stock || 999));
        return { ...item, quantity: validQty };
      }
      return item;
    });
    setCart(updated);
    saveLocalCart(updated);
  };

  const removeItem = (productId: number) => {
    const updated = cart.filter(item => item.product_id !== productId);
    setCart(updated);
    saveLocalCart(updated);
    toast.success('Produk dihapus dari keranjang');
  };

  const totalItems = cart.reduce((acc, item) => acc + item.quantity, 0);
  const totalPrice = cart.reduce((acc, item) => acc + (item.product_price * item.quantity), 0);

  if (!isMounted) return null; // Avoid hydration mismatch

  return (
    <main className="min-h-screen bg-[#f8f9fa] flex flex-col pt-16">
      <Navbar />
      <div className="flex-1 w-full max-w-5xl mx-auto space-y-6 px-4 py-8 md:py-12">

        {/* Header */}
        <div>
          <h1 className="text-slate-900 font-extrabold text-2xl md:text-3xl flex items-center gap-3">
            <span className="text-amber-500">🛒</span> Keranjang Belanja
          </h1>
          <p className="text-slate-500 mt-2 text-sm font-medium">
            Periksa kembali pesanan suku cadang aslimu sebelum melanjutkan pembayaran.
          </p>
        </div>

        {cart.length === 0 ? (
          <Card className="bg-white border-slate-200 shadow-sm rounded-2xl">
             <CardContent className="flex flex-col items-center justify-center py-24 text-center space-y-4">
              <span className="text-6xl opacity-30">🛍️</span>
              <h3 className="text-slate-900 font-bold text-xl">Keranjang masih kosong</h3>
              <p className="text-slate-500 max-w-sm text-sm font-medium mb-4">
                Wah, keranjang kamu masih kosong nih. Yuk mulai cari produk TDR impianmu sekarang!
              </p>
              <Button onClick={() => router.push('/shop')} className="bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold rounded-full px-8 py-6 mt-2 shadow-md">
                Mulai Belanja 🚀
              </Button>
            </CardContent>
          </Card>
        ) : (
          <div className="flex flex-col lg:flex-row gap-6 items-start">
            
            {/* Kiri: Daftar Produk */}
            <div className="w-full lg:w-2/3 space-y-4">
              {cart.map((item) => (
                <Card key={item.product_id} className="bg-white border-slate-200 shadow-sm rounded-2xl overflow-hidden hover:shadow-md transition-shadow">
                  <div className="p-5 flex flex-col sm:flex-row gap-5 items-start sm:items-center">
                    {/* Gambar Produk */}
                    <div className="w-24 h-24 shrink-0 bg-slate-50 rounded-xl flex items-center justify-center overflow-hidden border border-slate-100">
                      {item.thumbnail_url ? (
                        <Image 
                          src={item.thumbnail_url.startsWith('http') ? item.thumbnail_url : `http://localhost:8000/storage/${item.thumbnail_url}`} 
                          alt={item.product_name}
                          width={96} height={96}
                          className="object-cover w-full h-full"
                        />
                      ) : (
                        <span className="text-3xl opacity-30">📦</span>
                      )}
                    </div>

                    {/* Info */}
                    <div className="flex-1 w-full">
                      <div className="flex justify-between items-start gap-4">
                        <h4 className="text-slate-900 font-bold line-clamp-2 pr-4">{item.product_name}</h4>
                        <button 
                          onClick={() => removeItem(item.product_id)}
                          className="text-slate-400 hover:text-red-500 hover:bg-red-50 transition-colors p-2 rounded-lg"
                          title="Hapus"
                        >
                          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                        </button>
                      </div>
                      
                      <div className="text-amber-500 font-extrabold mt-1 text-lg">
                        {formatRupiah(item.product_price)}
                      </div>
                      
                      {item.affiliate_code && (
                        <div className="mt-2 inline-flex items-center gap-1.5 px-2.5 py-1 rounded border text-[10px] font-bold uppercase tracking-wider bg-amber-50 text-amber-600 border-amber-200">
                          <span>🏷️</span> REF: {item.affiliate_code}
                        </div>
                      )}

                      {/* Controls */}
                      <div className="flex items-center justify-between mt-5">
                        <div className="flex items-center gap-1 bg-white rounded-lg border border-slate-200 p-1 shadow-sm">
                          <button 
                            onClick={() => updateQuantity(item.product_id, item.quantity - 1)}
                            className="w-8 h-8 rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 flex items-center justify-center font-bold transition-colors"
                            disabled={item.quantity <= 1}
                          >
                            -
                          </button>
                          <span className="w-10 text-center text-sm font-bold text-slate-900">
                            {item.quantity}
                          </span>
                          <button 
                            onClick={() => updateQuantity(item.product_id, item.quantity + 1)}
                            className="w-8 h-8 rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 flex items-center justify-center font-bold transition-colors"
                            disabled={item.quantity >= item.stock}
                          >
                            +
                          </button>
                        </div>
                        <div className="text-right">
                          <div className="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-0.5">Subtotal</div>
                          <div className="text-slate-900 font-extrabold">{formatRupiah(item.product_price * item.quantity)}</div>
                        </div>
                      </div>

                    </div>
                  </div>
                </Card>
              ))}
            </div>

            {/* Kanan: Ringkasan */}
            <div className="w-full lg:w-1/3 sticky top-28">
              <Card className="bg-white border-slate-200 shadow-sm rounded-2xl overflow-hidden">
                <div className="h-1.5 w-full bg-amber-500" />
                <CardContent className="p-7">
                  <h3 className="text-slate-900 font-extrabold text-lg mb-4">Ringkasan Belanja</h3>
                  
                  <div className="space-y-4 text-sm border-b border-slate-100 pb-5 mb-5 font-medium">
                    <div className="flex justify-between text-slate-500">
                      <span>Total Item</span>
                      <span className="text-slate-900">{totalItems} produk</span>
                    </div>
                    <div className="flex justify-between text-slate-500">
                      <span>Total Harga</span>
                      <span className="text-slate-900 font-bold">{formatRupiah(totalPrice)}</span>
                    </div>
                  </div>

                  <div className="flex justify-between items-end mb-7">
                    <span className="text-slate-500 font-extrabold uppercase text-[11px] tracking-wider mb-1">Total Tagihan</span>
                    <span className="text-3xl font-extrabold text-amber-500">{formatRupiah(totalPrice)}</span>
                  </div>

                  <Button 
                    onClick={() => router.push('/checkout')}
                    className="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold py-6 text-base rounded-2xl shadow-lg transition-transform hover:-translate-y-0.5"
                  >
                    Checkout Sekarang ({totalItems})
                  </Button>
                </CardContent>
              </Card>
            </div>

          </div>
        )}
      </div>
      <Footer />
    </main>
  );
}
