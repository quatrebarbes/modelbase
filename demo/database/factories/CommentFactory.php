<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commentable_type' => Product::class,
            'commentable_id' => Product::factory(),
            'author' => $this->faker->name(),
            'body' => $this->faker->sentence(),
        ];
    }
}
