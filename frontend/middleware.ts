// ============================================================
// Next.js Middleware — proteksi route yang membutuhkan login
// Berjalan di Edge Runtime, membaca cookie auth_token
// ============================================================

import { NextRequest, NextResponse } from 'next/server';

// Route yang WAJIB login
const protectedRoutes = ['/dashboard', '/profile', '/orders', '/checkout', '/affiliate'];

// Route yang HANYA bisa diakses user yang BELUM login
const authRoutes = ['/login', '/register'];

export function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const token = request.cookies.get('auth_token')?.value;

  const isAuthenticated = Boolean(token);

  // Redirect ke /login jika akses protected route tapi belum login
  if (protectedRoutes.some((route) => pathname.startsWith(route))) {
    if (!isAuthenticated) {
      const loginUrl = new URL('/login', request.url);
      loginUrl.searchParams.set('redirect', pathname); // simpan tujuan redirect
      return NextResponse.redirect(loginUrl);
    }
  }

  // Redirect ke / jika sudah login tapi akses /login atau /register
  if (authRoutes.some((route) => pathname.startsWith(route))) {
    if (isAuthenticated) {
      return NextResponse.redirect(new URL('/', request.url));
    }
  }

  return NextResponse.next();
}

// Tentukan path mana yang perlu dijalankan middleware-nya
export const config = {
  matcher: [
    /*
     * Match semua path kecuali:
     * - _next/static (static files)
     * - _next/image (image optimization)
     * - favicon.ico
     * - file publik lainnya
     */
    '/((?!_next/static|_next/image|favicon.ico|.*\\.(?:svg|png|jpg|jpeg|gif|webp)$).*)',
  ],
};
