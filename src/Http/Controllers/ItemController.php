<?php

namespace Quatrebarbes\Modelbase\Http\Controllers;

use Quatrebarbes\Modelbase\Support\ItemRepository;
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
}
