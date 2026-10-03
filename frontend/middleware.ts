// ============================================================
// Next.js Middleware — proteksi route yang membutuhkan login
// Berjalan di Edge Runtime, membaca cookie auth_token
//
// Cookie presence adalah HINT, bukan bukti authenticated.
// Guest routes (/login, /register) TIDAK boleh di-redirect hanya
// karena cookie ada — credential stale harus tetap bisa membuka
// halaman login, lalu frontend membersihkan token via GET /user 401.
// Protected routes tetap minta credential sebagai hint menuju /login.
// ============================================================

import { NextRequest, NextResponse } from 'next/server';

const protectedRoutes = ['/dashboard', '/profile', '/orders', '/checkout', '/affiliate'];

export function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const token = request.cookies.get('auth_token')?.value;
  const hasCredential = Boolean(token);

  if (protectedRoutes.some((route) => pathname.startsWith(route))) {
    if (!hasCredential) {
      const loginUrl = new URL('/login', request.url);
      loginUrl.searchParams.set('redirect', pathname);
      return NextResponse.redirect(loginUrl);
    }
  }

  return NextResponse.next();
}

export const config = {
  matcher: [
    '/((?!_next/static|_next/image|favicon.ico|.*\\.(?:svg|png|jpg|jpeg|gif|webp)$).*)',
  ],
};
