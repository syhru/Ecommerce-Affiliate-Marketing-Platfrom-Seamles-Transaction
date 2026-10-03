// ============================================================
// Formatting helpers — satu pemilik untuk format Rupiah / tanggal.
//
// Wire format Laravel nullable: `formatRupiah` menerima `number | string | null`
// karena kolom numerik bisa diserialisasi sebagai string di beberapa driver,
// dan field seperti `created_at` nullable.
// ============================================================

export const formatRupiah = (amount: number | string | null | undefined): string => {
  if (amount === null || amount === undefined || amount === '') return 'Rp 0';
  const value = typeof amount === 'string' ? Number(amount) : amount;
  if (Number.isNaN(value)) return 'Rp 0';
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
  }).format(value);
};

export const formatDate = (dateString: string | null | undefined): string => {
  if (!dateString) return '-';
  const date = new Date(dateString);
  if (Number.isNaN(date.getTime())) return '-';
  return (
    date.toLocaleDateString('id-ID', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    }) +
    ' ' +
    date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) +
    ' WIB'
  );
};
