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
     * EX-201/EX-202/EX-203 : liste les connexions configurées.
     */
    public function index()
    {
        return response()->json(['data' => $this->connections->all()]);
    }
}
