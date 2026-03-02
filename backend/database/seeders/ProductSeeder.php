<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
  public function run(): void {
    $products = [
      ['name' => 'Rantai TDR 428H Standard - Honda Beat', 'brand' => 'TDR', 'category' => 'motor', 'type' => 'Standard', 'price' => 150000],
      ['name' => 'Rantai TDR 428H Standard - Honda Vario 125', 'brand' => 'TDR', 'category' => 'motor', 'type' => 'Standard', 'price' => 155000],
      ['name' => 'Rantai TDR 428H Gold - Yamaha MIO', 'brand' => 'TDR', 'category' => 'motor', 'type' => 'Gold', 'price' => 195000],
      ['name' => 'Rantai TDR 520H Racing - Yamaha NMAX', 'brand' => 'TDR', 'category' => 'motor', 'type' => 'Racing', 'price' => 275000],
      ['name' => 'Kampas Rem TDR Racing Depan - Honda PCX', 'brand' => 'TDR', 'category' => 'motor', 'type' => 'Racing', 'price' => 85000],
      ['name' => 'Kampas Rem TDR Racing Belakang - Honda PCX', 'brand' => 'TDR', 'category' => 'motor', 'type' => 'Racing', 'price' => 75000],
      ['name' => 'Filter Udara TDR Power - Universal', 'brand' => 'TDR', 'category' => 'motor', 'type' => 'Power', 'price' => 125000],
      ['name' => 'Filter Udara TDR Racing - Yamaha Aerox', 'brand' => 'TDR', 'category' => 'motor', 'type' => 'Racing', 'price' => 145000],
      ['name' => 'Knalpot TDR Full System - Honda Scoopy', 'brand' => 'TDR', 'category' => 'motor', 'type' => 'Full System', 'price' => 650000],
      ['name' => 'Knalpot TDR Racing - Yamaha Fino', 'brand' => 'TDR', 'category' => 'motor', 'type' => 'Racing', 'price' => 450000],

      ['name' => 'Shockbreaker TDR YSS Racing Belakang - Honda Beat', 'brand' => 'TDR', 'category' => 'shockbreaker', 'type' => 'Racing', 'price' => 345000],
      ['name' => 'Shockbreaker TDR YSS Racing Belakang - Honda Vario 125', 'brand' => 'TDR', 'category' => 'shockbreaker', 'type' => 'Racing', 'price' => 365000],
      ['name' => 'Shockbreaker TDR Street Performance - Honda Scoopy', 'brand' => 'TDR', 'category' => 'shockbreaker', 'type' => 'Street', 'price' => 280000],
      ['name' => 'Shockbreaker TDR Street Performance - Yamaha MIO', 'brand' => 'TDR', 'category' => 'shockbreaker', 'type' => 'Street', 'price' => 275000],
      ['name' => 'Shockbreaker TDR Touring Depan - Honda PCX 160', 'brand' => 'TDR', 'category' => 'shockbreaker', 'type' => 'Touring', 'price' => 495000],

      ['name' => 'Shockbreaker RPD Touring - Honda Beat', 'brand' => 'RPD', 'category' => 'shockbreaker', 'type' => 'Touring', 'price' => 320000],
      ['name' => 'Shockbreaker RPD Touring - Honda Vario 150', 'brand' => 'RPD', 'category' => 'shockbreaker', 'type' => 'Touring', 'price' => 335000],
      ['name' => 'Shockbreaker RPD Sport - Yamaha NMAX', 'brand' => 'RPD', 'category' => 'shockbreaker', 'type' => 'Sport', 'price' => 485000],
      ['name' => 'Shockbreaker RPD Sport - Yamaha Aerox 155', 'brand' => 'RPD', 'category' => 'shockbreaker', 'type' => 'Sport', 'price' => 495000],
      ['name' => 'Shockbreaker RPD Comfort Plus - Universal', 'brand' => 'RPD', 'category' => 'shockbreaker', 'type' => 'Comfort', 'price' => 385000],
      ['name' => 'Shockbreaker RPD Comfort Plus - Honda Scoopy', 'brand' => 'RPD', 'category' => 'shockbreaker', 'type' => 'Comfort', 'price' => 370000],
      ['name' => 'Shockbreaker RPD Comfort Plus - Honda PCX 160', 'brand' => 'RPD', 'category' => 'shockbreaker', 'type' => 'Comfort', 'price' => 420000],

      ['name' => 'Shockbreaker TDR YSS Racing Belakang - Yamaha Aerox', 'brand' => 'TDR', 'category' => 'shockbreaker', 'type' => 'Racing', 'price' => 390000],
      ['name' => 'Shockbreaker TDR YSS Racing Belakang - Honda ADV', 'brand' => 'TDR', 'category' => 'shockbreaker', 'type' => 'Racing', 'price' => 525000],
      ['name' => 'Rantai TDR 428H Gold - Yamaha NMAX', 'brand' => 'TDR', 'category' => 'motor', 'type' => 'Gold', 'price' => 215000],
    ];

    foreach ($products as $data) {
      $slug = Str::slug($data['name']);
      Product::firstOrCreate(
        ['slug' => $slug],
        array_merge($data, [
          'slug'          => $slug,
          'description'   => 'Produk berkualitas tinggi dari brand ' . $data['brand'] . '. Cocok untuk berbagai jenis motor.',
          'stock'         => rand(5, 50),
          'is_active'     => true,
          'thumbnail_url' => 'https://via.placeholder.com/400x400?text=' . urlencode($data['brand']),
        ])
      );
    }

    $this->command->info(count($products) . ' products seeded.');
  }
}
