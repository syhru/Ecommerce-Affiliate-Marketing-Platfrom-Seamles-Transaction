<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Inventory safety for order creation (WS-02 / PA-F-01).
 *
 * Stock validation and decrement happen as one atomic, conditional UPDATE, so
 * two concurrent checkouts racing for the last units can never both succeed
 * and drive stock negative:
 *
 *     UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?
 *
 * A product is only considered stockable when its `stock` column is not NULL.
 * A NULL stock is treated as unlimited/managed elsewhere and is skipped, which
 * preserves the pre-existing behaviour of the OrderService decrement.
 */
class ProductInventoryService
{
    /**
     * Reserve stock for a single product by acting as a single conditional
     * UPDATE guarded by the remaining stock.
     *
     * @param  int  $quantity  Quantity to reserve (must be >= 1).
     *
     * @throws InvalidArgumentException When stock is insufficient, including
     *                                  when a real (non-null) stock hits 0.
     */
    public function reserve(Product $product, int $quantity): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Jumlah item minimal 1.');
        }

        // A NULL stock is not tracked here — nothing to reserve against.
        if ($product->stock === null) {
            return;
        }

        // Atomic conditional decrement: only succeeds while enough stock remains.
        // This single statement is what makes concurrent checkouts safe.
        $updated = Product::where('id', $product->id)
            ->where('stock', '>=', $quantity)
            ->update([
                'stock' => DB::raw('stock - '.(int) $quantity),
            ]);

        if ($updated !== 1) {
            $remaining = $product->fresh()->stock;

            throw new InvalidArgumentException(sprintf(
                'Stok produk "%s" tidak mencukupi. Tersisa %d, diminta %d.',
                $product->name,
                $remaining ?? 0,
                $quantity,
            ));
        }

        // Keep the in-memory model in sync with the row we just mutated.
        $product->stock = $product->fresh()->stock;
    }

    /**
     * Return previously reserved stock for a single product.
     *
     * Used when order creation fails after (part of) the stock has been
     * reserved, and by the failed-payment cancellation flow.
     */
    public function release(Product $product, int $quantity): void
    {
        if ($quantity < 1 || $product->stock === null) {
            return;
        }

        Product::where('id', $product->id)->increment('stock', $quantity);
    }
}
