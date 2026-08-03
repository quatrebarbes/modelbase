<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    /** @use HasFactory<\Database\Factories\CommentFactory> */
    use HasFactory;

    protected $fillable = ['commentable_type', 'commentable_id', 'author', 'body'];

    public function commentable()
    {
        return $this->morphTo();
    }
}
