<?php

namespace Modules\Products\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'stock' => $this->stock,
            'is_active' => (bool) $this->is_active,
            'image' => $this->image,
            'image_url' => $this->publicImageUrl(),
            'category' => $this->whenLoaded('category'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}