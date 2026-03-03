'use client';

import { Footer } from '@/components/Footer';
import { Navbar } from '@/components/Navbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { apiGet, apiPost } from '@/src/lib/api';
import type { Product } from '@/src/types/product';
import { useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

// ─── Types ───────────────────────────────────────────────────
interface PaginatedMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number;
  to?: number;
}
interface PaginatedResponse<T> {
  data: T[];
  meta: PaginatedMeta;
}

type CategoryFilter = 'all' | 'motor' | 'shockbreaker';
type SortOption    = 'created_at' | 'price' | 'name';

// ─── Utils ────────────────────────────────────────────────────
const formatRupiah = (n: number) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(n);

const isLoggedIn = () =>
  typeof document !== 'undefined' && document.cookie.includes('auth_token=');

const truncate = (s: string, max: number) => s.length > max ? s.slice(0, max) + '…' : s;

// ─── Category Tabs ────────────────────────────────────────────
const CATEGORIES: { value: CategoryFilter; label: string; emoji: string }[] = [
  { value: 'all',          label: 'Semua',       emoji: '🛍️' },
  { value: 'motor',        label: 'Motor',        emoji: '🏍️' },
  { value: 'shockbreaker', label: 'Shockbreaker', emoji: '🔧' },
];

// ─── Sort Options ─────────────────────────────────────────────
const SORT_OPTIONS: { value: SortOption; label: string }[] = [
  { value: 'created_at', label: 'Terbaru' },
  { value: 'price',      label: 'Harga' },
  { value: 'name',       label: 'Nama A–Z' },
];

// ─── Stock Badge ──────────────────────────────────────────────
function StockBadge({ stock }: { stock: number }) {
  if (stock === 0)  return <span className="text-[10px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 font-medium">Habis</span>;
  if (stock <= 5)   return <span className="text-[10px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-600 font-medium">Stok: {stock}</span>;
  return                    <span className="text-[10px] px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-600 font-medium">Stok: {stock}</span>;
}

// ─── Category Pill ────────────────────────────────────────────
function CategoryPill({ category }: { category: string }) {
  const map: Record<string, string> = {
    motor:        'bg-blue-100 text-blue-600',
    shockbreaker: 'bg-purple-100 text-purple-600',
  };
  return (
    <span className={`text-[10px] px-2.5 py-1 rounded-full font-bold uppercase tracking-wider backdrop-blur-sm ${map[category] ?? 'bg-white/90 text-slate-800 shadow-sm'}`}>
      {category}
    </span>
  );
}

// ─── Skeleton Card ────────────────────────────────────────────
function SkeletonCard() {
  return (
    <div className="rounded-2xl border border-slate-100 bg-white overflow-hidden shadow-sm animate-pulse">
      <div className="h-44 bg-slate-100" />
      <div className="p-4 space-y-3">
        <div className="h-3 bg-slate-200 rounded w-1/3" />
        <div className="h-4 bg-slate-200 rounded w-3/4" />
        <div className="h-3 bg-slate-200 rounded w-full" />
        <div className="h-3 bg-slate-200 rounded w-2/3" />
        <div className="flex justify-between items-center pt-2">
          <div className="h-5 bg-slate-200 rounded w-1/3" />
          <div className="h-8 bg-slate-200 rounded w-16" />
        </div>
      </div>
    </div>
  );
}

// ─── Product Card ─────────────────────────────────────────────
function ProductCard({ product, onAddToCart, onBuyNow }: { product: Product; onAddToCart: (p: Product) => void, onBuyNow: (p: Product) => void }) {
  const router = useRouter();

  return (
    <div className="block h-full group">
      <Card className="bg-white border-slate-200 hover:border-amber-500/50 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl flex flex-col h-full overflow-hidden">
        
        {/* Clickable Area: Image & Title */}
        <div onClick={() => router.push(`/products/${product.slug}`)} className="cursor-pointer flex flex-col flex-1">

        {/* Thumbnail */}
        <div className="relative h-48 bg-slate-50 overflow-hidden shrink-0">
        {product.thumbnailUrl ? (
          /* eslint-disable-next-line @next/next/no-img-element */
          <img
            src={product.thumbnailUrl.startsWith('http') ? product.thumbnailUrl : `http://localhost:8000/storage/${product.thumbnailUrl}`}
            alt={product.name}
            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
          />
        ) : (
          <div className="w-full h-full flex flex-col items-center justify-center text-slate-300">
            <span className="text-5xl mb-1">📦</span>
            <span className="text-xs font-medium uppercase tracking-wider">{product.brand}</span>
          </div>
        )}
        {/* Category pill overlay */}
        <div className="absolute top-3 left-3">
          <CategoryPill category={product.category} />
        </div>
      </div>

      {/* Content */}
      <CardContent className="flex-1 pt-4 pb-2 px-5">
        <p className="text-[11px] text-slate-400 mb-1.5 font-bold uppercase tracking-wider truncate">{product.brand} · {product.type}</p>
        <h3 className="text-slate-900 font-bold text-base leading-snug mb-2 line-clamp-2">
          {product.name}
        </h3>
        {product.description && (
          <p className="text-slate-500 text-xs leading-relaxed line-clamp-2">
            {truncate(product.description, 80)}
          </p>
        )}
      </CardContent>
      </div>

      {/* Footer */}
      <CardFooter className="px-5 pb-5 pt-0 flex flex-col gap-3 border-t border-slate-100 mt-4">
        <div className="w-full flex items-center justify-between pt-3">
          <p className="text-amber-500 font-extrabold text-lg leading-tight">{formatRupiah(product.price)}</p>
          <div className="mt-1">
            <StockBadge stock={product.stock} />
          </div>
        </div>
        <div className="w-full flex gap-2">
          <Button
            size="sm"
            variant="outline"
            disabled={product.stock === 0}
            onClick={(e) => { e.preventDefault(); e.stopPropagation(); onAddToCart(product); }}
            className={`flex-1 font-bold transition-all rounded-full px-2 ${
              product.stock === 0
                ? 'bg-slate-50 text-slate-400 cursor-not-allowed border border-slate-200'
                : 'bg-white border-slate-300 text-slate-700 shadow-sm'
            }`}
          >
            🛒 Cart
          </Button>
          <Button
            size="sm"
            disabled={product.stock === 0}
            onClick={(e) => { e.preventDefault(); e.stopPropagation(); onBuyNow(product); }}
            className={`flex-1 font-bold transition-all rounded-full px-2 ${
              product.stock === 0
                ? 'bg-slate-100 text-slate-400 cursor-not-allowed border border-slate-200'
                : 'bg-slate-900 hover:bg-slate-800 text-white shadow-md'
            }`}
          >
            {product.stock === 0 ? 'Habis' : 'Beli'}
          </Button>
        </div>
      </CardFooter>
    </Card>
    </div>
  );
}

// ─── Inner Page (needs useSearchParams — inside Suspense) ─────
function ShopContent() {
  const router       = useRouter();
  const searchParams = useSearchParams();

  const [searchInput,   setSearchInput  ] = useState(searchParams.get('q') ?? '');
  const [activeSearch,  setActiveSearch ] = useState(searchParams.get('q') ?? '');
  const [activeCategory,setActiveCategory] = useState<CategoryFilter>((searchParams.get('category') as CategoryFilter) ?? 'all');
  const [activeSort,    setActiveSort   ] = useState<SortOption>('created_at');
  const [activePage,    setActivePage   ] = useState(1);

  const [products,  setProducts ] = useState<Product[]>([]);
  const [meta,      setMeta     ] = useState<PaginatedMeta>({ current_page: 1, last_page: 1, per_page: 12, total: 0, from: 0, to: 0 });
  const [isLoading, setIsLoading] = useState(true);

  // ── Capture affiliate referral code from URL ──────────────
  useEffect(() => {
    const ref = searchParams.get('ref');
    if (ref) {
      localStorage.setItem('tdr_affiliate_ref', ref);
    }
  }, [searchParams]);

  const getAffiliateCode = (): string | null => {
    if (typeof window === 'undefined') return null;
    return localStorage.getItem('tdr_affiliate_ref');
  };

  // ── Fetch ──────────────────────────────────────────────────
  const fetchProducts = useCallback(async (q: string, category: CategoryFilter, sort: SortOption, page: number) => {
    setIsLoading(true);
    try {
      const params = new URLSearchParams({ page: String(page), per_page: '12', sort });
      if (q)                   params.set('q', q);
      if (category !== 'all')  params.set('category', category);

      const res = await apiGet<PaginatedResponse<Product>>(`/products?${params.toString()}`);
      setProducts(res.data);
      setMeta(res.meta);
    } catch {
      toast.error('Gagal memuat produk. Pastikan server Laravel berjalan.');
      setProducts([]);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchProducts(activeSearch, activeCategory, activeSort, activePage);
  }, [activeSearch, activeCategory, activeSort, activePage, fetchProducts]);

  // ── Handlers ───────────────────────────────────────────────
  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    setActiveSearch(searchInput);
    setActivePage(1);
  };

  const handleCategoryChange = (cat: CategoryFilter) => {
    setActiveCategory(cat);
    setActivePage(1);
  };

  const handleSortChange = (sort: SortOption) => {
    setActiveSort(sort);
    setActivePage(1);
  };

  const handleClear = () => {
    setSearchInput('');
    setActiveSearch('');
    setActiveCategory('all');
    setActivePage(1);
  };

  const executeAddToCart = (product: Product) => {
    if (!isLoggedIn()) {
      toast.info('Login dulu untuk membeli produk ya!');
      router.push('/login?redirect=/shop');
      return;
    }
    
    // Add to Local Storage Cart fallback just in case
    try {
        const localCart = JSON.parse(localStorage.getItem('tdr_cart') || '[]');
        const existingItem = localCart.find((item: { product_id: number; quantity: number }) => item.product_id === product.id);
        if (existingItem) {
            existingItem.quantity += 1;
        } else {
            localCart.push({
                product_id: product.id,
                product_name: product.name,
                product_price: product.price,
                product_slug: product.slug,
                thumbnail_url: product.thumbnailUrl,
                stock: product.stock,
                quantity: 1,
                affiliate_code: getAffiliateCode()
            });
        }
        // Simpan data array keranjang terbaru ke Storage
        localStorage.setItem('tdr_cart', JSON.stringify(localCart));
        
        // Trigger navbar update immediately
        window.dispatchEvent(new Event('cart_updated')); 

        // Kasih feedback instan
        toast.success(`Berhasil menambahkan ${product.name} ke keranjang!`);
        
    } catch (e) {
        console.error("Local cart error", e);
    }

    // API call to backend (Fire and Forget)
    apiPost('/cart/add', { product_id: product.id, quantity: 1 }).catch(() => {
       // Ignore failing API as we rely on LocalStorage locally
    });
  };

  const handleAddToCart = (product: Product) => {
    executeAddToCart(product);
  };

  const handleBuyNow = (product: Product) => {
    if (!isLoggedIn()) {
      toast.info('Login dulu untuk membeli produk ya!');
      router.push('/login?redirect=/shop');
      return;
    }

    const buyNowItem = {
      product_id: product.id,
      product_name: product.name,
      product_price: product.price,
      product_slug: product.slug,
      thumbnail_url: product.thumbnailUrl,
      stock: product.stock,
      quantity: 1,
      affiliate_code: getAffiliateCode()
    };

    localStorage.setItem('tdr_buy_now', JSON.stringify([buyNowItem]));
    router.push(`/checkout?buy_now=true`);
  };

  const hasActiveFilter = activeSearch || activeCategory !== 'all';

  return (
    <main className="min-h-screen bg-[#f8f9fa] selection:bg-amber-200 selection:text-amber-900 font-sans pt-16 flex flex-col">
      <Navbar />

      {/* ── Hero Header ───────────────────────────────────── */}
      <div className="bg-white border-b border-slate-100 shadow-sm z-20">
        <div className="max-w-7xl mx-auto px-4 py-8">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-6">
            <div>
              <h1 className="text-slate-900 font-extrabold text-3xl tracking-tight">Katalog Produk</h1>
              <p className="text-slate-500 font-medium mt-1">
                Suku cadang motor TDR & HPZ dengan performa maksimal
              </p>
            </div>
            {/* Search */}
            <form onSubmit={handleSearch} className="flex gap-2 w-full sm:w-auto">
              <Input
                placeholder="Cari produk, spare part..."
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                className="bg-slate-50 border-slate-200 text-slate-900 placeholder:text-slate-400 focus:border-amber-500 focus:ring-amber-500 w-full sm:w-72 shadow-sm rounded-full px-4"
              />
              <Button type="submit" className="bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold shrink-0 rounded-full px-6 shadow-sm">
                Cari
              </Button>
            </form>
          </div>

          {/* ── Filter Bar ─────────────────────────────────── */}
          <div className="flex flex-wrap items-center justify-between gap-4 mt-8 pt-6 border-t border-slate-100">
            {/* Category Tabs */}
            <div className="flex gap-2">
              {CATEGORIES.map((cat) => (
                <button
                  key={cat.value}
                  onClick={() => handleCategoryChange(cat.value)}
                  className={`flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-semibold transition-all shadow-sm border ${
                    activeCategory === cat.value
                      ? 'bg-amber-500 text-slate-900 border-amber-500'
                      : 'bg-white text-slate-600 hover:text-slate-900 hover:bg-slate-50 border-slate-200'
                  }`}
                >
                  <span className="opacity-80">{cat.emoji}</span> {cat.label}
                </button>
              ))}
            </div>

            {/* Sort */}
            <div className="flex gap-2 ml-auto">
              {SORT_OPTIONS.map((opt) => (
                <button
                  key={opt.value}
                  onClick={() => handleSortChange(opt.value)}
                  className={`px-4 py-2 rounded-full text-xs font-semibold transition-all border shadow-sm ${
                    activeSort === opt.value
                      ? 'border-amber-500/50 text-amber-600 bg-amber-50'
                      : 'border-slate-200 text-slate-500 hover:text-slate-800 bg-white hover:bg-slate-50'
                  }`}
                >
                  {opt.label}
                </button>
              ))}
            </div>
          </div>
        </div>
      </div>

      {/* ── Content ───────────────────────────────────────── */}
      <div className="max-w-7xl mx-auto px-4 py-10 flex-1 w-full">

        {/* Active filter badge */}
        {hasActiveFilter && (
          <div className="flex items-center gap-3 mb-6 p-4 rounded-xl bg-amber-50/50 border border-amber-100/50 shadow-sm">
            <span className="text-slate-600 text-sm flex-1 font-medium">
              {activeSearch && (
                <>Pencarian: <span className="text-slate-900 font-bold">&quot;{activeSearch}&quot;</span>{' '}</>
              )}
              {activeCategory !== 'all' && (
                <>Kategori: <span className="text-amber-600 font-bold capitalize">{activeCategory}</span>{' '}</>
              )}
              {!isLoading && <span className="text-slate-500">— {meta.total} produk ditemukan</span>}
            </span>
            <Button variant="ghost" size="sm" onClick={handleClear}
              className="text-slate-500 hover:text-slate-900 hover:bg-white bg-white/50 text-xs h-8 px-3 rounded-full font-semibold border border-slate-200/60 shadow-sm">
              ✕ Reset Filter
            </Button>
          </div>
        )}

        {/* Total info */}
        {!hasActiveFilter && !isLoading && (
          <p className="text-slate-500 text-sm mb-6 font-medium">
            Menampilkan <span className="text-slate-900 font-bold">{meta.from ?? 1}–{meta.to ?? products.length}</span> dari <span className="text-slate-900 font-bold">{meta.total}</span> produk
          </p>
        )}

        {/* Skeleton */}
        {isLoading && (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            {Array.from({ length: 8 }).map((_, i) => <SkeletonCard key={i} />)}
          </div>
        )}

        {/* Empty */}
        {!isLoading && products.length === 0 && (
          <div className="flex flex-col items-center justify-center py-24 text-center bg-white rounded-3xl border border-slate-100 shadow-sm">
            <span className="text-6xl mb-4 opacity-50">🔍</span>
            <h3 className="text-slate-900 font-bold text-xl mb-2">Produk tidak ditemukan</h3>
            <p className="text-slate-500 mb-6 max-w-sm font-medium">
              Tidak ada produk untuk filter yang dipilih. Coba ubah kata kunci atau kategori.
            </p>
            <Button onClick={handleClear} className="bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold rounded-full px-6">
              Lihat semua produk
            </Button>
          </div>
        )}

        {/* Grid */}
        {!isLoading && products.length > 0 && (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            {products.map((p) => (
              <ProductCard key={p.id} product={p} onAddToCart={handleAddToCart} onBuyNow={handleBuyNow} />
            ))}
          </div>
        )}

        {/* Pagination */}
        {!isLoading && meta.last_page > 1 && (
          <div className="flex items-center justify-center gap-4 mt-16 pb-8">
            <Button variant="outline" size="sm" disabled={activePage <= 1}
              onClick={() => setActivePage((p) => Math.max(1, p - 1))}
              className="border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 disabled:opacity-30 rounded-full font-semibold shadow-sm">
              ‹ Sebelumnya
            </Button>
            <div className="flex items-center gap-1.5">
              {Array.from({ length: Math.min(meta.last_page, 5) }, (_, i) => {
                const page = i + 1;
                return (
                  <button key={page} onClick={() => setActivePage(page)}
                    className={`w-9 h-9 rounded-full text-sm font-bold transition-all shadow-sm border ${
                      activePage === page
                        ? 'bg-amber-500 text-slate-900 border-amber-500'
                        : 'bg-white text-slate-500 hover:text-slate-900 hover:bg-slate-50 border-slate-200'
                    }`}>
                    {page}
                  </button>
                );
              })}
              {meta.last_page > 5 && <span className="text-slate-400 text-sm font-bold mx-2">… {meta.last_page}</span>}
            </div>
            <Button variant="outline" size="sm" disabled={activePage >= meta.last_page}
              onClick={() => setActivePage((p) => Math.min(meta.last_page, p + 1))}
              className="border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 disabled:opacity-30 rounded-full font-semibold shadow-sm">
              Berikutnya ›
            </Button>
          </div>
        )}
      </div>

      <Footer />
    </main>
  );
}

// ─── Root Export (Suspense wrapper untuk useSearchParams) ─────
export default function ShopPage() {
  return (
    <Suspense fallback={
      <div className="min-h-screen bg-[#f8f9fa] flex items-center justify-center">
        <div className="text-slate-500 font-medium animate-pulse">Memuat katalog...</div>
      </div>
    }>
      <ShopContent />
    </Suspense>
  );
}
