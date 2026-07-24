<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => ucfirst($this->faker->words(2, true)),
            'sku' => strtoupper($this->faker->unique()->bothify('???-#####')),
            'price_cents' => $this->faker->numberBetween(500, 50000),
            'description' => $this->faker->optional()->sentence(),
        ];
    }
}
