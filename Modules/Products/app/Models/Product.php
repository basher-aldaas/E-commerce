<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// use Modules\Products\Database\Factories\ProductFactory;

class Product extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['category_id', 'name', 'description', 'price', 'image', 'stock', 'is_active'];


    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Build an absolute, displayable URL for the product image.
     *
     * - null / empty  -> null
     * - already a URL -> returned unchanged
     * - a stored path -> served from the public storage disk
     */
    public function publicImageUrl(): ?string
    {
        if (empty($this->image)) {
            return null;
        }

        if (preg_match('#^https?://#i', $this->image)) {
            return $this->image;
        }

        return asset('storage/'.ltrim($this->image, '/'));
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
    // protected static function newFactory(): ProductFactory
    // {
    //     // return ProductFactory::new();
    // }
}
