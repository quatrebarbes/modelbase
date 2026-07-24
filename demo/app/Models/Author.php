<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Author extends Model
{
    /** @use HasFactory<\Database\Factories\AuthorFactory> */
    use HasFactory;

    protected $connection = 'pgsql';

    protected $fillable = ['name', 'email'];

    public function articles()
    {
        return $this->hasMany(Article::class);
    }
}
