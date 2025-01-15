<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;

class CardapioController extends Controller
{
    public function index(){
        // Ordena as categorias: primeiro as que começam com 'P', depois as demais em ordem alfabética
        $categorias = Categoria::orderByRaw("
            CASE 
                WHEN categoria_nome LIKE 'Pi%' THEN 0 
                ELSE 1 
            END, categoria_nome
        ")->with('produtos')->get();
        return view('cardapio', ['categorias' => $categorias]);
    }
}
