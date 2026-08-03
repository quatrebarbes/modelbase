<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Thumbnail extends Model
{
    /** @use HasFactory<\Database\Factories\ThumbnailFactory> */
    use HasFactory;

    protected $fillable = ['imageable_type', 'imageable_id', 'path'];

    public function imageable()
    {
        return $this->morphTo();
    }
}
