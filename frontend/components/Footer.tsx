import { MapPin, MessageCircle, Shield } from "lucide-react";
import Image from "next/image";
import Link from "next/link";

export function Footer() {
  return (
    <footer className="bg-slate-900 text-slate-400 py-16 border-t border-slate-800">
      <div className="max-w-7xl mx-auto px-4 md:px-8">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-12 mb-12">
          
          {/* Brand & Tentang */}
          <div>
            <div className="flex items-center gap-2 mb-6">
              <Image 
                src="/assets/TDR-logo.svg" 
                alt="TDR HPZ Logo" 
                width={120} 
                height={40} 
                className="w-auto h-8 opacity-90"
              />
            </div>
            <p className="text-sm leading-relaxed mb-6 font-medium text-slate-500">
              Distributor resmi spare part balap berkualitas tinggi. Harga kompetitif dengan pengiriman cepat dan aman ke seluruh Indonesia.
            </p>
          </div>

          {/* Navigasi */}
          <div>
            <h4 className="text-white font-bold mb-6 tracking-wide uppercase text-sm">Navigasi</h4>
            <ul className="space-y-3 text-sm font-medium">
              <li><Link href="/" className="hover:text-amber-500 transition-colors">Beranda</Link></li>
              <li><Link href="/shop" className="hover:text-amber-500 transition-colors">Katalog Produk</Link></li>
              <li><Link href="/login" className="hover:text-amber-500 transition-colors">Masuk Akun</Link></li>
              <li><Link href="/login" className="hover:text-amber-500 transition-colors">Daftar Member</Link></li>
            </ul>
          </div>

          {/* Layanan */}
          <div>
            <h4 className="text-white font-bold mb-6 tracking-wide uppercase text-sm">Layanan</h4>
            <ul className="space-y-3 text-sm font-medium">
              <li><Link href="/orders" className="hover:text-amber-500 transition-colors">Lacak Pesanan</Link></li>
              <li><Link href="/affiliate/register" className="hover:text-amber-500 transition-colors">Program Affiliate</Link></li>
              <li><Link href="/telegram/setup" className="hover:text-amber-500 transition-colors">Notifikasi Telegram</Link></li>
            </ul>
          </div>

          {/* Kontak & Features */}
          <div>
            <h4 className="text-white font-bold mb-6 tracking-wide uppercase text-sm">Info & Keamanan</h4>
            <ul className="space-y-4 text-sm font-medium">
              <li className="flex items-start gap-3">
                <MapPin className="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
                <span>Jakarta, Indonesia. Pengiriman nasional bersertifikasi.</span>
              </li>
              <li className="flex items-start gap-3">
                <MessageCircle className="w-5 h-5 text-blue-400 shrink-0 mt-0.5" />
                <span>Dukungan pelanggan responsif & Bot Telegram.</span>
              </li>
              <li className="flex items-start gap-3">
                <Shield className="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" />
                <span>Transaksi 100% aman berkat teknologi enkripsi Midtrans.</span>
              </li>
            </ul>
          </div>

        </div>

        <div className="pt-8 border-t border-slate-800 text-center text-sm font-medium flex flex-col md:flex-row justify-between items-center gap-4">
          <p>© {new Date().getFullYear()} TDR High Performance Zone. All rights reserved.</p>
          <div className="flex items-center gap-4 opacity-50">
            <span>Seamless Transaction System</span>
          </div>
        </div>
      </div>
    </footer>
  );
}
