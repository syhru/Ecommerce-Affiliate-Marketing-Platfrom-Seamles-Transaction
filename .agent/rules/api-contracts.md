---
trigger: always_on
---

# API Integration Contracts

## Known Endpoints

- **Auth:** `POST /api/auth/login` (Expects: email, password | Returns: plainTextToken).
- **Products:** `GET /api/products` (Returns: List of products with price and stock).
- **Orders:** `POST /api/orders` (Expects: product_id, quantity | Requires: Bearer Token).

## Implementation for Next.js

1. Gunakan TypeScript Interface untuk setiap response API.
2. Simpan Token di `localStorage` atau `cookies` setelah login berhasil.
3. Selalu sertakan header `Authorization: Bearer {token}` untuk endpoint yang diproteksi Sanctum.
