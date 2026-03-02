<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    /** GET /api/products */
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::active()
            ->when($request->category, fn ($q, $v) => $q->where('category', $v))
            ->when($request->brand,    fn ($q, $v) => $q->where('brand', 'LIKE', "%{$v}%"))
            ->when($request->type,     fn ($q, $v) => $q->where('type', $v))
            ->when($request->q,        fn ($q, $v) => $q->where('name', 'LIKE', "%{$v}%"))
            ->orderBy(
                in_array($request->sort, ['price', 'name', 'created_at']) ? $request->sort : 'created_at',
                $request->order === 'asc' ? 'asc' : 'desc'
            )
            ->paginate($request->integer('per_page', 12));

        return ProductResource::collection($products);
    }

    /** GET /api/products/{slug} */
    public function show(string $slug): ProductResource
    {
        $product = Product::active()->where('slug', $slug)->firstOrFail();

        return new ProductResource($product);
    }
}
