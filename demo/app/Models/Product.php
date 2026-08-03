<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
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

    // Relation belongsToMany de démo (Phase 9bis, EX-307/EX-310).
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    // Relation polymorphique morphMany de démo (Phase 9bis, EX-307/EX-310).
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    // Relation polymorphique morphOne de démo (Phase 9bis, EX-307/EX-310).
    public function thumbnail(): MorphOne
    {
        return $this->morphOne(Thumbnail::class, 'imageable');
    }
}
