<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    /** @use HasFactory<\Database\Factories\ReviewFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'rating', 'body'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
