<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
    protected $fillable = ['category_id', 'name', 'description', 'price', 'image', 'is_active'];

    /** Quantity read from the Warehouse. Null means not loaded or unavailable. */
    private ?int $warehouseStock = null;

    public function withStockLevel(?int $quantity): static
    {
        $this->warehouseStock = $quantity;

        return $this;
    }

    /**
     * The Warehouse owns stock. This hides the old `stock` column everywhere,
     * including JSON, so its stale value can't be read by accident.
     */
    protected function stock(): Attribute
    {
        return Attribute::get(fn () => $this->warehouseStock);
    }

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
