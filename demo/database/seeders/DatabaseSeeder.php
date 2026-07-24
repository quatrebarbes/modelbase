<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed les données de démo du plug-in : quelques modèles sur la connexion
     * mysql (categories/products) et sur la connexion pgsql (authors/articles),
     * pour disposer de données réelles à parcourir dès la Phase 2.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Jean de Quatrebarbes',
            'email' => 'jean.de.quatrebarbes@hotmail.fr',
        ]);

        Category::factory(4)
            ->has(Product::factory()->count(5))
            ->create();

        Author::factory(3)
            ->has(Article::factory()->count(4))
            ->create();
    }
}
