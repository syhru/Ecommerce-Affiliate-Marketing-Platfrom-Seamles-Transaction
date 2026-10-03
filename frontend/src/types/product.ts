// ============================================================
// Types: Product
// Cerminan wire-format ProductResource (snake_case).
//
// ProductResource tidak mengemitted `updated_at`, sehingga field
// tersebut sengaja tidak dideklarasikan di sini.
// ============================================================

export interface Product {
  id: number;
  name: string;
  slug: string;
  brand: string;
  type: string;
  category: 'motor' | 'shockbreaker';
  description: string | null;
  technical_specs: string | null;
  price: number;
  stock: number;
  thumbnail_url: string | null;
  master_video_url: string | null;
  is_active: boolean;
  created_at: string | null;
}
