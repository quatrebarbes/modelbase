<?php

namespace Quatrebarbes\Modelbase\Http\Controllers;

use Quatrebarbes\Modelbase\Support\ItemFilterException;
use Quatrebarbes\Modelbase\Support\RelationRepository;
use Quatrebarbes\Modelbase\Support\RelationUnavailableException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RelationController extends Controller
{
    public function __construct(private RelationRepository $relations)
    {
    }

    /**
     * EX-306/EX-307/EX-308/EX-309/EX-426 : relations Eloquent déclarées par
     * le modèle hôte (nom, type, multiplicité, modèle/connexion cible,
     * navigabilité) — source unique consommée à la fois par le diagramme de
     * relations (module 3, EX-310) et par les tableaux d'objets liés de la
     * fiche détail d'un item (module 4).
     */
    public function index(string $connection, string $model)
    {
        return response()->json(['data' => $this->relations->forModel($connection, $model)]);
    }

    /**
     * EX-427/EX-428/EX-429/EX-430/EX-431 : listing paginé des objets liés
     * d'une relation donnée de l'item {item} du modèle {model}. EX-470/
     * EX-472 : `filter[colonne]=valeur` (répétable) et `sort=colonne,-colonne2`
     * (mêmes noms de query params qu'ItemController::index(), EX-436),
     * restreints aux colonnes exposées par le modèle lié — une colonne
     * inconnue, non exposée, ou de la table pivot est rejetée en 422.
     */
    public function items(Request $request, string $connection, string $model, string $item, string $relation)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 15);
        $filters = (array) $request->query('filter', []);
        $sort = $request->query('sort');

        try {
            $found = $this->relations->paginateRelated($connection, $model, $item, $relation, $page, max(1, $perPage), $filters, $sort);
        } catch (RelationUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (ItemFilterException $exception) {
            return response()->json(['message' => 'Filtre ou tri invalide.', 'errors' => $exception->errors()], 422);
        }

        if ($found === null) {
            return response()->json(['message' => 'Relation introuvable.'], 404);
        }

        return response()->json($found);
    }
}
