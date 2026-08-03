<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Category extends Model
{
    /** @use HasFactory<\Database\Factories\CategoryFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    // Deuxième relation de démo, pour illustrer le système d'onglets du
    // plug-in (module 4, Phase 9) dès qu'un modèle en déclare plusieurs.
    public function latestProduct(): HasOne
    {
        return $this->hasOne(Product::class)->latestOfMany();
    }

    // Relation hasManyThrough de démo (Phase 9bis, EX-307/EX-310) : les avis
    // des produits de cette catégorie, sans modèle intermédiaire déclaré.
    public function reviews(): HasManyThrough
    {
        return $this->hasManyThrough(Review::class, Product::class);
    }

    // Relation polymorphique morphMany de démo (Phase 9bis, EX-307/EX-310) :
    // les commentaires ciblent indifféremment une Category ou un Product.
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
