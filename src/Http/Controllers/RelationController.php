<?php

namespace Quatrebarbes\Modelbase\Http\Controllers;

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
     * d'une relation donnée de l'item {item} du modèle {model}.
     */
    public function items(Request $request, string $connection, string $model, string $item, string $relation)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 15);

        try {
            $found = $this->relations->paginateRelated($connection, $model, $item, $relation, $page, max(1, $perPage));
        } catch (RelationUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if ($found === null) {
            return response()->json(['message' => 'Relation introuvable.'], 404);
        }

        return response()->json($found);
    }
}
