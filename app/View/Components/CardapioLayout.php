<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Layout do cardápio público (delivery e mesa por QR): identidade "Forno
 * aceso", metadados de compartilhamento e CSS próprio. O guest continua
 * servindo login e telas de erro.
 */
class CardapioLayout extends Component
{
    public function __construct(
        public string $titulo = 'Empório da Pizza · Cardápio',
        public string $descricao = 'Peça pizzas, pastéis, panelinhas, sanduíches e bebidas do Empório da Pizza. Entrega ou retirada na loja.',
    ) {}

    public function render(): View
    {
        return view('layouts.cardapio');
    }
}
