'use client';

import { Button } from '@/components/ui/button';
import { apiGet } from '@/src/lib/api';
import { LogOut, Menu, ShoppingCart, User as UserIcon } from 'lucide-react';
import Image from 'next/image';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { useUserStore } from '@/src/stores/useUserStore';

export function Navbar() {
  const router = useRouter();
  const pathname = usePathname();
  const isAuthPage = pathname === '/login' || pathname === '/register';
  const [isScrolled, setIsScrolled] = useState(false);
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const { user, setUser, fetchUser, clearUser, isLoading } = useUserStore();
  const [cartCount, setCartCount] = useState(0);
  const [isHydrated, setIsHydrated] = useState(false); 
  const [isAuthReady, setIsAuthReady] = useState(false);

  useEffect(() => {
    setIsHydrated(true);
    if (typeof window !== 'undefined') {
      setIsAuthReady(true);
    }
  }, []);

  useEffect(() => {
    const handleScroll = () => {
      setIsScrolled(window.scrollY > 20);
    };
    window.addEventListener('scroll', handleScroll);
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  useEffect(() => {
    // Check Auth - SWR Pattern managed by Zustand
    if (typeof window !== 'undefined') {
      const hasToken = document.cookie.split(';').some(c => c.trim().startsWith('auth_token='));
      if (hasToken) {
        fetchUser();
      }
    }
  }, [fetchUser]);

  // Polling: re-fetch user data every 30s to detect role changes by admin
  useEffect(() => {
    const hasToken = () => document.cookie.split(';').some(c => c.trim().startsWith('auth_token='));
    if (!hasToken()) return;

    const interval = setInterval(() => {
      if (hasToken() && !document.hidden) {
        fetchUser();
      }
    }, 30_000);

    // Also re-fetch when tab becomes visible again
    const handleVisibility = () => {
      if (!document.hidden && hasToken()) {
        fetchUser();
      }
    };
    document.addEventListener('visibilitychange', handleVisibility);

    return () => {
      clearInterval(interval);
      document.removeEventListener('visibilitychange', handleVisibility);
    };
  }, [fetchUser]);

  useEffect(() => {
    const updateCartCount = () => {
      try {
        if (typeof window !== 'undefined') {
          const cart = JSON.parse(localStorage.getItem('tdr_cart') || '[]');
          if (Array.isArray(cart)) {
            const totalQty = cart.reduce((acc: number, item: any) => {
              const q = parseInt(item.quantity, 10);
              return acc + (isNaN(q) ? 1 : q);
            }, 0);
            setCartCount(totalQty);
          } else {
            setCartCount(0);
          }
        }
      } catch { }
    };
    
    updateCartCount();
    window.addEventListener('cart_updated', updateCartCount);
    return () => window.removeEventListener('cart_updated', updateCartCount);
  }, []);

  const handleLogout = () => {
    // 1. Pembersihan Menyeluruh (Cookie, Session, Local)
    document.cookie = 'auth_token=; Max-Age=0; Path=/';
    try { localStorage.removeItem('auth_user_storage'); } catch {}
    try { sessionStorage.clear(); } catch {}

    // 2. Tanam bendera khusus logout (setelah clear) agar auth guard tidak ter-trigger
    try { sessionStorage.setItem('tdr_is_logging_out', 'true'); } catch {}

    // 3. Clear memori Zustand
    clearUser();
    toast.success('Berhasil logout.');
    
    // 4. Hard Redirect (memerintahkan browser mereload bersih memutus routing NextJS)
    window.location.href = '/login';
  };

  const handleJadiAffiliateClick = async (e: React.MouseEvent) => {
    e.preventDefault();
    setIsMobileMenuOpen(false); // Close mobile menu if open
    
    let targetPath = '/affiliate/register';

    try {
      // Selalu fetch terbaru untuk memastikan status real-time
      const res = await apiGet<any>('/user');
      const apiUser = res?.data || res?.user || res;
      
      if (apiUser) {
        if (JSON.stringify(apiUser) !== JSON.stringify(user)) {
           setUser(apiUser);
        }
        
        if (apiUser.role === 'affiliate' && apiUser.affiliate_profile?.status === 'pending') {
          targetPath = '/affiliate/pending';
        }
      }
    } catch {
      // fallback jika gagal fetch
      if (user?.role === 'affiliate' && user?.affiliate_profile?.status === 'pending') {
         targetPath = '/affiliate/pending';
      }
    }

    if (window.location.pathname !== targetPath) {
      router.push(targetPath);
    }
  };

  if (!isHydrated) return <div className="h-16 w-full bg-white shadow-sm border-b border-slate-100" />;

  const isUserLoaded = isHydrated && user !== null;
  const isGuest = isHydrated && user === null;
  const isPending = isUserLoaded && user?.affiliate_profile?.status === 'pending';
  const isRejected = isUserLoaded && user?.affiliate_profile?.status === 'rejected';
  const isAffiliate = isUserLoaded && user?.role === 'affiliate' && user?.affiliate_profile?.status === 'active';
  const isStandardUser = isUserLoaded && ((user?.role as string) === 'user' || user?.role === 'customer') && !isPending && !isAffiliate && !isRejected;

  const navBaseClasses = "fixed top-0 inset-x-0 z-50 transition-all duration-300 border-b";
  const navScrolledClasses = "bg-white/80 backdrop-blur-md border-slate-200 shadow-sm py-3";
  const navUnscrolledClasses = isAuthPage ? "bg-white border-slate-200 py-4 shadow-sm" : "bg-transparent border-transparent py-5";

  return (
    <nav className={`${navBaseClasses} ${isScrolled ? navScrolledClasses : navUnscrolledClasses}`}>
      <div className="max-w-7xl mx-auto px-4 md:px-8 flex items-center justify-between">
        {/* Logo */}
        <Link href="/" className="flex items-center gap-2 group">
          <Image 
            src="/assets/TDR-logo.svg" 
            alt="TDR HPZ Logo" 
            width={120} 
            height={40} 
            className="w-auto h-8 md:h-10 group-hover:scale-105 transition-transform"
            priority
          />
        </Link>

        {/* Desktop Menu */}
        {!isAuthPage && (
          <div className="hidden md:flex items-center gap-8">
            <div className={`flex items-center gap-6 text-sm font-semibold transition-colors ${isScrolled ? 'text-slate-600' : 'text-slate-700'}`}>
            <Link href="/" prefetch={true} scroll={false} className="hover:text-amber-500 transition-colors">Beranda</Link>
            {user && (
              <Link href="/shop" prefetch={true} scroll={false} className="hover:text-amber-500 transition-colors">Katalog Produk</Link>
            )}
            {user && user.role !== 'admin' && (
              <Link href="/orders" prefetch={false} scroll={false} className="hover:text-amber-500 transition-colors">Histori</Link>
            )}
            {isStandardUser && (
              <Link href="/affiliate/register" prefetch={true} scroll={false} onClick={handleJadiAffiliateClick} className="hover:text-amber-500 transition-colors cursor-pointer">Jadi Affiliate</Link>
            )}
            {isPending && (
              <Link href="/affiliate/pending" prefetch={false} scroll={false} className="hover:text-amber-500 transition-colors">Jadi Affiliate</Link>
            )}
            {isAffiliate && (
              <Link href="/affiliate/dashboard" prefetch={false} scroll={false} className="hover:text-amber-500 transition-colors">Dashboard Affiliate</Link>
            )}
            {isRejected && (
              <Link href="/affiliate/rejected" prefetch={false} scroll={false} className="hover:text-amber-500 transition-colors">Daftar Affiliate</Link>
            )}
            {user && user.role === 'admin' && (
              <Link href="/admin/dashboard" prefetch={true} scroll={false} className="hover:text-amber-500 transition-colors">Admin Panel</Link>
            )}
          </div>

          <div className="flex items-center gap-4">
            {user ? (
              <>
                {user.role !== 'admin' && (
                  <Link href="/cart" prefetch={true} scroll={false} className={`relative p-2 rounded-full hover:bg-slate-100 transition-colors ${isScrolled ? 'text-slate-700' : 'text-slate-800'}`}>
                    <ShoppingCart className="w-5 h-5" />
                    {cartCount > 0 && (
                      <span className="absolute top-0 right-0 w-4 h-4 bg-red-500 text-white text-[10px] font-bold flex items-center justify-center rounded-full border-2 border-white">
                        {cartCount}
                      </span>
                    )}
                  </Link>
                )}
                <div className="flex items-center gap-3">
                  <div className={`flex items-center gap-2 text-sm font-semibold ${isScrolled ? 'text-slate-700' : 'text-slate-800'}`}>
                    <UserIcon className="w-4 h-4" /> {user.name}
                  </div>
                  <Button variant="ghost" size="sm" onClick={handleLogout} className="text-slate-500 hover:text-red-500 hover:bg-red-50">
                    <LogOut className="w-4 h-4" />
                  </Button>
                </div>
              </>
            ) : (
              <>
                <Button variant="ghost" className={`font-semibold hover:bg-slate-100 ${isScrolled ? 'text-slate-700' : 'text-slate-800'}`} onClick={() => router.push('/login')}>
                  Masuk
                </Button>
                <Button className="font-bold bg-amber-500 hover:bg-amber-400 text-slate-900 rounded-full shadow-md px-6" onClick={() => router.push('/register')}>
                  Daftar
                </Button>
              </>
            )}
          </div>
        </div>
        )}

        {/* Mobile Menu Toggle */}
        {!isAuthPage && (
          <button 
          title='menu'
            className="md:hidden p-2 text-slate-800 bg-white/50 backdrop-blur rounded-lg"
            onClick={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
          >
            <Menu className="w-6 h-6" />
          </button>
        )}
      </div>

      {/* Mobile Menu Wrapper */}
      {!isAuthPage && isMobileMenuOpen && (
        <div className="md:hidden absolute top-full left-0 w-full bg-white border-b border-slate-200 shadow-xl py-4 px-4 flex flex-col gap-4">
          <Link href="/" prefetch={true} scroll={false} onClick={() => setIsMobileMenuOpen(false)} className="text-slate-700 font-semibold px-2 py-1">Beranda</Link>
          {user && <Link href="/shop" prefetch={true} scroll={false} onClick={() => setIsMobileMenuOpen(false)} className="text-slate-700 font-semibold px-2 py-1">Katalog Produk</Link>}
          {user && user.role !== 'admin' && <Link href="/orders" prefetch={false} scroll={false} onClick={() => setIsMobileMenuOpen(false)} className="text-slate-700 font-semibold px-2 py-1">Histori</Link>}
          {isStandardUser && <Link href="/affiliate/register" prefetch={true} scroll={false} onClick={handleJadiAffiliateClick} className="text-slate-700 font-semibold px-2 py-1 cursor-pointer">Jadi Affiliate</Link>}
          {isPending && <Link href="/affiliate/pending" prefetch={false} scroll={false} onClick={() => setIsMobileMenuOpen(false)} className="text-slate-700 font-semibold px-2 py-1">Jadi Affiliate</Link>}
          {isAffiliate && <Link href="/affiliate/dashboard" prefetch={false} scroll={false} onClick={() => setIsMobileMenuOpen(false)} className="text-slate-700 font-semibold px-2 py-1">Dashboard Affiliate</Link>}
          {isRejected && <Link href="/affiliate/rejected" prefetch={false} scroll={false} onClick={() => setIsMobileMenuOpen(false)} className="text-slate-700 font-semibold px-2 py-1">Daftar Affiliate</Link>}
          {user && user.role === 'admin' && <Link href="/admin/dashboard" prefetch={true} scroll={false} onClick={() => setIsMobileMenuOpen(false)} className="text-slate-700 font-semibold px-2 py-1">Admin Panel</Link>}
          
          <hr className="border-slate-100 my-2" />
          
          {user ? (
            <div className="flex items-center justify-between px-2">
              <span className="text-sm font-semibold text-slate-800 flex items-center gap-2"><UserIcon className="w-4 h-4"/> {user.name}</span>
              <Button size="sm" variant="destructive" onClick={handleLogout}>Logout</Button>
            </div>
          ) : (
            <div className="flex gap-2">
              <Button variant="outline" className="w-full" onClick={() => router.push('/login')}>Masuk</Button>
              <Button className="w-full bg-amber-500 text-slate-900" onClick={() => router.push('/register')}>Daftar</Button>
            </div>
          )}
        </div>
      )}
    </nav>
  );
}
