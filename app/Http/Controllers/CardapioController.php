<?php

namespace App\Http\Controllers;

use App\Services\CardapioService;
use Illuminate\View\View;

class CardapioController extends Controller
{
    public function index(CardapioService $cardapio): View
    {
        return view('cardapio', $cardapio->dados() + ['mesaCliente' => null]);
    }
}
