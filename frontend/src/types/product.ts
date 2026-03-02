// ============================================================
// Types: Product
// sesuai dengan API response GET /api/products
// ============================================================

export interface Product {
  id: number;
  name: string;
  slug: string;
  brand: string;
  type: string;
  category: 'motor' | 'shockbreaker';
  description: string | null;
  technicalSpecs: string | null;
  price: number;
  stock: number;
  masterVideoUrl: string | null;
  thumbnailUrl: string | null;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
}

export interface ProductListResponse {
  data: Product[];
}
