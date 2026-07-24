<?php

namespace Quatrebarbes\Modelbase\Http\Controllers;

use Quatrebarbes\Modelbase\Support\ModelRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ModelController extends Controller
{
    public function __construct(private ModelRepository $models)
    {
    }

    /**
     * EX-301/EX-302/EX-304 : liste les modèles Eloquent déclarés pour la
     * connexion, filtrables par nom ou par nom de table via le paramètre de
     * requête `search`.
     */
    public function index(Request $request, string $connection)
    {
        return response()->json([
            'data' => $this->models->forConnection($connection, $request->query('search')),
        ]);
    }
}
