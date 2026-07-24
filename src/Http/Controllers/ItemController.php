<?php

namespace Quatrebarbes\Modelbase\Http\Controllers;

use Quatrebarbes\Modelbase\Support\ItemRepository;
use Quatrebarbes\Modelbase\Support\ItemValidationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ItemController extends Controller
{
    public function __construct(private ItemRepository $items)
    {
    }

    /**
     * EX-401/EX-403/EX-404 : liste paginée des items d'un modèle ; un modèle
     * sans item renvoie un `data` vide (pas une erreur).
     */
    public function index(Request $request, string $connection, string $model)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 15);

        return response()->json(
            $this->items->paginate($connection, $model, $page, max(1, $perPage))
        );
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
            return response()->json(['message' => 'Item introuvable.'], 404);
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
            return response()->json(['message' => 'Item introuvable.'], 404);
        }

        return response()->json(['data' => $updated]);
    }
}
