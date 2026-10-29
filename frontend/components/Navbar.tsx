'use client';

import { apiPost } from '@/src/lib/api';
import { logout, resendEmailVerification } from '@/src/lib/auth';
import { useUserStore } from '@/src/stores/useUserStore';
import { Column, NavIcon, Option, Row, UserMenu } from '@once-ui-system/core';
import { LayoutProvider } from '@once-ui-system/core/next';
import { ShoppingCart } from 'lucide-react';
import Image from 'next/image';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import './Navbar.scss';

export function Navbar() {
  const pathname = usePathname();
  const isAuthPage = pathname === '/login' || pathname === '/register';
  const { user, authStatus, authError, isLoading, fetchUser } = useUserStore();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [accountEpoch, setAccountEpoch] = useState(0);
  const [logoutBusy, setLogoutBusy] = useState(false);
  const [logoutError, setLogoutError] = useState('');
  const [cartCount, setCartCount] = useState(0);
  const [headerOffset, setHeaderOffset] = useState(8);

  const [isResendingVerification, setIsResendingVerification] = useState(false);
  const mobileTrigger = useRef<HTMLDivElement>(null);
  const accountTrigger = useRef<HTMLDivElement>(null);
  const restoreFocus = useRef<'account' | 'mobile' | null>(null);
  const header = useRef<HTMLElement>(null);
  const banner = useRef<HTMLDivElement>(null);
  const logoutPending = useRef(false);
  const authenticated = authStatus === 'authenticated' && user !== null;
  const superadmin = authenticated && user.role === 'superadmin';
  const profile = authenticated ? user.affiliate_profile : undefined;
  const activeAffiliate = authenticated && user.role === 'affiliate' && profile?.status === 'active';

  const closeMenus = () => {
    if (mobileOpen) restoreFocus.current = 'mobile';
    else if (accountTrigger.current?.getAttribute('aria-expanded') === 'true') restoreFocus.current = 'account';
    setMobileOpen(false);
    setAccountEpoch(value => value + 1);
  };

  useEffect(() => {
    const target = restoreFocus.current;
    restoreFocus.current = null;
    if (!target) return;
    const preferred = target === 'account' ? accountTrigger.current : mobileTrigger.current;
    const fallback = accountTrigger.current ?? header.current?.querySelector<HTMLAnchorElement>('.tdr-logo');
    const visible = (element: HTMLElement | null | undefined) => element && element.getClientRects().length > 0;
    if (visible(preferred)) preferred?.focus();
    else if (visible(fallback)) fallback?.focus();
    else header.current?.querySelector<HTMLAnchorElement>('.tdr-logo')?.focus();
  }, [accountEpoch, mobileOpen]);

  useEffect(() => {
    void fetchUser();
    const refresh = () => { if (!document.hidden) void fetchUser(); };
    const interval = window.setInterval(refresh, 30_000);
    document.addEventListener('visibilitychange', refresh);
    return () => {
      window.clearInterval(interval);
      document.removeEventListener('visibilitychange', refresh);
    };
  }, [fetchUser]);

  useEffect(() => {
    const updateCartCount = () => {
      let total = 0;
      try {
        const cart: unknown = JSON.parse(localStorage.getItem('tdr_cart') || '[]');
        if (Array.isArray(cart)) {
          for (const item of cart) {
            if (!item || typeof item !== 'object') continue;
            const raw = item.quantity ?? 1;
            const quantity = typeof raw === 'number' || typeof raw === 'string' ? Number(raw) : NaN;
            if (Number.isFinite(quantity) && quantity >= 0 && Number.isFinite(total + quantity)) total += quantity;
          }
        }
      } catch { /* Unreadable cart is displayed as empty, never rewritten. */ }
      setCartCount(total);
    };
    updateCartCount();
    window.addEventListener('cart_updated', updateCartCount);
    window.addEventListener('storage', updateCartCount);
    return () => {
      window.removeEventListener('cart_updated', updateCartCount);
      window.removeEventListener('storage', updateCartCount);
    };
  }, []);

  useEffect(() => {
    const resize = () => {
      const focused = document.activeElement;
      if (focused?.closest('.tdr-account-overlay') || focused === accountTrigger.current) restoreFocus.current = 'account';
      else if (focused?.closest('#tdr-mobile-panel') || focused === mobileTrigger.current) restoreFocus.current = 'mobile';
      setMobileOpen(false);
      // UserMenu owns open state; a new instance closes its internal portal and ScrollLock.
      setAccountEpoch(value => value + 1);
    };
    window.addEventListener('resize', resize);
    return () => window.removeEventListener('resize', resize);
  }, []);

  useEffect(() => {
    if (!mobileOpen) return;
    const outside = (event: PointerEvent) => {
      if (event.target instanceof Node && !header.current?.contains(event.target)) setMobileOpen(false);
    };
    document.addEventListener('pointerdown', outside);
    return () => document.removeEventListener('pointerdown', outside);
  }, [mobileOpen]);

  useEffect(() => {
    const element = header.current;
    if (!element) return;
    const update = () => {
      const bannerHeight = banner.current?.getBoundingClientRect().height ?? 0;
      element.style.setProperty('--tdr-banner-height', `${bannerHeight}px`);

      // Existing callers already reserve 64px; reserve only the measured difference.
      setHeaderOffset(Math.max(0, element.getBoundingClientRect().height + bannerHeight - 64));
    };
    update();
    const observer = new ResizeObserver(update);
    observer.observe(element);
    if (banner.current) observer.observe(banner.current);
    return () => observer.disconnect();
  }, [authenticated, user?.email_verified]);

  useEffect(() => {
    if (!mobileOpen) return;
    const link = document.getElementById('tdr-mobile-panel')?.querySelector('a');
    link?.focus();
  }, [mobileOpen]);

  const handleLogout = async () => {
    if (logoutPending.current) return;
    logoutPending.current = true;
    setLogoutBusy(true);
    setLogoutError('');
    try {
      await logout();
      toast.success('Berhasil logout.');
      window.location.href = '/login';
    } catch (error: unknown) {
      setLogoutError(error instanceof Error ? error.message : 'Keluar gagal. Silakan coba lagi.');
      toast.error('Belum berhasil keluar. Silakan coba lagi.');
    } finally {
      logoutPending.current = false;
      setLogoutBusy(false);
    }
  };

  const handleOpenAdminPanel = async () => {
    closeMenus();
    try {
      const sso = await apiPost<{ url: string }>('/admin/filament-sso', {});
      window.location.href = sso.url;
    } catch {
      window.location.href = process.env.NEXT_PUBLIC_FILAMENT_ADMIN_URL ?? 'http://localhost:8000/admin';
    }
  };

  const handleResendVerification = async () => {
    setIsResendingVerification(true);
    try {
      toast.success(await resendEmailVerification());
    } catch (error: unknown) {
      toast.error(error instanceof Error ? error.message : 'Email verifikasi gagal dikirim.');
    } finally {
      setIsResendingVerification(false);
    }
  };

  const navItems = [{ label: 'Beranda', href: '/' }, { label: 'Katalog Produk', href: '/shop' }];
  if (authenticated && !superadmin) {
    if (profile === null && user.role === 'customer') navItems.push({ label: 'Jadi Affiliate', href: '/affiliate/register' });
    else if (profile && ['pending', 'rejected', 'inactive'].includes(profile.status)) navItems.push({ label: 'Status Affiliate', href: `/affiliate/${profile.status}` });
  }
  const isActive = (href: string) => href === '/' ? pathname === '/' :
    href === '/shop' ? pathname === '/shop' || pathname.startsWith('/products/') :
      pathname === href || pathname.startsWith(`${href}/`);
  const navigation = () => navItems.map(item => (
    <Link key={item.href} href={item.href} prefetch={false} onClick={closeMenus}
      aria-current={isActive(item.href) ? 'page' : undefined}>{item.label}</Link>
  ));
  const maskedEmail = authenticated ? user.email : '';
  const initials = authenticated ? user.name.trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase() : '';
  const accountLink = (label: string, href: string) => (
    <Option key={href} label={label} value={href} href={href} onLinkClick={closeMenus}
      selected={isActive(href)} aria-current={isActive(href) ? 'page' : undefined} />
  );

  return (
    <LayoutProvider>
      <header ref={header} className="tdr-header" onKeyDown={event => {
        if (event.key === 'Escape' && mobileOpen) {
          setMobileOpen(false);
          mobileTrigger.current?.focus();
        }
      }}>
        <Row className="tdr-header-row">
          <Row className="tdr-header-left">
            {!isAuthPage && <NavIcon ref={mobileTrigger} className="tdr-mobile-toggle" active={mobileOpen}
              style={{ width: 44, height: 44, minWidth: 44, minHeight: 44, flexShrink: 0 }}
              role="button" tabIndex={0} aria-label="Menu navigasi" aria-expanded={mobileOpen} aria-controls="tdr-mobile-panel"
              onKeyDown={event => {
                if (event.key === 'Enter' || event.key === ' ') {
                  event.preventDefault();
                  setAccountEpoch(value => value + 1);
                  setMobileOpen(value => !value);
                }
              }}
              onClick={() => { setAccountEpoch(value => value + 1); setMobileOpen(value => !value); }} />}
            <Link href="/" className="tdr-logo" aria-label="TDR Beranda" onClick={closeMenus}>
              <Image src="/assets/TDR-logo.svg" alt="TDR HPZ Logo" width={120} height={40} priority />
            </Link>
            {!isAuthPage && <nav className="tdr-desktop-nav" aria-label="Navigasi utama">{navigation()}</nav>}
          </Row>
          {!isAuthPage && <Row className="tdr-header-right">
            {!superadmin && <Link href="/cart" className="tdr-cart" prefetch={false} onClick={closeMenus}
              aria-label={cartCount > 0 ? `Keranjang, ${cartCount} barang` : 'Keranjang'} aria-current={isActive('/cart') ? 'page' : undefined}>
              <ShoppingCart aria-hidden="true" />
              {cartCount > 0 && <span className="tdr-cart-badge" aria-hidden="true">{cartCount}</span>}
            </Link>}
            {authenticated ? <div className="tdr-account" onClickCapture={() => setMobileOpen(false)}
              onKeyDownCapture={event => { if (event.key === 'Enter' || event.key === ' ') setMobileOpen(false); }}>
              <UserMenu ref={accountTrigger} key={`${user.id}:${accountEpoch}:${pathname}`} className="tdr-account-trigger" placement="bottom-end"
                aria-label={`Menu akun ${user.name}`} name={user.name} avatarProps={{ value: initials }}
                dropdown={<Column className="tdr-account-overlay">
                
                  <div className="tdr-account-identity"><strong>{user.name}</strong><span>{maskedEmail}</span></div>
                  {accountLink('Profil Saya', '/profile')}
                  {!superadmin && accountLink('Histori Pesanan', '/orders')}
                  {activeAffiliate && accountLink('Dashboard Affiliate', '/affiliate/dashboard')}
                  {superadmin && user.email_verified && <Option label="Admin Panel" value="admin" onClick={handleOpenAdminPanel} />}
                  <hr />
                  <Option label={logoutBusy ? 'Keluar...' : 'Keluar'} value="logout" disabled={logoutBusy} aria-busy={logoutBusy} onClick={handleLogout} />
                </Column>} />
            </div> : authStatus === 'guest' ? <div className="tdr-guest">
              <Link href="/login" onClick={closeMenus}>Masuk</Link><Link href="/register" className="tdr-register" onClick={closeMenus}>Daftar</Link>
            </div> : <div className="tdr-session" aria-live="polite" aria-busy={isLoading}>
              {isLoading ? 'Memuat akun...' : <button type="button" onClick={() => void fetchUser()}>Muat ulang akun</button>}
            </div>}
          </Row>}
        </Row>
        {logoutError && !isAuthPage && <div className="tdr-logout-error" role="alert">Belum berhasil keluar. <button type="button" onClick={handleLogout} disabled={logoutBusy}>Coba lagi</button></div>}
        {authenticated && authError && !isAuthPage && !isLoading && <div className="tdr-relation-error" role="status">
          {authError} <button type="button" onClick={() => void fetchUser()}>Muat ulang</button>
        </div>}
        {authenticated && !user.email_verified && !isAuthPage && <div ref={banner} className="tdr-verification-banner">
          <span>Email Anda belum diverifikasi. Fitur transaksi belum dapat digunakan.</span>{' '}
          <button type="button" onClick={handleResendVerification} disabled={isResendingVerification}
            className="min-h-11 px-2 font-bold text-amber-800 underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-700 disabled:opacity-60">
            {isResendingVerification ? 'Mengirim...' : 'Kirim ulang verifikasi'}
          </button>
        </div>}
        {!isAuthPage && mobileOpen && <Column id="tdr-mobile-panel" className="tdr-mobile-panel">
          <nav aria-label="Navigasi HP">{navigation()}</nav>
        </Column>}
      </header>
      <div aria-hidden="true" style={{ height: headerOffset, flexShrink: 0 }} />
    </LayoutProvider>
  );
}
