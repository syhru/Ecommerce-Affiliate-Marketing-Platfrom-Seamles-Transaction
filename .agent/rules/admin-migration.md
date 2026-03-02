---
trigger: manual
---

# Admin Migration Guide (Blade to Filament)

## Migration Steps

1. Identifikasi fitur CRUD pada Admin Blade yang lama.
2. Buat Filament Resource di `app/Filament/Resources` untuk setiap model terkait.
3. Sesuaikan Form Schema agar cocok dengan kolom database (Table: products, orders, users, etc.).
4. Implementasikan Table Actions (Edit, Delete, View) sesuai fitur lama.

## Rules

- Gunakan `Filament v3` standard components.
- Pastikan relasi antar tabel (seperti Product belongsTo Category) terimplementasi di Form & Table.
- Jangan menghapus Controller Blade lama sebelum fitur di Filament dipastikan 100% identik secara fungsi.
