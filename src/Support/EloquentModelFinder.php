<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Découverte des modèles Eloquent déclarés par l'application hôte, par scan
 * du répertoire conventionnel `app/Models` (EX-301 en préparation). Utilisé
 * dès la Phase 2 pour le comptage de modèles par connexion (EX-202/EX-205) ;
 * le listing détaillé par connexion (module 3) s'appuiera sur le même
 * mécanisme de découverte.
 */
class EloquentModelFinder
{
    private const CACHE_KEY = 'modelbase:models:all';

    /**
     * @return array<int, class-string<Model>>
     */
    public function all(): array
    {
        $ttl = (int) config('modelbase.model_discovery_cache_ttl', 60);

        if ($ttl <= 0) {
            return $this->scan();
        }

        return Cache::remember(self::CACHE_KEY, $ttl, fn () => $this->scan());
    }

    /**
     * @return array<int, class-string<Model>>
     */
    private function scan(): array
    {
        // À voir plus tard : le traitement des apps qui stockeraient leurs
        // modèles ailleurs que dans le répertoire ad hoc
        $directory = app_path('Models');

        if (! File::isDirectory($directory)) {
            return [];
        }

        return collect(File::allFiles($directory))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(fn ($file) => $this->classFromPath($file->getPathname(), $directory))
            ->filter(fn (string $class) => $this->isConcreteModel($class))
            ->values()
            ->all();
    }

    /**
     * @return array<int, class-string<Model>>
     */
    public function forConnection(string $connection): array
    {
        return collect($this->all())
            ->filter(fn (string $class) => $this->connectionOf($class) === $connection)
            ->values()
            ->all();
    }

    /**
     * @return class-string<Model>|null
     */
    public function classForTable(string $connection, string $table): ?string
    {
        return collect($this->forConnection($connection))
            ->first(fn (string $class) => (new $class)->getTable() === $table);
    }

    private function classFromPath(string $path, string $directory): string
    {
        $relative = Str::of($path)
            ->after($directory.DIRECTORY_SEPARATOR)
            ->beforeLast('.php')
            ->replace(DIRECTORY_SEPARATOR, '\\');

        return app()->getNamespace().'Models\\'.$relative;
    }

    private function isConcreteModel(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        return $reflection->isSubclassOf(Model::class) && ! $reflection->isAbstract();
    }

    private function connectionOf(string $class): string
    {
        return (new $class)->getConnectionName() ?? config('database.default');
    }
}
