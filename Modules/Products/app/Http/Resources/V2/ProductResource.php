<?php

namespace Modules\Products\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Products API v2 representation.
 *
 * Differences from v1:
 * - adds a computed `in_stock` flag
 * - exposes `category` as a compact {id, name} object (when loaded)
 * - drops the raw `image` column in favour of `image_url` only
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => (string) $this->price,
            'stock' => $this->stock,
            'in_stock' => $this->stock > 0,
            'is_active' => (bool) $this->is_active,
            'image_url' => $this->publicImageUrl(),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}