<?php

namespace Modules\Products\Models;

use App\Services\Warehouse\Data\StockLevel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Products\Services\CatalogCache;

class Product extends Model
{
    use HasFactory;

    /** The Warehouse has stock for the product. */
    public const STOCK_IN_STOCK = 'in_stock';

    /** The Warehouse tracks the product, but its quantity is 0. */
    public const STOCK_OUT_OF_STOCK = 'out_of_stock';

    /** The Warehouse has no stock row for the product yet ("Not stocked yet"). */
    public const STOCK_NOT_STOCKED = 'not_stocked';

    /** The Warehouse could not be asked, so the quantity is unknown. */
    public const STOCK_UNAVAILABLE = 'unavailable';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['category_id', 'name', 'description', 'price', 'image', 'is_active'];

    /** Warehouse values are part of every serialized product, e.g. inside an order. */
    protected $appends = ['stock', 'stock_status'];

    /** Quantity read from the Warehouse. Null when not stocked or unknown. */
    private ?int $warehouseStock = null;

    private string $stockStatus = self::STOCK_UNAVAILABLE;

    /** Attaches the Warehouse's answer. Null means the Warehouse could not be asked. */
    public function withStockLevel(?StockLevel $level): static
    {
        $this->warehouseStock = $level?->tracked ? $level->quantity : null;
        $this->stockStatus = match (true) {
            $level === null => self::STOCK_UNAVAILABLE,
            ! $level->tracked => self::STOCK_NOT_STOCKED,
            $level->quantity > 0 => self::STOCK_IN_STOCK,
            default => self::STOCK_OUT_OF_STOCK,
        };

        return $this;
    }

    /** The Warehouse owns stock; Laravel stores no quantity of its own. */
    protected function stock(): Attribute
    {
        return Attribute::get(fn () => $this->warehouseStock);
    }

    protected function stockStatus(): Attribute
    {
        return Attribute::get(fn () => $this->stockStatus);
    }

    /** Any write makes every cached catalog page and product stale at once. */
    protected static function booted(): void
    {
        static::saved(fn () => app(CatalogCache::class)->flush());
        static::deleted(fn () => app(CatalogCache::class)->flush());
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
}
