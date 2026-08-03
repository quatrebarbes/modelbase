<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Review;
use App\Models\Tag;
use App\Models\Thumbnail;
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

        // Phase 9bis (EX-307/EX-310) : types de relation jusque-là non
        // couverts par la démo (belongsToMany, hasManyThrough, morphMany,
        // morphOne), nécessaires pour vérifier visuellement une disposition
        // en étoile à plusieurs branches sur le diagramme de relations.
        $tags = Tag::factory(5)->create();

        Product::all()->each(function (Product $product) use ($tags) {
            $product->tags()->attach($tags->random(2));
            Review::factory(2)->for($product)->create();
            Comment::factory(2)->create([
                'commentable_type' => Product::class,
                'commentable_id' => $product->id,
            ]);
            Thumbnail::factory()->create([
                'imageable_type' => Product::class,
                'imageable_id' => $product->id,
            ]);
        });

        Category::all()->each(function (Category $category) {
            Comment::factory(1)->create([
                'commentable_type' => Category::class,
                'commentable_id' => $category->id,
            ]);
        });
    }
}
