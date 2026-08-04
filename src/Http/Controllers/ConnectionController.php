<?php

namespace Quatrebarbes\Modelbase\Http\Controllers;

use Quatrebarbes\Modelbase\Support\ConnectionRepository;
use Illuminate\Routing\Controller;

class ConnectionController extends Controller
{
    public function __construct(private ConnectionRepository $connections)
    {
    }

    /**
     * EX-201/EX-203/EX-209 : liste les connexions configurées, sans statut ni
     * comptage de modèles (résolus à part par `status()`).
     */
    public function index()
    {
        return response()->json(['data' => $this->connections->all()]);
    }

    /**
     * EX-202/EX-205/EX-208/EX-210 : statut et comptage de modèles d'une seule
     * connexion. Volontairement hors du middleware `EnsureConnectionIsNavigable`
     * (EX-206) : cet endpoint sert justement à déterminer si la connexion est
     * navigable, l'y soumettre serait circulaire.
     */
    public function status(string $connection)
    {
        $status = $this->connections->status($connection);

        if ($status === null) {
            return response()->json(['message' => 'Connexion introuvable.'], 404);
        }

        return response()->json($status);
    }
}
