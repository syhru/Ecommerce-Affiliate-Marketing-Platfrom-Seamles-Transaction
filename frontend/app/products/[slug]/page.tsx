'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { apiGet } from '@/src/lib/api';
import type { Product } from '@/src/types/product';
import { Minus, Plus, Share2, ShoppingCart, Zap } from 'lucide-react';
import Link from 'next/link';
import { notFound, useRouter } from 'next/navigation';
import { use, useEffect, useState } from 'react';
import { toast } from 'sonner';

const formatRupiah = (n: number) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(n);

export default function ProductDetailPage({ params }: { params: Promise<{ slug: string }> }) {
  const router = useRouter();
  const { slug } = use(params);

  const [product, setProduct] = useState<Product | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isError, setIsError] = useState(false);
  
  const [quantity, setQuantity] = useState(1);
  const [isAddingToCart, setIsAddingToCart] = useState(false);

  useEffect(() => {
    async function fetchProduct() {
      try {
        const res = await apiGet<{ data: Product }>(`/products/${slug}`);
        if (!res || !res.data) {
          setIsError(true);
        } else {
          setProduct(res.data);
        }
      } catch (err: any) {
        if (err.message && err.message.includes('404')) {
          setIsError(true);
        } else {
          toast.error('Gagal mengambil data produk.');
          setIsError(true);
        }
      } finally {
        setIsLoading(false);
      }
    }
    fetchProduct();
  }, [slug]);

  if (isError) {
    notFound();
  }

  // Helper untuk mendapatkan gambar
  const getImageUrl = (url: string | null) => {
    if (!url) return null;
    return url.startsWith('http') ? url : `http://localhost:8000/storage/${url}`;
  };

  const handleCopyLink = () => {
    const link = window.location.href;
    navigator.clipboard.writeText(link).then(() => {
      toast.success('Link produk berhasil disalin!');
    });
  };

  const executeAddToCart = (productObj: Product, qty: number) => {
    try {
      if (typeof window === 'undefined') return;
      
      const cartStr = localStorage.getItem('tdr_cart');
      let cart = cartStr ? JSON.parse(cartStr) : [];
      if (!Array.isArray(cart)) cart = [];

      const existingItem = cart.find((item: any) => item.product_id === productObj.id);
      if (existingItem) {
        existingItem.quantity += qty;
        if (existingItem.quantity > productObj.stock) {
          existingItem.quantity = productObj.stock;
        }
      } else {
        cart.push({
          product_id: productObj.id,
          product_name: productObj.name,
          product_price: productObj.price,
          product_slug: productObj.slug,
          thumbnail_url: productObj.thumbnailUrl,
          stock: productObj.stock,
          quantity: qty,
          affiliate_code: null
        });
      }

      localStorage.setItem('tdr_cart', JSON.stringify(cart));
      window.dispatchEvent(new Event('cart_updated'));
      toast.success(`${qty}x ${productObj.name} ditambahkan ke keranjang.`);
    } catch (error) {
      toast.error('Gagal menambahkan ke keranjang.');
    }
  };

  const handleAddToCart = () => {
    if (!product) return;
    
    // Auth Check
    const isAuth = typeof document !== 'undefined' && document.cookie.includes('auth_token=');
    if (!isAuth) {
      toast.info('Kamu perlu login untuk menambahkan ke keranjang.');
      router.push('/login');
      return;
    }

    setIsAddingToCart(true);
    executeAddToCart(product, quantity);
    setTimeout(() => setIsAddingToCart(false), 500);
  };

  const handleBuyNow = async () => {
    if (!product) return;
    
    // Auth Check
    const isAuth = typeof document !== 'undefined' && document.cookie.includes('auth_token=');
    if (!isAuth) {
      toast.info('Kamu perlu login untuk langsung membeli.');
      router.push('/login');
      return;
    }

    toast('Memproses pesanan Anda...', { id: 'buy-now-toast' });

    const buyNowItem = {
      product_id: product.id,
      product_name: product.name,
      product_price: product.price,
      product_slug: product.slug,
      thumbnail_url: product.thumbnailUrl,
      stock: product.stock,
      quantity: quantity,
      affiliate_code: null
    };

    localStorage.setItem('tdr_buy_now', JSON.stringify([buyNowItem]));

    setTimeout(() => {
      toast.dismiss('buy-now-toast');
      router.push(`/checkout?buy_now=true`);
    }, 500);
  };

  if (isLoading || !product) {
    return (
      <main className="min-h-screen bg-[#f8f9fa] flex flex-col pt-20">
        <Navbar />
        <div className="flex-1 w-full max-w-6xl mx-auto p-4 md:p-8 flex justify-center items-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-amber-500"></div>
        </div>
      </main>
    );
  }

  // Parse Specs jika ada
  let parsedSpecs: Record<string, string> = {};
  if (product.technicalSpecs) {
    product.technicalSpecs.split('\n').forEach(line => {
      if (line.includes(':')) {
        const [k, v] = line.split(':');
        parsedSpecs[k.trim()] = v.trim();
      }
    });
  }

  return (
    <main className="min-h-screen bg-[#f8f9fa] flex flex-col pt-20">
      <Navbar />
      
      <div className="flex-1 w-full max-w-6xl mx-auto px-4 md:px-8 py-8">
        
        {/* ── Breadcrumb ── */}
        <nav className="flex items-center gap-2 text-sm font-medium text-slate-500 mb-8 overflow-x-auto whitespace-nowrap pb-2">
          <Link href="/" className="hover:text-amber-600 transition-colors">Beranda</Link>
          <span className="text-slate-300">/</span>
          <Link href="/shop" className="hover:text-amber-600 transition-colors">Katalog Produk</Link>
          <span className="text-slate-300">/</span>
          <span className="text-slate-900 font-bold">{product.name}</span>
        </nav>

        <div className="grid grid-cols-1 md:grid-cols-12 gap-10">
          
          {/* ── Kiri: Gambar Produk ── */}
          <div className="md:col-span-5">
            <div className="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 flex items-center justify-center min-h-[400px] md:sticky top-28">
              {product.thumbnailUrl ? (
                /* eslint-disable-next-line @next/next/no-img-element */
                <img
                  src={getImageUrl(product.thumbnailUrl) || ''}
                  alt={product.name}
                  className="w-full h-auto object-contain max-h-[450px] transition-transform hover:scale-105 duration-500"
                />
              ) : (
                <div className="flex flex-col items-center justify-center text-slate-300">
                  <span className="text-8xl mb-4 text-slate-200 drop-shadow-sm">📦</span>
                  <span className="text-sm font-bold uppercase tracking-widest">{product.brand}</span>
                </div>
              )}
            </div>
          </div>

          {/* ── Kanan: Info & Action ── */}
          <div className="md:col-span-7 flex flex-col">
            
            {/* Header / Titles */}
            <div className="mb-6">
              <div className="flex items-center gap-3 mb-3">
                <span className="px-3 py-1 bg-amber-100 text-amber-700 text-xs font-black uppercase tracking-widest rounded-full">
                  {product.category}
                </span>
                {product.brand && (
                  <span className="text-slate-500 text-sm font-bold uppercase tracking-wider">
                    {product.brand}
                  </span>
                )}
              </div>
              <h1 className="text-3xl md:text-4xl font-extrabold text-slate-900 leading-tight tracking-tight mb-4">
                {product.name}
              </h1>
              
              <div className="flex items-end gap-4 mb-2">
                <span className="text-4xl font-extrabold text-amber-500 tracking-tight">
                  {formatRupiah(product.price)}
                </span>
                <span className={`px-3 py-1.5 rounded-xl text-xs font-bold ${
                  product.stock > 10 ? 'bg-emerald-100 text-emerald-700' : 
                  product.stock > 0 ? 'bg-orange-100 text-orange-700' : 'bg-red-100 text-red-700'
                }`}>
                  {product.stock > 10 ? 'Stok Tersedia' : product.stock > 0 ? `Sisa ${product.stock} pcs` : 'Stok Habis'}
                </span>
              </div>
            </div>

            <hr className="border-slate-200 my-6" />

            {/* Description */}
            <div className="mb-8">
              <h3 className="text-lg font-bold text-slate-900 mb-3">Deskripsi Produk</h3>
              <p className="text-slate-600 leading-relaxed font-medium">
                {product.description || 'Tidak ada deskripsi rinci untuk produk ini.'}
              </p>
            </div>

            {/* Specifications */}
            {Object.keys(parsedSpecs).length > 0 && (
              <div className="mb-8">
                <h3 className="text-lg font-bold text-slate-900 mb-3">Spesifikasi Teknik</h3>
                <div className="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                  <table className="w-full text-sm text-left">
                    <tbody>
                      {Object.entries(parsedSpecs).map(([key, value], idx) => (
                        <tr key={idx} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/50">
                          <td className="px-5 py-3 font-semibold text-slate-500 w-1/3 bg-slate-50/30">{key}</td>
                          <td className="px-5 py-3 font-bold text-slate-900">{value}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {/* Actions Panel */}
            <div className="mt-auto bg-white p-6 rounded-3xl border border-slate-200 shadow-xl shadow-slate-200/50">
              {product.stock > 0 ? (
                <div className="flex flex-col sm:flex-row gap-4">
                  {/* Qty Selector */}
                  <div className="flex items-center justify-between border-2 border-slate-100 bg-slate-50 rounded-2xl px-2 py-1 shrink-0">
                    <Button 
                      type="button"
                      variant="ghost" 
                      size="icon" 
                      className="text-slate-500 hover:text-slate-900 hover:bg-slate-200 rounded-xl w-10 h-10"
                      onClick={(e) => { e.preventDefault(); setQuantity(Math.max(1, quantity - 1)); }}
                    >
                      <Minus className="w-4 h-4" />
                    </Button>
                    <Input 
                      type="number" 
                      value={quantity} 
                      readOnly 
                      className="w-14 text-center font-bold text-lg border-0 bg-transparent text-black opacity-100 focus-visible:ring-0 p-0"
                    />
                    <Button 
                      type="button"
                      variant="ghost" 
                      size="icon" 
                      className="text-slate-500 hover:text-slate-900 hover:bg-slate-200 rounded-xl w-10 h-10"
                      onClick={(e) => { e.preventDefault(); setQuantity(Math.min(product.stock, quantity + 1)); }}
                    >
                      <Plus className="w-4 h-4" />
                    </Button>
                  </div>

                  {/* Buttons */}
                  <Button 
                    variant="outline"
                    disabled={isAddingToCart}
                    onClick={handleAddToCart}
                    className="flex-1 py-7 text-base font-bold text-slate-700 bg-white border-2 border-slate-200 hover:bg-slate-50 rounded-2xl"
                  >
                    <ShoppingCart className="w-5 h-5 mr-2" />
                    Keranjang
                  </Button>
                  
                  <Button 
                    onClick={handleBuyNow}
                    className="flex-1 py-7 text-base font-bold text-slate-900 bg-amber-500 hover:bg-amber-400 shadow-lg shadow-amber-500/20 rounded-2xl"
                  >
                    <Zap className="w-5 h-5 mr-2 fill-slate-900" />
                    Beli Sekarang
                  </Button>
                </div>
              ) : (
                <div className="text-center p-4 bg-slate-100 rounded-2xl border border-slate-200">
                  <p className="font-bold text-slate-500">Maaf, Stok Produk Habis</p>
                </div>
              )}

              {/* Share */}
              <div className="mt-5 flex justify-center">
                <Button 
                  variant="ghost" 
                  onClick={handleCopyLink}
                  className="text-slate-500 font-bold hover:text-amber-600 hover:bg-amber-50 rounded-full px-5"
                >
                  <Share2 className="w-4 h-4 mr-2" />
                  Salin Link Produk
                </Button>
              </div>
            </div>

          </div>
        </div>

      </div>
      <Footer />
    </main>
  );
}
