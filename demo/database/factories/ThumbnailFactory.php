<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Thumbnail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Thumbnail>
 */
class ThumbnailFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'imageable_type' => Product::class,
            'imageable_id' => Product::factory(),
            'path' => 'thumbnails/'.$this->faker->uuid().'.jpg',
        ];
    }
}
