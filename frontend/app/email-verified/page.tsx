import Link from 'next/link';

export default function EmailVerifiedPage() {
  return (
    <main className="min-h-screen bg-white px-6 py-24 text-slate-900">
      <div className="mx-auto max-w-lg border-l-4 border-amber-500 pl-6">
        <h1 className="text-2xl font-bold">Email berhasil diverifikasi</h1>
        <p className="mt-3 text-slate-700">
          Akun Anda sudah dapat menggunakan fitur transaksi sesuai peran akun.
        </p>
        <Link
          href="/"
          className="mt-6 inline-flex min-h-11 items-center font-bold text-amber-800 underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-700"
        >
          Kembali ke beranda
        </Link>
      </div>
    </main>
  );
}