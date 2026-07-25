<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
}
