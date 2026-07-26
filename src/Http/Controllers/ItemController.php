<?php

namespace Quatrebarbes\Modelbase\Http\Controllers;

use Quatrebarbes\Modelbase\Support\ItemDeletionException;
use Quatrebarbes\Modelbase\Support\ItemFilterException;
use Quatrebarbes\Modelbase\Support\ItemRepository;
use Quatrebarbes\Modelbase\Support\ItemValidationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ItemController extends Controller
{
    private const ITEM_NOT_FOUND = 'Item introuvable.';

    public function __construct(private ItemRepository $items)
    {
    }

    /**
     * EX-401/EX-403/EX-404 : liste paginée des items d'un modèle ; un modèle
     * sans item renvoie un `data` vide (pas une erreur). EX-432/EX-435 :
     * `filter[colonne]=valeur` (répétable) et `sort=colonne,-colonne2`
     * (ordre = priorité, EX-436), restreints aux colonnes exposées par
     * ItemRepository::columnsFor() — une colonne inconnue ou non exposée est
     * rejetée en 422, jamais tentée telle quelle dans une requête SQL.
     * EX-437/EX-438 : `trashed=with`/`trashed=only` étend/restreint le
     * listing aux items soft-deleted (sans effet pour un modèle n'utilisant
     * pas SoftDeletes, EX-443).
     */
    public function index(Request $request, string $connection, string $model)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 15);
        $filters = (array) $request->query('filter', []);
        $sort = $request->query('sort');
        $trashed = $request->query('trashed');

        try {
            $result = $this->items->paginate($connection, $model, $page, max(1, $perPage), $filters, $sort, $trashed);
        } catch (ItemFilterException $exception) {
            return response()->json(['message' => 'Filtre ou tri invalide.', 'errors' => $exception->errors()], 422);
        }

        return response()->json($result);
    }

    /**
     * EX-405/EX-406/EX-407/EX-408/EX-409/EX-410 : détail complet d'un item,
     * chaque valeur décorée de son type de colonne et, pour une clé
     * étrangère, de la résolution de l'item référencé.
     */
    public function show(string $connection, string $model, string $item)
    {
        $found = $this->items->find($connection, $model, $item);

        if ($found === null) {
            return response()->json(['message' => self::ITEM_NOT_FOUND], 404);
        }

        return response()->json(['data' => $found]);
    }

    /**
     * EX-412/EX-414/EX-415/EX-416 : schéma des colonnes du modèle (type, clé
     * étrangère, caractère technique), indépendant de l'existence d'un item —
     * utilisé par le formulaire front pour se construire (création, ou
     * modèle encore vide, EX-404).
     */
    public function columns(string $connection, string $model)
    {
        return response()->json(['data' => $this->items->columns($connection, $model)]);
    }

    /**
     * EX-412/EX-417 : création d'un item. Aucune validation propre au
     * plug-in (EX-417) : les valeurs sont écrites telles quelles, hors
     * colonnes techniques (EX-416) ; une violation de contrainte de colonne
     * est traduite en 422 par ItemRepository.
     */
    public function store(Request $request, string $connection, string $model)
    {
        try {
            $created = $this->items->create($connection, $model, $request->all());
        } catch (ItemValidationException $exception) {
            return response()->json(['message' => 'Validation échouée.', 'errors' => $exception->errors()], 422);
        }

        return response()->json(['data' => $created], 201);
    }

    /**
     * EX-413/EX-417 : modification d'un item existant, même principe que
     * store() pour la validation.
     */
    public function update(Request $request, string $connection, string $model, string $item)
    {
        try {
            $updated = $this->items->update($connection, $model, $item, $request->all());
        } catch (ItemValidationException $exception) {
            return response()->json(['message' => 'Validation échouée.', 'errors' => $exception->errors()], 422);
        }

        if ($updated === null) {
            return response()->json(['message' => self::ITEM_NOT_FOUND], 404);
        }

        return response()->json(['data' => $updated]);
    }

    /**
     * EX-418/EX-420 : suppression d'un item. La confirmation préalable
     * (EX-419) est de la responsabilité du front ; côté API, une contrainte
     * d'intégrité référentielle violée (item encore référencé par une clé
     * étrangère entrante) est traduite en 409 par ItemRepository, jamais en
     * suppression forcée.
     */
    public function destroy(string $connection, string $model, string $item)
    {
        try {
            $deleted = $this->items->delete($connection, $model, $item);
        } catch (ItemDeletionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if (! $deleted) {
            return response()->json(['message' => self::ITEM_NOT_FOUND], 404);
        }

        return response()->json(null, 204);
    }

    /**
     * EX-440 : restauration d'un item soft-deleted. 404 aussi bien pour un
     * item inconnu que pour un modèle n'utilisant pas SoftDeletes (EX-443,
     * l'action n'étant de toute façon pas proposée par le front dans ce cas)
     * — même principe que le reste du contrôleur, qui ne distingue pas ces
     * deux cas de « ressource introuvable ».
     */
    public function restore(string $connection, string $model, string $item)
    {
        $restored = $this->items->restore($connection, $model, $item);

        if ($restored === null) {
            return response()->json(['message' => self::ITEM_NOT_FOUND], 404);
        }

        return response()->json(['data' => $restored]);
    }

    /**
     * EX-441/EX-442 : suppression définitive (physique) d'un item déjà
     * soft-deleted, distincte de destroy() (EX-418). La confirmation
     * supplémentaire (EX-442) est de la responsabilité du front. Même
     * traduction 409 que destroy() si une contrainte de clé étrangère
     * entrante bloque cette suppression physique.
     */
    public function forceDestroy(string $connection, string $model, string $item)
    {
        try {
            $deleted = $this->items->forceDelete($connection, $model, $item);
        } catch (ItemDeletionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if (! $deleted) {
            return response()->json(['message' => self::ITEM_NOT_FOUND], 404);
        }

        return response()->json(null, 204);
    }
}
