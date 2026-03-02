<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'brand'           => $this->brand,
            'type'            => $this->type,
            'category'        => $this->category,
            'description'     => $this->description,
            'technical_specs' => $this->technical_specs,
            'price'           => (float) $this->price,
            'stock'           => $this->stock,
            'thumbnail_url'   => $this->thumbnail_url,
            'master_video_url'=> $this->master_video_url,
            'is_active'       => $this->is_active,
            'created_at'      => $this->created_at?->toISOString(),
        ];
    }
}
