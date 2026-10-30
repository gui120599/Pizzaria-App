<?php

namespace App\Support;

/**
 * Cor primária dos painéis (garçom e admin): a brasa do "Forno aceso"
 * (resources/css/marca/forno-aceso.css). O tom 600 é a brasa (#C2410C) e o
 * 400 é a brasa-viva (#E8673A), usada como texto/ícone no modo escuro. O 500
 * é o fundo do botão primário no modo escuro. Contraste do branco: 600 = 5,2:1;
 * 500 = 4,8:1. Texto e ícone no modo escuro: 400 = 5,8:1 sobre gray-900.
 */
class PaletaMarca
{
    /** @return array<int, string> */
    public static function brasa(): array
    {
        return [
            50 => '#FFF7ED',
            100 => '#FFEDD5',
            200 => '#FED7AA',
            300 => '#FDBA74',
            400 => '#E8673A',
            500 => '#CC450E',
            600 => '#C2410C',
            700 => '#9A3412',
            800 => '#7C2D12',
            900 => '#6A2812',
            950 => '#431407',
        ];
    }
}
