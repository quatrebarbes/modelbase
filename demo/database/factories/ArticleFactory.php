<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\Author;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'author_id' => Author::factory(),
            'title' => ucfirst($this->faker->sentence(4)),
            'published_at' => $this->faker->optional(0.7)->dateTimeBetween('-1 year'),
            'metadata' => [
                'tags' => $this->faker->words(3),
                'reading_minutes' => $this->faker->numberBetween(2, 15),
            ],
        ];
    }
}
