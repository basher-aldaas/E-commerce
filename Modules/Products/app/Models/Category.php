<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Products\Services\CatalogCache;

// use Modules\Products\Database\Factories\CategoryFactory;

class Category extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['name', 'description'];


    /** Category names appear in product responses, so a write flushes the catalog. */
    protected static function booted(): void
    {
        static::saved(fn () => app(CatalogCache::class)->flush());
        static::deleted(fn () => app(CatalogCache::class)->flush());
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
    // protected static function newFactory(): CategoryFactory
    // {
    //     // return CategoryFactory::new();
    // }
}
