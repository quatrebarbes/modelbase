<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory, SoftDeletes, Searchable;

    protected $fillable = ['category_id', 'name', 'sku', 'price_cents', 'description'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
